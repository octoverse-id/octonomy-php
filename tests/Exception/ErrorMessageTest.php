<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Exception;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\ErrorCode;
use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestContext;
use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the message formula for a non-2xx response (Internal\ErrorMessage):
 * what happened, why where the code tells the SDK, how to fix it, and the
 * values involved, with no cause the SDK cannot know.
 *
 * Like ApiExceptionTest, it works on (code, status, server message) with no
 * response body, because five codes have no captured envelope (see there).
 * Their server messages are the defaults octonomy/core/errors.py declares at
 * server 3.2.1. ClientTest replays the captured envelopes through a Client.
 */
final class ErrorMessageTest extends TestCase
{
    private const NOT_FOUND_TAIL = ' The server does not say why, so the SDK cannot either.'
        . ' Fix: check that the request above, its id included, is the one you meant to send.'
        . ' Server said: "Resource not found." Detail: "Tag was not found." Request ID: req_1.';

    /**
     * The default state of a stock server: a namespaced write is refused, and
     * the SDK can say exactly why and what to change.
     */
    public function testAWriteWhileNamespacedWritesAreOffSaysWhyAndHowToFixIt(): void
    {
        $context = new RequestContext(ApiVersion::V2, 'POST', '/api/v2/tags', [], new NamespaceScope('merchant', 'globex'));

        self::assertSame(
            'octonomy: namespaced writes are disabled on this Octonomy deployment (namespaced_writes_disabled, HTTP 403).'
            . ' Request: POST /api/v2/tags in namespace merchant:globex.'
            . ' OCTONOMY_NAMESPACE_WRITE_ENABLED is off on the server, as it is by default.'
            . ' Fix: set OCTONOMY_NAMESPACE_WRITE_ENABLED=true on the server, or omit the namespace to write globally.'
            . ' Server said: "Namespaced writes are not enabled." Request ID: req_1.',
            ErrorMessage::forResponse(403, ErrorCode::NAMESPACED_WRITES_DISABLED, 'Namespaced writes are not enabled.', [], 'req_1', $context, Redaction::none()),
        );
    }

