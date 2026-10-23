<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * FeatureDisabledException is thrown when the Octonomy deployment has a feature
 * the request needs switched off. The token is not at fault, so this is not a
 * ForbiddenException: the same call succeeds once an operator turns the
 * feature on, and requests outside the namespace axis keep working meanwhile.
 *
 * - namespaced_writes_disabled (403): OCTONOMY_NAMESPACE_WRITE_ENABLED is off,
 *   which is the server's default, so a namespaced create, update or delete is
 *   refused. Reads in the namespace and global writes still work.
 * - namespace_api_disabled (503): OCTONOMY_NAMESPACE_V2_API_ENABLED is off, the
 *   first step of the server's namespace rollback, so every namespaced v2
 *   request is refused. Requests without a namespace still work.
 *
 * $errorCode says which one; retrying does not help until the flag changes.
 */
final class FeatureDisabledException extends ApiException {}
