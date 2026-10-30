<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

use Octoverse\Octonomy\ErrorCode;
use Octoverse\Octonomy\RequestContext;

/**
 * ErrorMessage writes the message of every exception a request ends in, to one
 * formula: what happened, why when the SDK actually knows, how to fix it, and
 * the values involved. Where the SDK cannot know why, the message says so
 * instead of guessing.
 *
 * The values are the request as RequestContext describes it, the status, the
 * error envelope's code, message, detail, request id and the names of its
 * details, and the HTTP client's own error. The SDK's own words never
 * interpolate anything on Redaction's list. Every piece of text it quotes from
 * the client or the server passes through Redaction::text() first, which
 * replaces the values the request carried: a client's middleware or a server
 * can echo the request. (At server 3.2.1 no envelope message or detail does.)
 * The details' values are left out entirely, because a validation reason can
 * quote what the request sent ('"x" is not a valid choice.');
 * ApiException::$details keeps them for the caller.
 *
 * A 404 names the kind and the id of the resource it asked for, says the SDK
 * cannot tell why, and asks for the request to be checked, and says nothing
 * about where else the row might be: a scoped read deliberately does not
 * reveal whether it exists outside what the request could see.
 *
 * @internal
 */
final class ErrorMessage
{
    /**
     * The collections whose "{collection}/{id}" path names a single resource,
     * by the kind a 404 message calls it.
     */
    private const ITEM_KINDS = ['tags' => 'tag', 'vocabularies' => 'vocabulary', 'tag-aliases' => 'tag alias'];

    private function __construct() {}

    /**
     * The message for a non-2xx response.
     *
     * @param string|null          $code          the error envelope's code, or null when the body carries
     *                                            no envelope
     * @param string|null          $serverMessage the error envelope's message
     * @param array<string, mixed> $details       the error envelope's details
     * @param Redaction            $redaction     the values the request carried, to replace in what the
     *                                            server sent
     * @param string               $contentType   the response's Content-Type header, '' when it had none
     */
    public static function forResponse(
        int $status,
        ?string $code,
        ?string $serverMessage,
        array $details,
        ?string $requestId,
        RequestContext $context,
        Redaction $redaction,
        string $contentType = '',
    ): string {
        if ($code === null) {
            // The body did not come from Octonomy's error handler: a proxy, a
            // gateway, a crash page, or a base_url that is not the API could
            // have sent it, and the status alone does not say which.
            return sprintf(
                'octonomy: %s failed with HTTP %d, and the body is not an Octonomy error envelope'
                . ' ({"error": {...}}; %s), so the SDK cannot tell why.'
                . ' Fix: check that base_url is the Octonomy API\'s address, then the logs of the server'
                . ' and of anything in front of it, such as a proxy or a load balancer.',
                self::request($context),
                $status,
                $redaction->text(self::contentType($contentType)),
            );
        }

        [$what, $why, $fix] = self::explain($code, $context, $requestId !== null);
        $server = self::server($serverMessage, $details, $requestId);

        return implode(' ', array_filter([
            sprintf('octonomy: %s (%s, HTTP %d).', $what, $redaction->text($code), $status),
            sprintf('Request: %s.', self::request($context)),
            $why,
            'Fix: ' . $fix,
            $server === null ? null : $redaction->text($server),
        ]));
    }

