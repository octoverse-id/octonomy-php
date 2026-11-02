<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Http\HealthTransport;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\Resource\HealthService;

/**
 * HealthClient reaches the server's health probes, and nothing else, without
 * credentials (#24). Construct it with an options array (or a HealthConfig):
 * only base_url is required, and a Client's options work too: their token,
 * tenant_id and actor_id are not sent, and no exception quotes them.
 *
 * It exists because a Client requires a token and a tenant id, and the probes
 * need neither: a readiness check in a deploy script or an orchestrator has
 * none to give. A caller who holds a Client already reaches the same probes
 * through Client::health(), which sends the same request.
 *
 * ```php
 * $probe = new HealthClient(['base_url' => 'https://octonomy.example.com', 'timeout' => 2]);
 * $live = $probe->health()->live();
 * ```
 *
 * A short timeout is usually right for a probe: one that hangs for the
 * 30-second default has already failed for whoever is polling it.
 */
final class HealthClient
{
    private readonly HealthService $health;

    /**
     * @param array<string, mixed>|HealthConfig $config an options array (see HealthConfig::fromArray) or a
     *                                                  HealthConfig
     *
     * @throws Exception\ConfigurationException when the configuration is invalid
     */
    public function __construct(array|HealthConfig $config)
    {
        $resolved = $config instanceof HealthConfig ? $config : HealthConfig::fromArray($config);
        // A Client's options may hold its token and actor id. A probe sends
        // neither, but no message about one may quote them (Redaction).
        $token = is_array($config) ? $config['token'] ?? null : null;
        $actorId = is_array($config) ? $config['actor_id'] ?? null : null;
        $redaction = Redaction::of(is_string($token) ? $token : '', is_string($actorId) ? $actorId : null, null);
        $this->health = new HealthService(new HealthTransport($resolved, Client::VERSION, $redaction));
    }

    /**
     * Health probes the server's liveness and readiness.
     */
    public function health(): HealthService
    {
        return $this->health;
    }
}
