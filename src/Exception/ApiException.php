<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\ErrorCode;

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
        // Read leniently, unlike a 2xx body: whatever a gateway or proxy sent in
        // place of the envelope, the status alone still picks the exception.
        $error = self::member($body, 'error');

        $code = self::text($error, 'code') ?? self::codeFromStatus($status);
        $message = self::text($error, 'message') ?? self::messageFromStatus($status);
        $details = self::member($error, 'details');
        $requestId = self::text($error, 'request_id');

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

    /**
     * The JSON object under $key, or an empty array when it is absent or not an object.
     *
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private static function member(array $a, string $key): array
    {
        $value = $a[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $item) {
            $out[(string) $k] = $item;
        }

        return $out;
    }

    /**
     * The string under $key, or null when it is absent or not a string.
     *
     * @param array<string, mixed> $a
     */
    private static function text(array $a, string $key): ?string
    {
        $value = $a[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
