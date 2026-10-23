<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

/**
 * Error codes returned by the Octonomy API in the error envelope
 * ({"error": {"code": ...}}). They mirror octonomy/core/errors.py on the server,
 * every code it declares as of server 3.2.1.
 */
final class ErrorCode
{
    public const VALIDATION = 'validation_error';
    public const AUTH_REQUIRED = 'authentication_required';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    public const TENANT_MISMATCH = 'tenant_mismatch';
    public const APPLICATION_MISMATCH = 'application_mismatch';
    public const INACTIVE_TAG = 'inactive_tag';

    /** A change to a row's application or namespace after creation (409). */
    public const SCOPE_IMMUTABLE = 'scope_immutable';

    // Namespace codes. The namespace axis exists on api_version v2 alone, so
    // namespace_not_supported is v1's answer to namespace headers, and the
    // others arise on v2.

    /** Namespace headers sent to /api/v1 (400). */
    public const NAMESPACE_NOT_SUPPORTED = 'namespace_not_supported';

    /** A structurally unusable X-Namespace-* pair on v2, such as the reserved type "global" (400). */
    public const NAMESPACE_INVALID = 'namespace_invalid';

    /** A namespaced write while the deployment's OCTONOMY_NAMESPACE_WRITE_ENABLED is off, its default (403). */
    public const NAMESPACED_WRITES_DISABLED = 'namespaced_writes_disabled';

    /** A namespaced v2 request while the deployment's OCTONOMY_NAMESPACE_V2_API_ENABLED is off (503). */
    public const NAMESPACE_API_DISABLED = 'namespace_api_disabled';

    /** More than one equally specific tag or alias matched a resolution (400). */
    public const AMBIGUOUS_RESOLUTION = 'ambiguous_resolution';

    private function __construct() {}
}
