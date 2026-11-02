<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\HealthClient;
use Octoverse\Octonomy\Model\HealthStatus;
use Octoverse\Octonomy\Resource\HealthService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * HealthTest probes a real server (#24). The unit suite proves what a probe
 * sends; only the server can say it answers one sent with no token and no
 * tenant, at its root rather than under /api. The not-ready 503 is not probed
 * here, since it needs the database stopped and this suite must leave the
 * harness as it found it; the fixture capture records it instead.
 */
final class HealthTest extends IntegrationTestCase
{
    /**
     * @param \Closure(HealthService): HealthStatus $probe
     */
    #[DataProvider('probes')]
    public function testAHealthClientWithNoCredentialsProbesTheServer(\Closure $probe): void
    {
        $status = $probe((new HealthClient(['base_url' => self::harnessBaseUrl(), 'timeout' => 10]))->health());

        self::assertTrue($status->ok);
        self::assertSame(HealthStatus::STATUS_OK, $status->status);
    }

    /**
     * @param \Closure(HealthService): HealthStatus $probe
     */
    #[DataProvider('probes')]
    public function testAClientProbesTheServerOnEitherVersion(\Closure $probe): void
    {
        foreach ([ApiVersion::V1, ApiVersion::V2] as $apiVersion) {
            $status = $probe($this->harnessClient($apiVersion)->health());

            self::assertTrue($status->ok, $apiVersion);
            self::assertSame(HealthStatus::STATUS_OK, $status->status, $apiVersion);
        }
    }

    /**
     * @return iterable<string, array{\Closure(HealthService): HealthStatus}>
     */
    public static function probes(): iterable
    {
        yield 'live()' => [static fn(HealthService $h): HealthStatus => $h->live()];
        yield 'ready()' => [static fn(HealthService $h): HealthStatus => $h->ready()];
    }
}