    /**
     * The message for a request the HTTP client threw on, so the SDK got no
     * response it could use. The client's error is quoted, redacted, and never
     * interpreted: from it alone the SDK cannot tell why the request failed,
     * nor, for a write, whether the server applied it. When the client's
     * exception holds a response ($status), the message names its status, and
     * claims nothing about where it came from: middleware can make one up.
     *
     * @param string    $origin    the scheme, host and port of base_url, without any credentials in it
     * @param int|null  $status    the status of the response the client's exception holds, if any
     * @param Redaction $redaction the values the request carried, to replace in the client's error
     */
    public static function forClientFailure(
        RequestContext $context,
        string $origin,
        \Throwable $clientError,
        ?int $status,
        Redaction $redaction,
    ): string {
        $write = !in_array($context->method, ['GET', 'HEAD', 'OPTIONS'], true);

        return sprintf(
            'octonomy: the HTTP client failed on %s to %s%s, with %s%s.'
            . ' The SDK cannot tell from this why the request failed%s.'
            . ' Fix: act on the client\'s error; if it could not connect, check that the server at %s is up and'
            . ' reachable from this host%s.',
            self::request($context),
            $origin,
            $status === null ? '' : sprintf(' after an HTTP %d response', $status),
            $clientError::class,
            // Withheld whole when the request the client's exception holds has
            // a body the redaction could not read: it may be quoted there.
            $redaction->isComplete()
                ? sprintf(': "%s"', $redaction->text($clientError->getMessage()))
                : ', whose message is not shown: the request it holds has a body the SDK cannot read back to redact',
            $write ? ', or whether the server received and applied it' : '',
            $origin,
            $write ? '; before sending the write again, check whether it took effect' : '',
        );
    }

    /**
     * The message for a body that cannot be encoded as JSON, so nothing was
     * sent. It never quotes the body.
     *
     * @param string $reason why, in words that hold none of the body
     * @param string $fix    what to change
     */
    public static function unencodable(RequestContext $context, string $reason, string $fix): string
    {
        return sprintf(
            'octonomy: the body of %s cannot be encoded as JSON: %s. Nothing was sent, and the body is not shown.'
            . ' Fix: %s',
            self::request($context),
            $reason,
            $fix,
        );
    }

    /**
     * What a json_encode() error code means for a request body, in fixed words:
     * never the exception's own message, which an object's jsonSerialize()
     * could have written.
     */
    public static function jsonError(int $code): string
    {
        return match ($code) {
            JSON_ERROR_DEPTH => 'it is nested deeper than 512 levels',
            JSON_ERROR_RECURSION => 'it refers to itself',
            JSON_ERROR_INF_OR_NAN => 'a number in it is INF or NAN',
            JSON_ERROR_UNSUPPORTED_TYPE => 'it holds a value JSON has no type for, such as a resource',
            JSON_ERROR_UTF8 => 'a string in it is not valid UTF-8',
            default => sprintf('json_encode() refused it with error %d', $code),
        };
    }

    /**
     * The message for a value an HTTP header cannot carry, such as one with a
     * trailing newline. It describes the value and never quotes it, since it
     * may be the token or the actor id.
     *
     * @param string $what   what holds the value, as the caller named it, such as 'token' or 'actorId'
     * @param string $header the header it is sent as
     */
    public static function unsendable(string $what, string $header): string
    {
        return sprintf(
            'octonomy: %s holds a line break or another control character, which the %s header cannot carry;'
            . ' the value is not shown. Fix: remove the control characters, such as a trailing newline left by'
            . ' reading the value from a file.',
            $what,
            $header,
        );
    }

    /**
     * The message for an empty path segment (Path::of()), which no URL can
     * carry, so nothing was sent. The server refuses a blank id or resource
     * type everywhere, so the SDK knows the request could not have named a
     * resource.
     */
    public static function emptyPathSegment(): string
    {
        return 'octonomy: an id or another path segment is empty, so the request would name no single resource,'
            . ' and the server refuses a blank id or resource type. Nothing was sent. Fix: pass the id of the'
            . ' resource you mean.';
    }

    /**
     * The message for a timeout given together with an HTTP client. The SDK
     * sets a timeout only on the client it builds, so this one would be
     * dropped without a word, as it was before #18.
     */
    public static function timeoutWithHttpClient(float $timeout): string
    {
        return sprintf(
            'octonomy: timeout and http_client are both set, and the timeout of %s seconds would be ignored: the'
            . ' SDK sets the timeout of only the HTTP client it builds itself. Fix: set the timeout on the client'
            . ' passed as http_client, such as Guzzle\'s \'timeout\' option, or leave http_client out to have the'
            . ' SDK build a client with this timeout.',
            $timeout,
        );
    }

