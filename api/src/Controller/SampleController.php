<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the example history for download.
 *
 * The same file the invariant suite generates from, so the example a reader
 * downloads and the one the tests exercise cannot drift apart.
 */
final readonly class SampleController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/sample.csv')]
        private string $samplePath,
    ) {
    }

    #[Route('/api/sample', name: 'api_sample', methods: ['GET'])]
    public function __invoke(): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->samplePath);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'sample.csv');

        return $response;
    }
}
