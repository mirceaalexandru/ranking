<?php

declare(strict_types=1);

namespace App\Controller;

use App\Csv\BudgetHistoryReader;
use App\Csv\CsvError;
use App\Domain\SeededRandom;
use App\Domain\Simulator;
use App\Http\ReportPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The endpoint that answers the exercise: a budget history in, generated costs
 * and the daily report out.
 *
 * Nothing is stored. The same file and the same seed produce the same result,
 * which is what makes a run reproducible without a database, and what lets two
 * people use the application at once without interfering.
 */
final readonly class SimulateController
{
    private const int MAX_UPLOAD_BYTES = 1_048_576;

    public function __construct(
        private BudgetHistoryReader $reader,
        private Simulator $simulator,
        private ReportPresenter $presenter,
    ) {
    }

    #[Route('/api/simulate', name: 'api_simulate', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return $this->problem('Upload a CSV file in the "file" field.', Response::HTTP_BAD_REQUEST);
        }

        if (!$file->isValid()) {
            return $this->problem($file->getErrorMessage(), Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->problem(
                sprintf('The file is larger than %d bytes.', self::MAX_UPLOAD_BYTES),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $seed = $this->seedFrom($request);
        if (null === $seed) {
            return $this->problem('The seed must be a non-negative integer.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->reader->read((string) file_get_contents($file->getPathname()));

        if (!$result->isSuccessful()) {
            return new JsonResponse(
                ['errors' => array_map(static fn (CsvError $error): array => $error->toArray(), $result->errors)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $history = $result->history();
        $period = $history->coveringPeriod();
        $report = $this->simulator->run($history, $period, new SeededRandom($seed));

        return new JsonResponse($this->presenter->present($report, $period, $seed));
    }

    /**
     * An absent seed is generated and returned, so any run can be pinned and
     * repeated.
     */
    private function seedFrom(Request $request): ?int
    {
        $raw = $request->request->get('seed');

        if (null === $raw || '' === $raw) {
            return random_int(1, PHP_INT_MAX);
        }

        if (!is_string($raw) || 1 !== preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function problem(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [['line' => null, 'column' => null, 'message' => $message]]], $status);
    }
}
