<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\Internal\RequestId;

/**
 * RequestOptions customizes a single request. $actorId overrides
 * Config::$actorId for one call (sent as X-Actor-ID to attribute the mutation in
 * the audit log). $namespace places the call in a merchant or sub-tenant
 * namespace (the X-Namespace-* header pair); it exists only here, never on
 * Config, and needs api_version v2. $requestId is sent as X-Request-ID, the
 * caller's own id for the call (withRequestId()).
 *
 * There is no per-request api_version: a Client calls the one surface it was
 * constructed with.
 *
 * ```php
 * $options = new RequestOptions(actorId: 'svc-catalog', requestId: $traceId);
 * $client->tags()->update($id, new TagUpdate(name: 'Autumn'), options: $options);
 * ```
 */
final class RequestOptions
{
    /**
     * @param string|null $requestId sent as X-Request-ID, or null to send none and let the server mint
     *                               one; see withRequestId()
     *
     * @throws \InvalidArgumentException when $actorId holds a control character, which the X-Actor-ID
     *                                   header cannot carry, or $requestId is blank, holds a byte outside
     *                                   printable ASCII, or begins or ends with a space
     */
    public function __construct(
        public readonly ?string $actorId = null,
        public readonly ?NamespaceScope $namespace = null,
        public readonly ?string $requestId = null,
    ) {
        // Refused here, never quoted: Guzzle would refuse it on sending with an
        // error that quotes it, and the actor id is never shown (Redaction).
        if ($actorId !== null && !Redaction::isHeaderSafe($actorId)) {
            throw Redaction::scrub(new \InvalidArgumentException(ErrorMessage::unsendable('actorId', 'X-Actor-ID')));
        }
        if ($requestId !== null) {
            RequestId::check($requestId);
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

    /**
     * Convenience constructor for sending the caller's own id for a single
     * call as X-Request-ID. To send an actor or a namespace with it, use the
     * constructor's named arguments.
     *
     * The server reads the header when it is present and mints req_<uuid>
     * when it is not, then records whichever id it holds in the audit row of
     * a mutation, the outbox event (and the webhook delivered from it), its
     * structured request log, and the error envelope of a failed call
     * (ApiException::$requestId). Supplying the id is what ties those to the
     * caller's own log line for the call.
     *
     * The SDK never mints one: without this option no header is sent, and the
     * server's own minting stays in place. There is no Config field for one
     * either, since a request id names a single request, and a client-wide
     * default would stamp every call with the same value. Generate one per
     * call, such as a UUID or the trace id you already log, and log it on your
     * side: a successful call returns only the model, not the response's
     * headers.
     *
     * The id must be non-blank printable ASCII with no space at either end, or
     * the server would record a different one (Internal\RequestId). Keep it to
     * 100 characters: the server stores it in a column that wide, and a longer
     * one fails a mutation with a bare 500 (an ApiException without the error
     * envelope) and rolls it back. That limit is the server's, and the SDK does
     * not check it.
     *
     * @throws \InvalidArgumentException when $requestId is blank, holds a byte outside printable ASCII, or
     *                                   begins or ends with a space
     */
    public static function withRequestId(string $requestId): self
    {
        return new self(requestId: $requestId);
    }
}