    /**
     * Every code the SDK knows gets a fix.
     */
    #[DataProvider('codes')]
    public function testEveryKnownCodeGetsAFix(string $code, int $status): void
    {
        $context = new RequestContext(ApiVersion::V2, 'GET', '/api/v2/tags/tag_1');

        $message = ErrorMessage::forResponse($status, $code, 'The server message.', [], null, $context, Redaction::none());

        self::assertStringStartsWith('octonomy: ', $message);
        self::assertStringContainsString("({$code}, HTTP {$status}).", $message);
        self::assertStringContainsString('Request: GET /api/v2/tags/tag_1.', $message);
        self::assertStringContainsString('Server said: "The server message."', $message);
        self::assertMatchesRegularExpression('/ Fix: [^ ]/', $message);
        self::assertStringNotContainsString('does not know the code', $message);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function codes(): iterable
    {
        yield 'validation_error' => [ErrorCode::VALIDATION, 400];
        yield 'tenant_mismatch' => [ErrorCode::TENANT_MISMATCH, 400];
        yield 'application_mismatch' => [ErrorCode::APPLICATION_MISMATCH, 400];
        yield 'inactive_tag' => [ErrorCode::INACTIVE_TAG, 400];
        yield 'namespace_invalid' => [ErrorCode::NAMESPACE_INVALID, 400];
        yield 'namespace_not_supported' => [ErrorCode::NAMESPACE_NOT_SUPPORTED, 400];
        yield 'ambiguous_resolution' => [ErrorCode::AMBIGUOUS_RESOLUTION, 400];
        yield 'authentication_required' => [ErrorCode::AUTH_REQUIRED, 403];
        yield 'forbidden' => [ErrorCode::FORBIDDEN, 403];
        yield 'namespaced_writes_disabled' => [ErrorCode::NAMESPACED_WRITES_DISABLED, 403];
        yield 'not_found' => [ErrorCode::NOT_FOUND, 404];
        yield 'conflict' => [ErrorCode::CONFLICT, 409];
        yield 'scope_immutable' => [ErrorCode::SCOPE_IMMUTABLE, 409];
        yield 'namespace_api_disabled' => [ErrorCode::NAMESPACE_API_DISABLED, 503];
    }

    public function testTheCodesAboveAreEveryDeclaredCode(): void
    {
        $listed = array_map(static fn(array $row): string => $row[0], iterator_to_array(self::codes(), false));

        self::assertEqualsCanonicalizing(array_values((new \ReflectionClass(ErrorCode::class))->getConstants()), $listed);
    }

    /**
     * A 404 names the kind and the id, and the scope the request was sent in,
     * and says the SDK cannot tell why, but nothing about where else the row
     * might be.
     */
    #[DataProvider('notFound')]
    public function testANotFoundNamesTheKindAndTheIdAndNothingMore(RequestContext $context, string $expected): void
    {
        $message = ErrorMessage::forResponse(404, ErrorCode::NOT_FOUND, 'Resource not found.', ['detail' => 'Tag was not found.'], 'req_1', $context, Redaction::none());

        self::assertSame($expected, $message);
        foreach (['elsewhere', 'another', 'other ', 'exist', 'global partition'] as $speculation) {
            self::assertStringNotContainsString($speculation, $message);
        }
    }

    /**
     * @return iterable<string, array{RequestContext, string}>
     */
    public static function notFound(): iterable
    {
        yield 'a namespaced tag read' => [
            new RequestContext(ApiVersion::V2, 'GET', '/api/v2/tags/tag%2F1', ['application_id' => 'commerce', 'include_global' => 'false'], new NamespaceScope('merchant', 'm-42')),
            'octonomy: tag "tag/1" was not found (not_found, HTTP 404).'
            . ' Request: GET /api/v2/tags/tag%2F1?application_id=commerce&include_global=false in namespace merchant:m-42.'
            . self::NOT_FOUND_TAIL,
        ];
        yield 'a v1 vocabulary delete' => [
            new RequestContext(ApiVersion::V1, 'DELETE', '/api/v1/vocabularies/voc_1'),
            'octonomy: vocabulary "voc_1" was not found (not_found, HTTP 404). Request: DELETE /api/v1/vocabularies/voc_1.'
            . self::NOT_FOUND_TAIL,
        ];
        yield 'a path that is not one resource' => [
            new RequestContext(ApiVersion::V2, 'GET', '/api/v2/tags'),
            'octonomy: the resource was not found (not_found, HTTP 404). Request: GET /api/v2/tags.'
            . ' The server does not say why, so the SDK cannot either.'
            . ' Fix: check that the request above is the one you meant to send.'
            . ' Server said: "Resource not found." Detail: "Tag was not found." Request ID: req_1.',
        ];
    }

    /**
     * No envelope: a proxy, a gateway or something that is not the API
     * answered, and the status alone says nothing about why.
     */
    public function testAnErrorWithoutTheEnvelopeSaysTheSdkCannotTellWhy(): void
    {
        $context = new RequestContext(ApiVersion::V2, 'GET', '/api/v2/tags/tag_1');

        self::assertSame(
            'octonomy: GET /api/v2/tags/tag_1 failed with HTTP 502, and the body is not an Octonomy error envelope'
            . ' ({"error": {...}}; Content-Type text/html), so the SDK cannot tell why. Fix: check that base_url is the'
            . " Octonomy API's address, then the logs of the server and of anything in front of it, such as a proxy or"
            . ' a load balancer.',
            ErrorMessage::forResponse(502, null, null, [], null, $context, Redaction::none(), 'text/html'),
        );
    }

    public function testACodeTheSdkDoesNotKnowIsSaidToBeUnknown(): void
    {
        $context = new RequestContext(ApiVersion::V2, 'POST', '/api/v2/tags');

        self::assertSame(
            'octonomy: the request failed (a_newer_code, HTTP 422). Request: POST /api/v2/tags.'
            . ' This SDK release does not know the code, so it cannot say why.'
            . " Fix: look the request up in the server's logs by its request ID, and check that this SDK release"
            . " supports the server's version. Server said: \"Something newer.\" Request ID: req_1.",
            ErrorMessage::forResponse(422, 'a_newer_code', 'Something newer.', [], 'req_1', $context, Redaction::none()),
        );
    }

    public function testWithoutARequestIdTheFixDoesNotAskForOne(): void
    {
        $context = new RequestContext(ApiVersion::V2, 'POST', '/api/v2/tags');

        self::assertStringContainsString(
            "Fix: look the request up in the server's logs, and check that this SDK release supports the server's version.",
            ErrorMessage::forResponse(422, 'a_newer_code', 'Something newer.', [], null, $context, Redaction::none()),
        );
    }

    /**
     * The one namespace_invalid cause the SDK can see from its own request.
     */
    public function testTheReservedGlobalTypeIsNamedAsTheCause(): void
    {
        $context = new RequestContext(ApiVersion::V2, 'GET', '/api/v2/tags', [], new NamespaceScope('global', 'x'));

        $message = ErrorMessage::forResponse(400, ErrorCode::NAMESPACE_INVALID, 'Namespace headers are invalid.', [], null, $context, Redaction::none());

        self::assertStringContainsString('The namespace type "global" is reserved', $message);
        self::assertStringContainsString('Fix: omit the namespace to use the global partition.', $message);
    }

    /**
     * The details are named, never quoted: a reason can echo what the request
     * sent. The captured validation_error is the evidence of their shape.
     */
    public function testDetailsAreNamedButTheirValuesAreLeftOut(): void
    {
        $error = CapturedFixture::load('v2/errors/validation-error')->json()['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        $context = new RequestContext(ApiVersion::V2, 'POST', '/api/v2/tags');

        $message = ErrorMessage::forResponse(400, ErrorCode::VALIDATION, 'Request validation failed.', $details, null, $context, Redaction::none());

        self::assertStringContainsString('Details on: name, slug.', $message);
        self::assertStringNotContainsString('This field may not be blank.', $message);
        self::assertStringNotContainsString('Use lowercase letters', $message);
    }

    /**
     * A server may echo what the request sent. Whatever it quotes from the
     * request (the token, the actor id, a value from the body) is replaced in
     * the message, wherever it appears in what the server sent: the message,
     * the detail, the details' names, the request id or the code.
     */
    public function testWhatTheServerEchoesFromTheRequestIsReplaced(): void
    {
        $redaction = Redaction::of('octo_tokensecret', 'actor-7', ['slug' => 'slug-from-the-body', 'metadata' => ['note' => 'a/b']]);
        $context = new RequestContext(ApiVersion::V2, 'POST', '/api/v2/tags');

        $message = ErrorMessage::forResponse(
            400,
            ErrorCode::VALIDATION,
            'Invalid value slug-from-the-body from actor-7 with Bearer octo_tokensecret.',
            ['detail' => 'Rejected {"note":"a\\/b"}', 'slug-from-the-body' => ['echoed as a name']],
            'req_actor-7',
            $context,
            $redaction,
        );

        foreach (['slug-from-the-body', 'actor-7', 'octo_tokensecret', 'a\\/b'] as $echoed) {
            self::assertStringNotContainsString($echoed, $message);
        }
        self::assertStringContainsString(
            'Server said: "Invalid value [redacted] from [redacted] with Bearer [redacted]." Detail: "Rejected {"[redacted]":"[redacted]"}"'
            . ' Details on: [redacted]. Request ID: req_[redacted].',
            $message,
        );
    }
}
