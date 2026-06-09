<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * ForbiddenException is thrown for a 403 / forbidden response — the token is
 * valid but lacks the scope required for the operation.
 */
final class ForbiddenException extends ApiException {}
