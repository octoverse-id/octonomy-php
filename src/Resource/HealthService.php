<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Http\HealthTransport;
use Octoverse\Octonomy\Model\HealthStatus;

/**
 * HealthService probes the server's liveness and readiness (#24). Reach it via
 * Client::health(), or via HealthClient::health() when there are no
 * credentials to hand. Both send the same request: GET at the server's root,
 * outside /api/{version}, with no token, no tenant and no namespace, since the
 * server serves the probes unauthenticated.
 *
 * ```php
 * $status = (new HealthClient(['base_url' => 'https://octonomy.example.com', 'timeout' => 2]))
 *     ->health()
 *     ->ready();
 * if (!$status->ok) {
 *     // answering, but not serving: back off and probe again
 * }
 * ```
 *
 * A server that answers "not ready" and one that does not answer are told
 * apart: ready() returns the first, with $ok false, and the second raises
 * TransportException. Anything else that answers in the server's place, such
 * as a proxy's 502, raises ApiException.
 *
 * The methods take no RequestOptions. A namespace, an actor id and an
 * include_global scope or attribute a call to the tenant-scoped API, and mean
 * nothing on a route with no tenant; accepting them only to ignore them would
 * be a silent no-op. The Go SDK resolved the same way.
 */
final class HealthService
{
    /**
     * Built by Client::health() and HealthClient::health(); reach the service
     * there rather than constructing it.
     */
    public function __construct(private readonly HealthTransport $transport) {}

    /**
     * Probe liveness (GET /health/live): whether the server's process is up
     * and serving HTTP. The server answers 200 {"status": "ok"} without
     * checking anything else, so this is the signal to restart on, not to
     * route traffic by; ready() is that one.
     *
     * @throws ApiException               when anything but the probe answers with a non-2xx status
     * @throws MalformedResponseException when a 2xx body is not a probe's answer
     * @throws TransportException         when no response arrives, or a 2xx body is not JSON
     */
    public function live(): HealthStatus
    {
        return $this->transport->probe('health/live', false);
    }

    /**
     * Probe readiness (GET /health/ready): whether the server can serve
     * traffic, which at server 3.2.1 means it can reach its database. A 503
     * {"status": "unavailable"} returns a HealthStatus with $ok false instead
     * of throwing, since a readiness probe exists to report exactly that.
     *
     * At server 3.2.1 the check opens a cursor on a database connection the
     * server keeps for up to 60 seconds, which needs no round trip, so the
     * server can report ready for up to a minute after losing its database.
     *
     * @throws ApiException               when anything but the probe answers with a non-2xx status, a
     *                                    503 without the probe's {"status": ...} body included
     * @throws MalformedResponseException when a 2xx body is not a probe's answer
     * @throws TransportException         when no response arrives, or a 2xx body is not JSON
     */
    public function ready(): HealthStatus
    {
        return $this->transport->probe('health/ready', true);
    }
}
