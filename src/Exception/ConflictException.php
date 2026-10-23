<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * ConflictException is thrown for a 409 / conflict response — for example a
 * duplicate slug or an idempotency clash — and for a 409 / scope_immutable
 * one: an update that would move a row to another application or namespace.
 * A row's scope is fixed at creation, so re-create it in the target scope
 * instead. $errorCode says which.
 */
final class ConflictException extends ApiException {}
