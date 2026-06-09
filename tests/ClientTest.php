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
        $client = $this->clientWith([self::jsonResponse(200, ['id' => 'tag_1'])]);

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
        $client = $this->clientWith([self::jsonResponse(200, ['id' => 'tag_1'])], ['actor_id' => 'svc-config']);

        $client->tags()->get('tag_1');

        self::assertSame('svc-config', $this->lastRequest()->getHeaderLine('X-Actor-ID'));
    }

    public function testActorHeaderPerCallOverride(): void
    {
        $client = $this->clientWith([self::jsonResponse(200, ['id' => 'tag_1'])], ['actor_id' => 'svc-config']);

        $client->tags()->get('tag_1', RequestOptions::withActor('svc-call'));

        self::assertSame('svc-call', $this->lastRequest()->getHeaderLine('X-Actor-ID'));
    }

    /**
     * @param class-string<ApiException> $expected
     */
    #[DataProvider('errorProvider')]
    public function testErrorEnvelopeMapsToException(int $status, string $code, string $expected): void
    {
        $client = $this->clientWith([
            self::jsonResponse($status, [
                'error' => [
                    'code' => $code,
                    'message' => 'boom',
                    'details' => ['field' => 'slug'],
                    'request_id' => 'req_123',
                ],
            ]),
        ]);

        try {
            $client->tags()->get('tag_1');
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame($status, $e->statusCode);
            self::assertSame($code, $e->errorCode);
            self::assertSame('req_123', $e->requestId);
            self::assertSame('slug', $e->details['field'] ?? null);
            self::assertSame('boom', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function errorProvider(): iterable
    {
        yield 'not found' => [404, 'not_found', NotFoundException::class];
        yield 'conflict' => [409, 'conflict', ConflictException::class];
        yield 'validation' => [400, 'validation_error', ValidationException::class];
        yield 'tenant mismatch' => [400, 'tenant_mismatch', ValidationException::class];
        yield 'auth' => [401, 'authentication_required', AuthenticationException::class];
        yield 'forbidden' => [403, 'forbidden', ForbiddenException::class];
    }

    public function testErrorFallbackForNonEnvelopeBody(): void
    {
        $client = $this->clientWith([new \GuzzleHttp\Psr7\Response(502, [], 'upstream down')]);

        try {
            $client->tags()->get('tag_1');
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame(502, $e->statusCode);
            self::assertSame('http_502', $e->errorCode);
        }
    }
}
