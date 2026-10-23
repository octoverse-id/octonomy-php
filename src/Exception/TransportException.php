<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\RequestContext;

/**
 * TransportException wraps a network-level failure (connection error, timeout,
 * or an undecodable response body) — i.e. the SDK never received a usable HTTP
 * response from Octonomy. $context describes the request that failed.
 *
 * When the HTTP client failed, its error is quoted in the message (with any
 * value the request carried replaced) and not chained: the client's exception
 * holds the request it could not send, Authorization header and body included,
 * so getPrevious() is null. When a 2xx body was not JSON, getPrevious() is the
 * \JsonException.
 */
final class TransportException extends OctonomyException
{
    /**
     * @param RequestContext $context the request that failed
     */
    public function __construct(
        string $message,
        public readonly RequestContext $context,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
