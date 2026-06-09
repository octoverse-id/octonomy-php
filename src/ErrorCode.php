<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

/**
 * Error codes returned by the Octonomy API in the error envelope
 * ({"error": {"code": ...}}). They mirror octonomy/core/errors.py on the server.
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

    private function __construct() {}
}
