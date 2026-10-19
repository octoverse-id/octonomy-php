<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use Octoverse\Octonomy\Client;
use PHPUnit\Framework\TestCase;

/**
 * IntegrationTestCase builds a Client from the credentials that
 * scripts/octonomy-harness.sh writes, or skips.
 *
 * The gate is OCTONOMY_TEST_BASE_URL, the same variable the octonomy-go suites
 * key on: with no harness running it is empty and every integration test skips,
 * because an absent container is a developer's normal case. Run
 * `make harness-up` then `make test-integration` to exercise them.
 *
 * Two states are failures rather than skips, because both would otherwise report
 * a vacuous pass:
 *
 *   - OCTONOMY_TEST_BASE_URL set but the token or tenant empty. That is a broken
 *     harness, not an absent one.
 *   - OCTONOMY_INTEGRATION_REQUIRED=1 with no base URL. CI sets it: there the
 *     harness was supposed to boot, so a skip would hide the failure.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Read one harness variable, treating unset and empty alike.
     */
    protected static function harnessEnv(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : $value;
    }

    /**
     * Build a Client authenticated as the harness's wildcard service token, or
     * skip the test when no harness is running.
     */
    protected function harnessClient(): Client
    {
        $baseUrl = self::harnessEnv('OCTONOMY_TEST_BASE_URL');
        if ($baseUrl === '') {
            if (self::harnessEnv('OCTONOMY_INTEGRATION_REQUIRED') === '1') {
                self::fail(
                    'OCTONOMY_INTEGRATION_REQUIRED=1 but OCTONOMY_TEST_BASE_URL is empty: the harness did not '
                    . 'export its credentials, so this test would have skipped and reported a vacuous pass',
                );
            }
            self::markTestSkipped(
                'OCTONOMY_TEST_BASE_URL is empty; run `make harness-up` and then `make test-integration`',
            );
        }

        $token = self::harnessEnv('OCTONOMY_TEST_TOKEN');
        $tenantId = self::harnessEnv('OCTONOMY_TEST_TENANT_ID');
        if ($token === '' || $tenantId === '') {
            self::fail(
                'OCTONOMY_TEST_BASE_URL is set but OCTONOMY_TEST_TOKEN/OCTONOMY_TEST_TENANT_ID are not: '
                . 'the harness env is incomplete',
            );
        }

        return new Client([
            'base_url' => $baseUrl,
            'token' => $token,
            'tenant_id' => $tenantId,
            'actor_id' => 'octonomy-php-integration',
        ]);
    }
}
