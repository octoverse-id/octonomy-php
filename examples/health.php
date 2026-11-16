<?php

declare(strict_types=1);

/**
 * Health: is the server up, and can it serve traffic?
 *
 * Asks the liveness and readiness probes, which need no token and no tenant,
 * so a HealthClient takes only the base URL. It exits 0 when the server is
 * ready and 1 when it is not, so a deploy script or a container check can run
 * it as it is.
 *
 *   vendor/bin/octonomy-dev-server run examples/health.php
 *
 * Against your own Octonomy, set OCTONOMY_BASE_URL alone.
 */

use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\HealthClient;

require __DIR__ . '/support/bootstrap.php';

$env = exampleEnv('health', ['OCTONOMY_BASE_URL']);

// A probe should answer fast or count as down, so give it a short timeout. A
// Client reaches the same probes through $client->health(), sending neither its
// token nor its tenant.
$probe = new HealthClient(['base_url' => $env['OCTONOMY_BASE_URL'], 'timeout' => 2]);

try {
    // Live: the process answers. Ready: it can reach its database too.
    $live = $probe->health()->live();
    printf("live:  %s\n", $live->status);

    // A server that answers but is not ready is not an exception: it returns
    // ok false, with status "unavailable".
    $ready = $probe->health()->ready();
    printf("ready: %s\n", $ready->status);
    exit($ready->ok ? 0 : 1);
} catch (TransportException $e) {
    // Nothing answered: refused, timed out, or no such host.
    exampleFail('health', 'not reachable: ' . $e->getMessage());
} catch (OctonomyException $e) {
    // Something answered in the server's place, such as a proxy's 502.
    exampleFail('health', 'Octonomy error: ' . $e->getMessage());
}
