<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\ErrorCode;
use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\RequestContext;

/**
 * ApiException represents a non-2xx response from Octonomy. It carries the HTTP
 * status alongside the server's error envelope ({"error": {...}}) so callers can
 * inspect the machine code, details, and request id, and the RequestContext of
 * the request that failed.
 *
 * The message is the SDK's, not the server's: it says what failed and with
 * which request, why where the error code tells the SDK, and how to fix it,
 * and it quotes the server's own message with any value the request carried
 * (the token, the actor id, the body) replaced. $serverMessage and $details
 * hold what the server sent, unedited: they are the server's data, not a
 * message, and can echo what the request sent.
 *
 * Prefer catching the specific subclasses (NotFoundException, ConflictException,
 * ValidationException, AuthenticationException, ForbiddenException,
 * FeatureDisabledException, UnsupportedApiVersionException,
 * AmbiguousResolutionException) where you need to branch; catch ApiException
 * for the general case.
 */
class ApiException extends OctonomyException
{
    /**
     * @param RequestContext       $context       the request that received this response
     * @param array<string, mixed> $details
     * @param string|null          $serverMessage the error envelope's message as the server sent it, unedited,
     *                                            or null when the response carried none
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $errorCode,
        public readonly RequestContext $context,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
        public readonly ?string $serverMessage = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Build the most specific ApiException subclass for a non-2xx response,
     * decoding the standard Octonomy error envelope and falling back to the HTTP
     * status when the envelope is absent.
     *
     * @param array<string, mixed> $body
     * @param string               $contentType the response's Content-Type header, '' when it had none
     * @param Redaction|null       $redaction   @internal the values the request carried, which the message
     *                                          must not quote from the response; null for none
     */
    public static function fromResponse(
        int $status,
        array $body,
        RequestContext $context,
        string $contentType = '',
        ?Redaction $redaction = null,
    ): self {
        // Read leniently, unlike a 2xx body: whatever a gateway or proxy sent in
        // place of the envelope, the status alone still picks the exception.
        $error = self::member($body, 'error');

        $serverCode = self::text($error, 'code');
        $code = $serverCode ?? self::codeFromStatus($status);
        $serverMessage = self::text($error, 'message');
        $details = self::member($error, 'details');
        $requestId = self::text($error, 'request_id');

        $class = self::classFor($code, $status);
        // The message reads the server's code, never the one derived from the
        // status: a bare 404 from a proxy must not be described as a missing row.
        $message = ErrorMessage::forResponse(
            $status,
            $serverCode,
            $serverMessage,
            $details,
            $requestId,
            $context,
            $redaction ?? Redaction::none(),
            $contentType,
        );

        return new $class($message, $status, $code, $context, $details, $requestId, $serverMessage);
    }

    /**
     * The exception class for an error response: by its code when the code is
     * one ErrorCode declares, and by the HTTP status otherwise.
     *
     * The code decides first because statuses are shared: a 403 is either a
     * token without the grant (forbidden) or a deployment flag
     * (namespaced_writes_disabled), and a 400 is any of seven codes.
     * Only the five statuses below have a fallback class. Any other status
     * without a known code, a 503 included, stays a bare ApiException: a 503
     * without the envelope is a proxy or load balancer, and reading it as
     * namespace_api_disabled would name a cause the server never stated.
     *
     * @return class-string<self>
     *
     * @internal public so the mapping can be tested without a response body
     */
    public static function classFor(string $code, int $status): string
    {
        return match ($code) {
            ErrorCode::NOT_FOUND => NotFoundException::class,
            ErrorCode::CONFLICT, ErrorCode::SCOPE_IMMUTABLE => ConflictException::class,
            ErrorCode::VALIDATION,
            ErrorCode::TENANT_MISMATCH,
            ErrorCode::APPLICATION_MISMATCH,
            ErrorCode::INACTIVE_TAG,
            ErrorCode::NAMESPACE_INVALID => ValidationException::class,
            ErrorCode::AUTH_REQUIRED => AuthenticationException::class,
            ErrorCode::FORBIDDEN => ForbiddenException::class,
            ErrorCode::NAMESPACED_WRITES_DISABLED, ErrorCode::NAMESPACE_API_DISABLED => FeatureDisabledException::class,
            ErrorCode::NAMESPACE_NOT_SUPPORTED => UnsupportedApiVersionException::class,
            ErrorCode::AMBIGUOUS_RESOLUTION => AmbiguousResolutionException::class,
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
