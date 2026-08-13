<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Generation\Algorithm;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SimulateEndpointTest extends WebTestCase
{
    private function upload(
        KernelBrowser $client,
        string $csv,
        ?string $seed = '1234',
        ?string $algorithm = null,
    ): void {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        self::assertIsString($path);
        file_put_contents($path, $csv);

        $parameters = [];
        if (null !== $seed) {
            $parameters['seed'] = $seed;
        }
        if (null !== $algorithm) {
            $parameters['algorithm'] = $algorithm;
        }

        $client->request(
            'POST',
            '/api/simulate',
            $parameters,
            ['file' => new UploadedFile($path, 'history.csv', 'text/csv', null, true)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        $body = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $body[$key] = $value;
        }

        return $body;
    }

    private function sampleCsv(): string
    {
        return (string) file_get_contents(__DIR__.'/../../fixtures/sample.csv');
    }

    public function testItSimulatesAnUploadedHistory(): void
    {
        $client = static::createClient();
        $this->upload($client, $this->sampleCsv());

        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        self::assertSame(1234, $body['seed']);
        self::assertSame(
            ['start' => '2019-01-01', 'end' => '2019-03-31', 'days' => 90],
            $body['period'],
            'the period runs from the first change to the end of the last change\'s month',
        );

        self::assertIsArray($body['days']);
        self::assertCount(90, $body['days'], 'every calendar day has a row');
        self::assertIsArray($body['months']);
        self::assertCount(3, $body['months']);
    }

    /**
     * JSON numbers are doubles. Handing money over as one would give back
     * exactly the floating-point problem integer cents exists to avoid.
     */
    public function testMoneyIsSerialisedAsStrings(): void
    {
        $client = static::createClient();
        $this->upload($client, $this->sampleCsv());

        $body = $this->decode($client);
        self::assertIsArray($body['days']);
        self::assertIsArray($body['months']);

        $firstDay = $body['days'][0];
        self::assertIsArray($firstDay);
        self::assertIsString($firstDay['costs']);
        self::assertIsString($firstDay['budgetSet']);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $firstDay['costs']);

        $firstMonth = $body['months'][0];
        self::assertIsArray($firstMonth);
        foreach (['allowance', 'spent', 'remaining'] as $field) {
            self::assertIsString($firstMonth[$field], "{$field} should be a string");
        }
    }

    public function testTheSameSeedProducesTheSameResponse(): void
    {
        $client = static::createClient();

        $this->upload($client, $this->sampleCsv(), '999');
        $first = (string) $client->getResponse()->getContent();

        $this->upload($client, $this->sampleCsv(), '999');
        $again = (string) $client->getResponse()->getContent();

        $this->upload($client, $this->sampleCsv(), '1000');
        $different = (string) $client->getResponse()->getContent();

        self::assertSame($first, $again);
        self::assertNotSame($first, $different);
    }

    public function testAnAbsentSeedIsGeneratedAndReturnedSoTheRunCanBePinned(): void
    {
        $client = static::createClient();
        $this->upload($client, $this->sampleCsv(), null);

        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        self::assertIsInt($body['seed']);
        self::assertGreaterThan(0, $body['seed']);

        // Short enough to read off the screen and type back in — and well
        // inside JavaScript's safe integer range, so the value shown is the
        // value that produced the run rather than a rounded approximation.
        self::assertLessThanOrEqual(999_999, $body['seed']);

        // Replaying with the returned seed reproduces the run exactly.
        $original = (string) $client->getResponse()->getContent();
        $this->upload($client, $this->sampleCsv(), (string) $body['seed']);

        self::assertSame($original, (string) $client->getResponse()->getContent());
    }

    public function testNothingLeaksBetweenRequests(): void
    {
        $client = static::createClient();

        $this->upload($client, "date,time,budget\n2019-05-01,10:00,4\n", '7');
        $may = $this->decode($client);

        $this->upload($client, $this->sampleCsv(), '7');
        $sample = $this->decode($client);

        self::assertIsArray($may['period']);
        self::assertIsArray($sample['period']);
        self::assertSame('2019-05-01', $may['period']['start']);
        self::assertSame('2019-01-01', $sample['period']['start']);
    }

    public function testItRunsPacedByDefaultAndReportsWhichAlgorithmWasUsed(): void
    {
        $client = static::createClient();
        $this->upload($client, $this->sampleCsv());

        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        self::assertIsArray($body['algorithm']);
        self::assertSame('paced', $body['algorithm']['value']);
    }

    public function testTheAlgorithmIsPartOfARunsIdentity(): void
    {
        $client = static::createClient();

        $this->upload($client, $this->sampleCsv(), '55', 'paced');
        $paced = (string) $client->getResponse()->getContent();

        $this->upload($client, $this->sampleCsv(), '55', 'greedy');
        $greedy = (string) $client->getResponse()->getContent();

        // Same file, same seed, different algorithm: a different run, not a
        // variation of the same one.
        self::assertNotSame($paced, $greedy);
    }

    public function testEveryAlgorithmTheDomainOffersIsAccepted(): void
    {
        $client = static::createClient();

        foreach (Algorithm::cases() as $algorithm) {
            $this->upload($client, $this->sampleCsv(), '1', $algorithm->value);

            self::assertResponseIsSuccessful("{$algorithm->value} should be accepted");
            $body = $this->decode($client);
            self::assertIsArray($body['algorithm']);
            self::assertSame($algorithm->value, $body['algorithm']['value']);
        }
    }

    public function testAnUnknownAlgorithmIsRejected(): void
    {
        $client = static::createClient();
        $this->upload($client, $this->sampleCsv(), '1', 'clairvoyant');

        self::assertResponseStatusCodeSame(400);
    }

    public function testAMalformedFileIsRejectedWithEveryProblemAndItsLine(): void
    {
        $client = static::createClient();
        $this->upload($client, "date,time,budget\n2019-01-01,10:00,7\nnonsense,10:00,2\n2019-01-03,99:00,1\n");

        self::assertResponseStatusCodeSame(422);
        $body = $this->decode($client);

        self::assertIsArray($body['errors']);
        self::assertCount(2, $body['errors']);

        $first = $body['errors'][0];
        self::assertIsArray($first);
        self::assertSame(3, $first['line']);
        self::assertSame('date', $first['column']);
    }

    public function testAMissingFileIsABadRequest(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/simulate');

        self::assertResponseStatusCodeSame(400);
    }

    public function testANonNumericSeedIsRejected(): void
    {
        $client = static::createClient();
        $this->upload($client, $this->sampleCsv(), 'not-a-number');

        self::assertResponseStatusCodeSame(400);
    }

    public function testASeedBeyondTheSafeRangeIsRejectedRatherThanSilentlyAltered(): void
    {
        $client = static::createClient();
        $this->upload($client, $this->sampleCsv(), '9223372036854775807');

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnEmptyFileIsRejected(): void
    {
        $client = static::createClient();
        $this->upload($client, '');

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheEndpointRejectsOtherMethods(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/simulate');

        self::assertResponseStatusCodeSame(405);
    }
}
