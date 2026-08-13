<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SampleEndpointTest extends WebTestCase
{
    public function testItServesTheExampleForDownload(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/sample');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('sample.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    /**
     * What is served must be byte-identical to what the tests generate from,
     * so the example cannot quietly diverge from what is verified.
     */
    public function testItServesTheSameFileTheTestsUse(): void
    {
        $client = static::createClient();
        ob_start();
        $client->request('GET', '/api/sample');
        $client->getResponse()->sendContent();
        $served = (string) ob_get_clean();

        self::assertSame((string) file_get_contents(__DIR__.'/../../fixtures/sample.csv'), $served);
    }

    public function testTheServedFileCanBeFedStraightBackIn(): void
    {
        $client = static::createClient();
        ob_start();
        $client->request('GET', '/api/sample');
        $client->getResponse()->sendContent();
        $served = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'csv');
        self::assertIsString($path);
        file_put_contents($path, $served);

        $client->request(
            'POST',
            '/api/simulate',
            ['seed' => '42'],
            ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'sample.csv', 'text/csv', null, true)],
        );

        self::assertResponseIsSuccessful();
    }
}
