<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

/**
 * RequestOptions customizes a single request. $actorId overrides
 * Config::$actorId for one call (sent as X-Actor-ID to attribute the mutation in
 * the audit log). $namespace places the call in a merchant or sub-tenant
 * namespace (the X-Namespace-* header pair); it exists only here, never on
 * Config, and needs api_version v2.
 *
 * There is no per-request api_version: a Client calls the one surface it was
 * constructed with.
 */
final class RequestOptions
{
    public function __construct(
        public readonly ?string $actorId = null,
        public readonly ?NamespaceScope $namespace = null,
    ) {}

    /**
     * Convenience constructor for the common case of attributing a single call
     * to a specific actor.
     */
    public static function withActor(string $actorId): self
    {
        return new self(actorId: $actorId);
    }
}
