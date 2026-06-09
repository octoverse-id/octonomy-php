<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * ConflictException is thrown for a 409 / conflict response — for example a
 * duplicate slug or an idempotency clash.
 */
final class ConflictException extends ApiException {}
