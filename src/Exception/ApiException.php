<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\ErrorCode;
use Octoverse\Octonomy\Internal\Json;

/**
 * ApiException represents a non-2xx response from Octonomy. It carries the HTTP
 * status alongside the server's error envelope ({"error": {...}}) so callers can
 * inspect the machine code, details, and request id.
 *
 * Prefer catching the specific subclasses (NotFoundException, ConflictException,
 * ValidationException, AuthenticationException, ForbiddenException) where you
 * need to branch; catch ApiException for the general case.
 */
class ApiException extends OctonomyException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $errorCode,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Build the most specific ApiException subclass for a non-2xx response,
     * decoding the standard Octonomy error envelope and falling back to the HTTP
     * status when the envelope is absent.
     *
     * @param array<string, mixed> $body
     */
    public static function fromResponse(int $status, array $body): self
    {
        $error = Json::object($body, 'error');

        $code = Json::nullableString($error, 'code') ?? self::codeFromStatus($status);
        $message = Json::nullableString($error, 'message') ?? self::messageFromStatus($status);
        $details = Json::object($error, 'details');
        $requestId = Json::nullableString($error, 'request_id');

        $class = self::classFor($code, $status);

        return new $class($message, $status, $code, $details, $requestId);
    }

    /**
     * @return class-string<self>
     */
    private static function classFor(string $code, int $status): string
    {
        return match ($code) {
            ErrorCode::NOT_FOUND => NotFoundException::class,
            ErrorCode::CONFLICT => ConflictException::class,
            ErrorCode::VALIDATION, ErrorCode::TENANT_MISMATCH, ErrorCode::APPLICATION_MISMATCH, ErrorCode::INACTIVE_TAG => ValidationException::class,
            ErrorCode::AUTH_REQUIRED => AuthenticationException::class,
            ErrorCode::FORBIDDEN => ForbiddenException::class,
            default => match ($status) {
                404 => NotFoundException::class,
                409 => ConflictException::class,
                401 => AuthenticationException::class,
                403 => ForbiddenException::class,
                400 => ValidationException::class,
                default => self::class,
            },
        };
    }

    private static function codeFromStatus(int $status): string
    {
        return match ($status) {
            400 => ErrorCode::VALIDATION,
            401 => ErrorCode::AUTH_REQUIRED,
            403 => ErrorCode::FORBIDDEN,
            404 => ErrorCode::NOT_FOUND,
            409 => ErrorCode::CONFLICT,
            default => 'http_' . $status,
        };
    }

    private static function messageFromStatus(int $status): string
    {
        return 'Octonomy request failed with HTTP ' . $status;
    }
}
