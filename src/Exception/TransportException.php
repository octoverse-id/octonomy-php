<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\RequestContext;

/**
 * TransportException wraps a network-level failure (connection error, timeout,
 * or an undecodable response body) — i.e. the SDK never received a usable HTTP
 * response from Octonomy. Inspect getPrevious() for the underlying cause, and
 * $context for the request that failed.
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
