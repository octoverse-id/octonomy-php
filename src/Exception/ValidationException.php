<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * ValidationException is thrown for a 400-level validation failure
 * (validation_error, tenant_mismatch, application_mismatch, inactive_tag,
 * namespace_invalid). Inspect $details for field-level information and
 * $errorCode for the exact code.
 *
 * Two other 400 codes have classes of their own, because the fix is not to
 * correct the input: namespace_not_supported (UnsupportedApiVersionException)
 * and ambiguous_resolution (AmbiguousResolutionException).
 */
final class ValidationException extends ApiException {}
