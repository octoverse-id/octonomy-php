<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * ForbiddenException is thrown for a 403 / forbidden response — the token is
 * valid but lacks the scope or grant required for the operation. A 403
 * namespaced_writes_disabled is a deployment flag rather than a missing grant,
 * and is a FeatureDisabledException instead.
 */
final class ForbiddenException extends ApiException {}
