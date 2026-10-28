<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Http;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\RecordingFactory;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Covers an http_client that is not Guzzle's (#18). The SDK can only call its
 * PSR-18 sendRequest(), so everything the request carries must already be on
 * the request: the URI with its query, every header, the body.
 */
final class Psr18ClientTest extends SdkTestCase
{
    public function testItIsHandedTheRequestAsTheSdkBuiltIt(): void
    {
        $client = $this->psr18ClientWith([self::captured('v2/tags/create')], ['actor_id' => 'svc-catalog']);

        $tag = $client->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label', applicationId: 'harness-app'),
            new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a')),
        );

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://octonomy.test/api/v2/tags', (string) $request->getUri());
        self::assertSame([
            'Authorization' => 'Bearer test-token',
            'X-Tenant-ID' => 'tenant-1',
            'Accept' => 'application/json',
            'User-Agent' => 'octonomy-php/' . Client::VERSION,
            'X-Actor-ID' => 'svc-catalog',
            'X-Namespace-Type' => 'merchant',
            'X-Namespace-ID' => 'harness-merchant-a',
            'Content-Type' => 'application/json',
        ], array_diff_key(array_map(static fn(array $values): string => implode(', ', $values), $request->getHeaders()), ['Host' => true]));
        self::assertSame('{"name":"Featured","slug":"featured","type":"label","application_id":"harness-app"}', $this->lastRawBody());
        self::assertSame(self::capturedData('v2/tags/create')['id'], $tag->id);
    }

    public function testAListCarriesItsQueryOnTheUri(): void
    {
        $client = $this->psr18ClientWith([self::captured('v2/tags/list')]);

        $page = $client->tags()->list(new TagListParams(applicationId: 'commerce', limit: 25, includeGlobal: true));

        self::assertSame('application_id=commerce&limit=25&include_global=true', $this->lastRequest()->getUri()->getQuery());
        self::assertCount(count(self::capturedData('v2/tags/list')), $page->data);
    }

    public function testADeleteReturnsNothing(): void
    {
        $client = $this->psr18ClientWith([self::captured('v2/tags/delete')]);

        $client->tags()->delete('tag_1');

        self::assertSame('DELETE', $this->lastRequest()->getMethod());
        self::assertSame('/api/v2/tags/tag_1', $this->lastRequest()->getUri()->getPath());
        self::assertSame('', $this->lastRawBody());
    }

    /**
     * A PSR-18 client returns a 4xx or 5xx rather than throwing, and the SDK
     * maps it as it does Guzzle's.
     *
     * @param class-string<ApiException>   $expected
     * @param \Closure(Client): mixed      $call
     */
    #[DataProvider('errorResponses')]
    public function testAnErrorStatusBecomesItsTypedException(string $fixture, string $expected, \Closure $call): void
    {
        $client = $this->psr18ClientWith([self::captured($fixture)]);

        try {
            $call($client);
            self::fail("expected {$expected}");
        } catch (ApiException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame(self::captured($fixture)->status, $e->statusCode);
        }
    }

    /**
     * @return iterable<string, array{string, class-string<ApiException>, \Closure(Client): mixed}>
     */
    public static function errorResponses(): iterable
    {
        $create = static fn(Client $client): mixed => $client->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label', applicationId: 'harness-app'),
        );

        yield 'not found' => ['v2/errors/not-found', NotFoundException::class, static fn(Client $client): mixed => $client->tags()->get('tag_missing')];
        yield 'conflict' => ['v2/errors/conflict', ConflictException::class, $create];
        yield 'validation' => ['v2/errors/validation-error', ValidationException::class, $create];
    }

    /**
     * What a PSR-18 client throws is reported as a TransportException, redacted
     * and not chained, as Guzzle's is: a network exception holds the request.
     */
    public function testANetworkFailureIsReportedRedacted(): void
    {
        $refuse = static fn(RequestInterface $request): \Throwable => self::networkFailure(
            sprintf('could not reach the host for %s with %s', (string) $request->getBody(), $request->getHeaderLine('Authorization')),
            $request,
        );
        $client = $this->psr18ClientWith([], ['token' => 'octo_psr18_token'], $refuse);

        try {
            $client->tags()->create(new TagCreate(name: 'Name From The Body', slug: 'slug-from-the-body', type: 'label'));
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('slug-from-the-body', $this->lastRawBody(), 'the body was not sent');
            self::assertNull($e->getPrevious());
            self::assertStringStartsWith('octonomy: the HTTP client failed on POST /api/v2/tags to https://octonomy.test, with ', $e->getMessage());
            self::assertStringContainsString('"could not reach the host for [redacted] with [redacted]"', $e->getMessage());
            self::assertStringNotContainsString('octo_psr18_token', $e->getMessage());
            self::assertStringNotContainsString('slug-from-the-body', $e->getMessage());
        }
    }

    /**
     * The request and its body are built with the PSR-17 factories passed as
     * request_factory and stream_factory, whatever the client.
     *
     * @param \Closure(self, array<string, mixed>): Client $build
     */
    #[DataProvider('bothKindsOfClient')]
    public function testTheInjectedFactoriesBuildTheRequest(\Closure $build): void
    {
        $factory = self::recordingFactory();
        $client = $build($this, ['request_factory' => $factory, 'stream_factory' => $factory]);

        $client->tags()->create(new TagCreate(name: 'Featured', slug: 'featured', type: 'label'));

        self::assertSame([['POST', 'https://octonomy.test/api/v2/tags']], $factory->requests);
        self::assertSame(['{"name":"Featured","slug":"featured","type":"label"}'], $factory->streams);
        self::assertSame('recording-factory', $this->lastRequest()->getHeaderLine(RecordingFactory::MARK));
        self::assertSame('{"name":"Featured","slug":"featured","type":"label"}', $this->lastRawBody());
    }

    /**
     * @return iterable<string, array{\Closure(self, array<string, mixed>): Client}>
     */
    public static function bothKindsOfClient(): iterable
    {
        yield 'a PSR-18 client' => [static fn(self $t, array $config): Client => $t->psr18ClientWith([self::captured('v2/tags/create')], $config)];
        yield 'a Guzzle client' => [static fn(self $t, array $config): Client => $t->clientWith([self::captured('v2/tags/create')], $config)];
    }

    /**
     * A PSR-18 network exception, which holds the request it failed to send.
     */
    private static function networkFailure(string $message, RequestInterface $request): NetworkExceptionInterface
    {
        return new class ($message, $request) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }
}
