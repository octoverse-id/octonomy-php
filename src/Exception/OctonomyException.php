<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\Internal\Redaction;

/**
 * OctonomyException is the base type for every failure the SDK reports. Catch
 * it to handle any SDK failure uniformly.
 *
 * A mistake in the calling code that the SDK detects before sending anything is
 * not such a failure, and is thrown as PHP's own \LogicException family
 * instead: a NamespaceScope with a blank type or id, a per-request header value
 * holding a control character, or a body that cannot be encoded as JSON
 * (\InvalidArgumentException), or a v2-only feature on a v1 client
 * (\LogicException). Fix the call; there is nothing to retry. These are
 * scrubbed of the SDK's frame arguments too. A configured value a header
 * cannot carry is a ConfigurationException.
 *
 * No exception the SDK throws carries the token, the Authorization header, the
 * request body or X-Actor-ID: not in its message, its RequestContext, or the
 * arguments of the SDK's frames in its trace, which are removed when it is
 * constructed. Each message says what happened and the values involved, why
 * where the SDK knows (and that it does not, where it does not), and how to fix
 * it.
 */
class OctonomyException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        Redaction::scrub($this);
    }
}
