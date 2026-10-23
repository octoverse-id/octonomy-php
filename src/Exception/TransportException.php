<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\RequestContext;

/**
 * TransportException means the SDK got no response it could use: the HTTP
 * client threw (a connection error, a timeout, or middleware that throws,
 * whose exception may hold a response), or a 2xx body was not JSON. $context
 * describes the request that failed.
 *
 * When the HTTP client threw, its error is quoted in the message (with any
 * value the request carried replaced) and not chained: the client's exception
 * holds the request, Authorization header and body included. When a 2xx body
 * was not JSON, the decoder's error is quoted and not chained either: its
 * json_decode() frame holds the raw body. getPrevious() is always null.
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
