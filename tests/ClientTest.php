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
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
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
    }

    public function testSendsAuthAndTenantHeaders(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/get')]);

        $client->tags()->get('tag_1');

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/v1/tags/tag_1', $request->getUri()->getPath());
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        self::assertSame('tenant-1', $request->getHeaderLine('X-Tenant-ID'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('octonomy-php/' . Client::VERSION, $request->getHeaderLine('User-Agent'));
        self::assertSame('', $request->getHeaderLine('X-Actor-ID'));
    }

    public function testActorHeaderFromConfig(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/get')], ['actor_id' => 'svc-config']);

        $client->tags()->get('tag_1');

        self::assertSame('svc-config', $this->lastRequest()->getHeaderLine('X-Actor-ID'));
    }

    public function testActorHeaderPerCallOverride(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/get')], ['actor_id' => 'svc-config']);

        $client->tags()->get('tag_1', RequestOptions::withActor('svc-call'));

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
        $client = $this->clientWith([$captured->response()]);

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
            self::assertSame($error['message'], $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, class-string<ApiException>}>
     */
    public static function errorProvider(): iterable
    {
        yield 'not found' => ['v1/errors/not-found', 'not_found', NotFoundException::class];
        yield 'conflict' => ['v1/errors/conflict', 'conflict', ConflictException::class];
        yield 'validation' => ['v1/errors/validation-error', 'validation_error', ValidationException::class];
        yield 'tenant mismatch' => ['v1/errors/tenant-mismatch', 'tenant_mismatch', ValidationException::class];
        yield 'application mismatch' => ['v1/errors/application-mismatch', 'application_mismatch', ValidationException::class];
        yield 'inactive tag' => ['v1/errors/inactive-tag', 'inactive_tag', ValidationException::class];
        // The server answers a bad or missing token with 403, not 401: it sends no
        // WWW-Authenticate challenge. The code, not the status, picks the class.
        yield 'auth' => ['v1/errors/authentication-required', 'authentication_required', AuthenticationException::class];
        yield 'forbidden' => ['v1/errors/forbidden', 'forbidden', ForbiddenException::class];
    }

    public function testErrorFallbackForNonEnvelopeBody(): void
    {
        // A gateway's body, not Octonomy's: synthetic, since no Octonomy sends it.
        $client = $this->clientWith([self::syntheticNegative(502, 'upstream down', [])]);

        try {
            $client->tags()->get('tag_1');
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame(502, $e->statusCode);
            self::assertSame('http_502', $e->errorCode);
        }
    }
}
