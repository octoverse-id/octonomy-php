<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use Octoverse\Octonomy\Tests\Fixture\VendoredSpec;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Proves each vendored spec is the contract the pinned server serves (#21).
 *
 * The harness runs the server image pinned by digest, and the server renders
 * its schema at /api/{version}/schema/ with the same generator that writes the
 * files committed at its release tag (its own CI fails when they differ). So
 * the body of each vendored spec must be exactly what that endpoint returns.
 * Unlike the hash in the spec's header, this evidence is not stored beside the
 * file it vouches for. Read-only, like the rest of the suite.
 */
final class VendoredSpecMatchesServerTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function apiVersions(): iterable
    {
        yield 'v1' => ['v1'];
        yield 'v2' => ['v2'];
    }

    #[DataProvider('apiVersions')]
    public function testTheSpecIsWhatThePinnedServerServes(string $apiVersion): void
    {
        $url = rtrim(self::harnessBaseUrl(), '/') . "/api/{$apiVersion}/schema/";
        $served = @file_get_contents($url, false, stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 30, 'header' => "Accept: application/vnd.oai.openapi\r\n"],
        ]));
        self::assertIsString($served, "no response from {$url}");
        self::assertMatchesRegularExpression('#^HTTP/\S+ 200\b#', $http_response_header[0] ?? '', "{$url} did not answer 200");

        $spec = VendoredSpec::load($apiVersion);
        self::assertSame(hash('sha256', $served), hash('sha256', $spec->body), "{$spec->file} is not what the pinned server serves at {$url}: re-vendor it");
    }
}
