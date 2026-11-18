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
     * @readonly
     */
    public int $statusCode;
    /**
     * @readonly
     */
    public string $errorCode;
    /**
     * @var RequestContext
     * @readonly
     */
    public RequestContext $context;
    /**
     * @var array<string, mixed>
     * @readonly
     */
    public array $details = [];
    /**
     * @readonly
     */
    public ?string $requestId = null;
    /**
     * @var string|null
     * @readonly
     */
    public ?string $serverMessage = null;
    /**
     * @param RequestContext       $context       the request that received this response
     * @param array<string, mixed> $details
     * @param string|null          $serverMessage the error envelope's message as the server sent it, unedited,
     *                                            or null when the response carried none
     */
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        RequestContext $context,
        array $details = [],
        ?string $requestId = null,
        ?string $serverMessage = null
    ) {
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->context = $context;
        $this->details = $details;
        $this->requestId = $requestId;
        $this->serverMessage = $serverMessage;
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
        ?Redaction $redaction = null
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
        switch ($code) {
            case ErrorCode::NOT_FOUND:
                return NotFoundException::class;
            case ErrorCode::CONFLICT:
            case ErrorCode::SCOPE_IMMUTABLE:
                return ConflictException::class;
            case ErrorCode::VALIDATION:
            case ErrorCode::TENANT_MISMATCH:
            case ErrorCode::APPLICATION_MISMATCH:
            case ErrorCode::INACTIVE_TAG:
            case ErrorCode::NAMESPACE_INVALID:
                return ValidationException::class;
            case ErrorCode::AUTH_REQUIRED:
                return AuthenticationException::class;
            case ErrorCode::FORBIDDEN:
                return ForbiddenException::class;
            case ErrorCode::NAMESPACED_WRITES_DISABLED:
            case ErrorCode::NAMESPACE_API_DISABLED:
                return FeatureDisabledException::class;
            case ErrorCode::NAMESPACE_NOT_SUPPORTED:
                return UnsupportedApiVersionException::class;
            case ErrorCode::AMBIGUOUS_RESOLUTION:
                return AmbiguousResolutionException::class;
            default:
                switch ($status) {
                    case 404:
                        return NotFoundException::class;
                    case 409:
                        return ConflictException::class;
                    case 401:
                        return AuthenticationException::class;
                    case 403:
                        return ForbiddenException::class;
                    case 400:
                        return ValidationException::class;
                    default:
                        return self::class;
                }
        }
    }

    private static function codeFromStatus(int $status): string
    {
        switch ($status) {
            case 400:
                return ErrorCode::VALIDATION;
            case 401:
                return ErrorCode::AUTH_REQUIRED;
            case 403:
                return ErrorCode::FORBIDDEN;
            case 404:
                return ErrorCode::NOT_FOUND;
            case 409:
                return ErrorCode::CONFLICT;
            default:
                return 'http_' . $status;
        }
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