    /**
     * The message for a 2xx whose body is not JSON. Octonomy answers every
     * endpoint that has a body with JSON, so the SDK cannot read this one, and
     * cannot tell what sent it. (An empty body is not this case: Json::decode()
     * reads it as null, and the envelope check reports it.)
     *
     * @param string    $contentType the response's Content-Type header, '' when it had none
     * @param int       $bytes       the length of the body
     * @param string    $jsonError   json_decode()'s reason
     * @param Redaction $redaction   the values the request carried, to replace in the Content-Type
     */
    public static function forUndecodable(
        RequestContext $context,
        int $status,
        string $contentType,
        int $bytes,
        string $jsonError,
        Redaction $redaction,
    ): string {
        return sprintf(
            'octonomy: %s answered HTTP %d with a body that is not JSON (%s; %s, %d bytes), where the Octonomy API'
            . ' sends JSON. The SDK cannot tell what sent it. Fix: check that base_url is the Octonomy API\'s address,'
            . ' not a web page or a proxy in front of it.',
            self::request($context),
            $status,
            $jsonError,
            $redaction->text(self::contentType($contentType)),
            $bytes,
        );
    }

    /**
     * The request as a message names it: the method, the path and query as
     * sent, and the namespace, such as
     * "GET /api/v2/tags/7d1c?application_id=commerce in namespace merchant:m-42".
     */
    public static function request(RequestContext $context): string
    {
        $out = $context->endpoint();
        if ($context->query !== []) {
            $out .= '?' . http_build_query($context->query, '', '&', PHP_QUERY_RFC3986);
        }
        if ($context->namespace !== null) {
            $out .= sprintf(' in namespace %s:%s', $context->namespace->type, $context->namespace->id);
        }

        return $out;
    }

    /**
     * What happened, why (null where the SDK does not know, unless it must say
     * that it cannot), and the fix, for an error code. The why of a deployment
     * flag is knowable because the server sends that code for that flag and
     * nothing else.
     *
     * @param bool $requestId whether the server sent a request id to look the request up by
     *
     * @return array{string, string|null, string}
     */
    private static function explain(string $code, RequestContext $context, bool $requestId): array
    {
        $item = self::item($context);

        return match ($code) {
            ErrorCode::NAMESPACED_WRITES_DISABLED => [
                'namespaced writes are disabled on this Octonomy deployment',
                'OCTONOMY_NAMESPACE_WRITE_ENABLED is off on the server, as it is by default.',
                'set OCTONOMY_NAMESPACE_WRITE_ENABLED=true on the server, or omit the namespace to write globally.',
            ],
            ErrorCode::NAMESPACE_API_DISABLED => [
                'the namespaced v2 API is disabled on this Octonomy deployment',
                'OCTONOMY_NAMESPACE_V2_API_ENABLED is off on the server. It is on by default, and turning it off'
                . ' is the first step of a namespace rollback; requests without a namespace still work.',
                'have the server\'s operator set OCTONOMY_NAMESPACE_V2_API_ENABLED=true, or omit the namespace'
                . ' to use the global partition.',
            ],
            ErrorCode::NAMESPACE_NOT_SUPPORTED => [
                'namespace headers reached an API version that has no namespaces',
                'Only /api/v2 has namespaces. This SDK sends X-Namespace-Type and X-Namespace-ID only from'
                . ' RequestOptions::$namespace, and only on a v2 Client.',
                'build the Client with \'api_version\' => \'v2\' (Octonomy server 2.0.0 or newer), or remove'
                . ' whatever else adds those headers.',
            ],
            ErrorCode::NAMESPACE_INVALID => $context->namespace?->type === 'global'
                ? [
                    'the server refused the namespace',
                    'The namespace type "global" is reserved: it names the tenant-shared partition.',
                    'omit the namespace to use the global partition.',
                ]
                : [
                    'the server refused the namespace',
                    null,
                    'check the namespace type and id, and that nothing else adds X-Namespace-* headers.',
                ],
            // Nothing about where else the row might be: a scoped read does not
            // reveal whether it exists outside what the request could see.
            ErrorCode::NOT_FOUND => [
                $item === null ? 'the resource was not found' : sprintf('%s "%s" was not found', $item[0], $item[1]),
                'The server does not say why, so the SDK cannot either.',
                $item === null
                    ? 'check that the request above is the one you meant to send.'
                    : 'check that the request above, its id included, is the one you meant to send.',
            ],
            ErrorCode::CONFLICT => [
                'the request conflicts with existing data',
                null,
                'change the conflicting values the details name, or use the existing row.',
            ],
            ErrorCode::SCOPE_IMMUTABLE => [
                'a row\'s application and namespace cannot change after it is created',
                'The update would move the row to another application or namespace.',
                'create the row again in the scope it should have, instead of updating it.',
            ],
            ErrorCode::VALIDATION => [
                'the server rejected the request as invalid',
                null,
                'correct the values the details name and send the request again; $details holds the server\'s'
                . ' reason for each.',
            ],
            ErrorCode::TENANT_MISMATCH => [
                'the request reaches outside its tenant',
                null,
                'check that tenant_id is a tenant the token is granted (create_service_token --tenant), and that'
                . ' every id the request names belongs to that tenant.',
            ],
            ErrorCode::APPLICATION_MISMATCH => [
                'the request does not fit its application',
                null,
                'send an application_id the token is granted, which a token without a tenant-wide grant must,'
                . ' and use rows that belong to that application.',
            ],
            ErrorCode::INACTIVE_TAG => [
                'the tag is inactive',
                'Deleting a tag deactivates it, and an inactive tag cannot be assigned.',
                'assign an active tag, or reactivate this one with tags()->update($id, new TagUpdate(isActive: true)).',
            ],
            ErrorCode::AUTH_REQUIRED => [
                'the server did not accept the token',
                null,
                'pass a valid service token as the \'token\' option. An Octonomy operator issues one on the server'
                . ' with `python manage.py create_service_token`, which prints it once.',
            ],
            ErrorCode::FORBIDDEN => [
                'the token is not allowed to make this request',
                null,
                'use a token that is active, unexpired, and granted this request\'s scope, application and'
                . ' namespace; an operator issues one with `python manage.py create_service_token`.',
            ],
            ErrorCode::AMBIGUOUS_RESOLUTION => [
                'more than one tag or alias matched equally well',
                null,
                'narrow the request by application_id, type or namespace; $details names the axis that tells'
                . ' them apart.',
            ],
            default => [
                'the request failed',
                'This SDK release does not know the code, so it cannot say why.',
                sprintf('look the request up in the server\'s logs%s, and check that this SDK release supports the'
                . ' server\'s version.', $requestId ? ' by its request ID' : ''),
            ],
        };
    }

