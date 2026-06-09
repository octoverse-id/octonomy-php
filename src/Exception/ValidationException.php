<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * ValidationException is thrown for a 400-level validation failure
 * (validation_error, tenant_mismatch, application_mismatch, inactive_tag).
 * Inspect $details for field-level information and $errorCode for the exact code.
 */
final class ValidationException extends ApiException {}
