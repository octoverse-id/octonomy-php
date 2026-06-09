<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * AuthenticationException is thrown for a 401 / authentication_required response —
 * the service token is missing, invalid, or expired.
 */
final class AuthenticationException extends ApiException {}
