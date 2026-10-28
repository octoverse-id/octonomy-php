<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * RequestIdTest asks a real server what it records under X-Request-ID (#19).
 * The unit suite proves the header leaves the SDK; only the server can say it
 * keeps the caller's id rather than its own, and still mints one when there is
 * none.
 *
 * The id is read back from the error envelope of a read that misses, since a
 * successful call returns only its model and a read writes no audit row. The
 * same id goes to the audit row and the event of a mutation, which this suite
 * cannot make: every test only reads.
 */
final class RequestIdTest extends IntegrationTestCase
{
    /** A tag id no corpus row has. */
    private const MISSING = '00000000-0000-0000-0000-000000000000';

    #[DataProvider('versions')]
    public function testTheServerRecordsTheCallersRequestId(string $apiVersion): void
    {
        $id = sprintf('octonomy-php-%s-%s', $apiVersion, bin2hex(random_bytes(8)));

        try {
            $this->harnessClient($apiVersion)->tags()->get(self::MISSING, options: RequestOptions::withRequestId($id));
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame($id, $e->requestId);
            self::assertStringContainsString("Request ID: {$id}.", $e->getMessage());
        }
    }

    #[DataProvider('versions')]
    public function testWithoutOneTheServerMintsItsOwn(string $apiVersion): void
    {
        try {
            $this->harnessClient($apiVersion)->tags()->get(self::MISSING);
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertMatchesRegularExpression('/^req_[0-9a-f]{32}$/', (string) $e->requestId);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versions(): iterable
    {
        yield 'api_version v1' => [ApiVersion::V1];
        yield 'api_version v2' => [ApiVersion::V2];
    }
}