    /**
     * The kind and the id of the single resource the request's path names,
     * such as ['tag', '7d1c'], or null when the path is not one resource's.
     *
     * @return array{string, string}|null
     */
    private static function item(RequestContext $context): ?array
    {
        $prefix = '/api/' . $context->apiVersion . '/';
        if (str_starts_with($context->path, $prefix)) {
            $segments = explode('/', substr($context->path, strlen($prefix)));
            if (count($segments) === 2 && isset(self::ITEM_KINDS[$segments[0]]) && $segments[1] !== '') {
                return [self::ITEM_KINDS[$segments[0]], rawurldecode($segments[1])];
            }
        }

        return null;
    }

    /**
     * What the error envelope said, quoted: its message, its detail when that is
     * a string, the names its details carry, and its request id.
     *
     * @param array<string, mixed> $details
     */
    private static function server(?string $message, array $details, ?string $requestId): ?string
    {
        $parts = [];
        if ($message !== null) {
            $parts[] = sprintf('Server said: "%s"', $message);
        }
        $detail = $details['detail'] ?? null;
        if (is_string($detail)) {
            $parts[] = sprintf('Detail: "%s"', $detail);
        }
        // Names only, never values (see the class comment). A list of reasons
        // has integer keys and names nothing.
        $named = array_filter(array_keys($details), static fn(int|string $key): bool => is_string($key) && $key !== 'detail');
        if ($named !== []) {
            $parts[] = sprintf('Details on: %s.', implode(', ', $named));
        }
        if ($requestId !== null) {
            $parts[] = sprintf('Request ID: %s.', $requestId);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private static function contentType(string $contentType): string
    {
        return $contentType === '' ? 'no Content-Type' : 'Content-Type ' . $contentType;
    }
}
