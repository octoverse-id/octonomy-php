<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * UnsupportedApiVersionException is thrown for a 400 namespace_not_supported
 * response: a request reached /api/v1 carrying the X-Namespace-* headers, and
 * v1 has no namespace axis. It is a version mismatch, not invalid input, so it
 * is not a ValidationException.
 *
 * A v1 Client refuses a namespace with a \LogicException before sending, so
 * this arrives only when something other than RequestOptions added the
 * headers, such as middleware on an injected HTTP client. Build the Client with
 * api_version v2, or remove the headers to stay in the global partition.
 */
final class UnsupportedApiVersionException extends ApiException {}
