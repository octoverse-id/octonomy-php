<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\AuthenticationException;
use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\ForbiddenException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\UnsupportedApiVersionException;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\RequestContext;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientTest extends SdkTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigProvider')]
    public function testInvalidConfigThrows(array $config): void
    {
        $this->expectException(ConfigurationException::class);
        new Client($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'missing base_url' => [['token' => 't', 'tenant_id' => 'acme']];
        yield 'missing token' => [['base_url' => 'https://x.test', 'tenant_id' => 'acme']];
        yield 'missing tenant_id' => [['base_url' => 'https://x.test', 'token' => 't']];
        yield 'relative base_url' => [['base_url' => 'x.test', 'token' => 't', 'tenant_id' => 'acme']];
        yield 'non-string token' => [['base_url' => 'https://x.test', 'token' => 123, 'tenant_id' => 'acme']];
    }

    public function testValidConfigBuildsServices(): void
    {
        $client = new Client([
            'base_url' => 'https://octonomy.test/',
            'token' => 't',
            'tenant_id' => 'acme',
        ]);

        self::assertSame($client->tags(), $client->tags());
        self::assertSame($client->vocabularies(), $client->vocabularies());
        self::assertSame($client->aliases(), $client->aliases());
        self::assertSame($client->assignments(), $client->assignments());
        self::assertSame($client->resources(), $client->resources());
        self::assertSame($client->auditLogs(), $client->auditLogs());
    }

    public function testSendsAuthAndTenantHeaders(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')]);

        $client->tags()->get('tag_1');

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/v2/tags/tag_1', $request->getUri()->getPath());
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        self::assertSame('tenant-1', $request->getHeaderLine('X-Tenant-ID'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('octonomy-php/' . Client::VERSION, $request->getHeaderLine('User-Agent'));
        self::assertSame('', $request->getHeaderLine('X-Actor-ID'));
        // No namespace was named, so none is sent: the global partition.
        self::assertFalse($request->hasHeader('X-Namespace-Type'));
        self::assertFalse($request->hasHeader('X-Namespace-ID'));
    }

    public function testActorHeaderFromConfig(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')], ['actor_id' => 'svc-config']);

        $client->tags()->get('tag_1');

        self::assertSame('svc-config', $this->lastRequest()->getHeaderLine('X-Actor-ID'));
    }

    public function testActorHeaderPerCallOverride(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')], ['actor_id' => 'svc-config']);

        $client->tags()->get('tag_1', options: RequestOptions::withActor('svc-call'));

        self::assertSame('svc-call', $this->lastRequest()->getHeaderLine('X-Actor-ID'));
    }

    /**
     * @param class-string<ApiException> $expected
     */
    #[DataProvider('errorProvider')]
    public function testErrorEnvelopeMapsToException(string $fixture, string $code, string $expected): void
    {
        $captured = CapturedFixture::load($fixture);
        $error = $captured->json()['error'];
        self::assertSame($code, $error['code'], "{$fixture} is not the {$code} envelope");
        $client = $this->clientWith([$captured], ['api_version' => $captured->apiVersion()]);

        try {
            $client->tags()->get('tag_1');
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame($captured->status, $e->statusCode);
            self::assertSame($code, $e->errorCode);
            self::assertSame($error['request_id'], $e->requestId);
            self::assertNotNull($e->requestId);
            self::assertSame($error['details'], $e->details);
            // The message is the SDK's: it names the code and the request, and
            // quotes the server's message, which $serverMessage keeps as sent.
            self::assertSame($error['message'], $e->serverMessage);
            self::assertStringStartsWith('octonomy: ', $e->getMessage());
            self::assertStringContainsString("({$code}, HTTP {$captured->status}).", $e->getMessage());
            self::assertStringContainsString("Request: GET /api/{$captured->apiVersion()}/tags/tag_1.", $e->getMessage());
            self::assertStringContainsString("Server said: \"{$error['message']}\"", $e->getMessage());
            self::assertStringContainsString("Request ID: {$error['request_id']}.", $e->getMessage());
            self::assertEquals(new RequestContext($captured->apiVersion(), 'GET', "/api/{$captured->apiVersion()}/tags/tag_1"), $e->context);
        }
    }

    /**
     * @return iterable<string, array{string, string, class-string<ApiException>}>
     */
    public static function errorProvider(): iterable
    {
        foreach (['v1', 'v2'] as $v) {
            yield "{$v} not found" => ["{$v}/errors/not-found", 'not_found', NotFoundException::class];
            yield "{$v} conflict" => ["{$v}/errors/conflict", 'conflict', ConflictException::class];
            yield "{$v} validation" => ["{$v}/errors/validation-error", 'validation_error', ValidationException::class];
            yield "{$v} tenant mismatch" => ["{$v}/errors/tenant-mismatch", 'tenant_mismatch', ValidationException::class];
            yield "{$v} inactive tag" => ["{$v}/errors/inactive-tag", 'inactive_tag', ValidationException::class];
            // The server answers a bad or missing token with 403, not 401: it sends no
            // WWW-Authenticate challenge. The code, not the status, picks the class.
            yield "{$v} auth" => ["{$v}/errors/authentication-required", 'authentication_required', AuthenticationException::class];
            yield "{$v} forbidden" => ["{$v}/errors/forbidden", 'forbidden', ForbiddenException::class];
        }
        // Captured on v1 only: v2 cannot produce it from the harness corpus.
        yield 'v1 application mismatch' => ['v1/errors/application-mismatch', 'application_mismatch', ValidationException::class];
        // v1 only by definition: v2 has the namespace axis. A version mismatch,
        // not invalid input, so not a ValidationException.
        yield 'v1 namespace not supported' => ['v1/errors/namespace-not-supported', 'namespace_not_supported', UnsupportedApiVersionException::class];
    }

    /**
     * A gateway's error page carries no envelope, so the status alone decides,
     * and a 502 or 503 from one is a bare ApiException. A 503 in particular is
     * not read as the server's namespace_api_disabled flag, which only an
     * enveloped 503 states.
     */
    #[DataProvider('gatewayStatuses')]
    public function testErrorFallbackForNonEnvelopeBody(int $status): void
    {
        $client = $this->clientWith([self::syntheticNegative(SyntheticNegative::GATEWAY, $status, 'upstream down', [])]);

        try {
            $client->tags()->get('tag_1');
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame($status, $e->statusCode);
            self::assertSame("http_{$status}", $e->errorCode);
            self::assertEquals(new RequestContext('v2', 'GET', '/api/v2/tags/tag_1'), $e->context);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function gatewayStatuses(): iterable
    {
        yield 'a 502 from a gateway' => [502];
        yield 'a 503 from a load balancer' => [503];
    }
}
