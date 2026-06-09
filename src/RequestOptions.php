<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

/**
 * RequestOptions customizes a single request. Today it carries an actor id that
 * overrides Config::$actorId for one call (sent as X-Actor-ID to attribute the
 * mutation in the audit log).
 */
final class RequestOptions
{
    public function __construct(
        public readonly ?string $actorId = null,
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
