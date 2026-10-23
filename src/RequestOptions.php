<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Redaction;

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
    /**
     * @throws \InvalidArgumentException when $actorId holds a control character, which the X-Actor-ID
     *                                   header cannot carry
     */
    public function __construct(
        public readonly ?string $actorId = null,
        public readonly ?NamespaceScope $namespace = null,
    ) {
        // Refused here, never quoted: Guzzle would refuse it on sending with an
        // error that quotes it, and the actor id is never shown (Redaction).
        if ($actorId !== null && !Redaction::isHeaderSafe($actorId)) {
            throw Redaction::scrub(new \InvalidArgumentException(ErrorMessage::unsendable('actorId', 'X-Actor-ID')));
        }
    }

    /**
     * Convenience constructor for the common case of attributing a single call
     * to a specific actor.
     */
    public static function withActor(string $actorId): self
    {
        return new self(actorId: $actorId);
    }
}
