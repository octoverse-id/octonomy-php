<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyListParams;
use Octoverse\Octonomy\Model\VocabularyUpdate;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The namespace axis of api_version v2 (#15): NamespaceScope, its header pair,
 * include_global, and the guard that refuses both on a v1 client before any
 * request is sent. The four guard paths are each feature on each version:
 * namespace and include_global, sent on v2 and refused on v1.
 */
final class NamespaceTest extends SdkTestCase
{
    /**
     * Half a pair names no namespace, so it cannot be built.
     */
    #[DataProvider('incompletePairs')]
    public function testANamespaceIsATypeAndAnIdTogether(string $type, string $id, string $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        new NamespaceScope($type, $id);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function incompletePairs(): iterable
    {
        $noId = 'octonomy: namespace type "merchant" has no id; a namespace is a type and an id together';
        $noType = 'octonomy: a namespace type is required; omit the namespace for the global (tenant-shared) partition';

        yield 'a type without an id' => ['merchant', '', $noId];
        yield 'a type with a blank id' => ['merchant', "  \t", $noId];
        yield 'an id without a type' => ['', 'm-42', $noType];
        yield 'a blank type' => [' ', 'm-42', $noType];
    }

    public function testANamespaceKeepsItsValuesAsGiven(): void
    {
        // Opaque and caller-canonical: the server does not case-fold them.
        $namespace = new NamespaceScope('Merchant', 'M-42');

        self::assertSame('Merchant', $namespace->type);
        self::assertSame('M-42', $namespace->id);
    }

    /**
     * Guard path 1: v2 + namespace sends both headers, on every method.
     *
     * @param \Closure(Client, RequestOptions): mixed $call
     */
    #[DataProvider('calls')]
    public function testAV2RequestCarriesTheNamespacePair(string $operation, \Closure $call): void
    {
        $client = $this->clientWith([self::captured("v2/{$operation}")]);

        $call($client, new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a')));

        $request = $this->lastRequest();
        self::assertSame('merchant', $request->getHeaderLine('X-Namespace-Type'));
        self::assertSame('harness-merchant-a', $request->getHeaderLine('X-Namespace-ID'));
        // The pair rides beside the credentials, not in place of them.
        self::assertSame('tenant-1', $request->getHeaderLine('X-Tenant-ID'));
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    public function testANamespaceAndAnActorTravelTogether(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')]);

        $client->tags()->get('tag_1', new RequestOptions(actorId: 'svc-call', namespace: new NamespaceScope('merchant', 'harness-merchant-a')));

        self::assertSame('svc-call', $this->lastRequest()->getHeaderLine('X-Actor-ID'));
        self::assertSame('harness-merchant-a', $this->lastRequest()->getHeaderLine('X-Namespace-ID'));
    }

    /**
     * A namespace belongs to the one request that names it: the next request on
     * the same client is global again.
     */
    public function testANamespaceDoesNotOutliveItsRequest(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/list-include-global'), self::captured('v2/vocabularies/list-global')]);

        $client->vocabularies()->list(
            new VocabularyListParams(applicationId: 'harness-app', includeGlobal: true),
            new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a')),
        );
        $client->vocabularies()->list();

        self::assertFalse($this->lastRequest()->hasHeader('X-Namespace-Type'));
        self::assertFalse($this->lastRequest()->hasHeader('X-Namespace-ID'));
    }

    /**
     * Guard path 2: v1 + namespace throws, on every method, and nothing is sent.
     *
     * @param \Closure(Client, RequestOptions): mixed $call
     */
    #[DataProvider('calls')]
    public function testAV1ClientRefusesANamespaceBeforeSending(string $operation, \Closure $call): void
    {
        $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);

        try {
            $call($client, new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a')));
            self::fail("expected {$operation} to be refused");
        } catch (\LogicException $e) {
            self::assertSame('octonomy: a namespace requires api_version v2; this client is v1', $e->getMessage());
        }
        $this->assertNothingSent();
    }

    /**
     * Guard path 3: v2 + include_global sends it, true or false.
     *
     * @param \Closure(Client, bool): mixed $list
     */
    #[DataProvider('includeGlobalLists')]
    public function testAV2ListSendsIncludeGlobal(string $operation, \Closure $list): void
    {
        foreach (['true' => true, 'false' => false] as $sent => $includeGlobal) {
            $client = $this->clientWith([self::captured("v2/{$operation}")]);

            $list($client, $includeGlobal);

            self::assertSame($sent, $this->lastQuery()['include_global'] ?? null);
        }
    }

    /**
     * Guard path 4: v1 + include_global throws, and nothing is sent. False is
     * refused too: setting it at all asks for a v2 feature, and /api/v1 would
     * drop it without a word either way.
     *
     * @param \Closure(Client, bool): mixed $list
     */
    #[DataProvider('includeGlobalLists')]
    public function testAV1ClientRefusesIncludeGlobalBeforeSending(string $operation, \Closure $list): void
    {
        foreach ([true, false] as $includeGlobal) {
            $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);

            try {
                $list($client, $includeGlobal);
                self::fail("expected {$operation} to be refused");
            } catch (\LogicException $e) {
                self::assertSame('octonomy: include_global requires api_version v2; this client is v1', $e->getMessage());
            }
            $this->assertNothingSent();
        }
    }

    /**
     * The same v1 client still sends a request that uses neither feature.
     */
    public function testAV1ClientSendsAGlobalRequest(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/list')], ['api_version' => ApiVersion::V1]);

        $client->tags()->list(new TagListParams(includeShared: true));

        self::assertSame('/api/v1/tags', $this->lastRequest()->getUri()->getPath());
        self::assertFalse($this->lastRequest()->hasHeader('X-Namespace-Type'));
    }

    /**
     * Every service method, keyed by the captured operation that answers it.
     *
     * @return iterable<string, array{string, \Closure(Client, RequestOptions): mixed}>
     */
    public static function calls(): iterable
    {
        yield 'tags()->create()' => ['tags/create', static fn(Client $c, RequestOptions $o): mixed => $c->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label', applicationId: 'harness-app'),
            $o,
        )];
        yield 'tags()->get()' => ['tags/get', static fn(Client $c, RequestOptions $o): mixed => $c->tags()->get('tag_1', $o)];
        yield 'tags()->list()' => ['tags/list', static fn(Client $c, RequestOptions $o): mixed => $c->tags()->list(
            new TagListParams(applicationId: 'harness-app'),
            $o,
        )];
        yield 'tags()->update()' => ['tags/update', static fn(Client $c, RequestOptions $o): mixed => $c->tags()->update(
            'tag_1',
            new TagUpdate(description: 'Hand-picked by the merchandising team'),
            $o,
        )];
        yield 'tags()->delete()' => ['tags/delete', static function (Client $c, RequestOptions $o): mixed {
            $c->tags()->delete('tag_1', $o);

            return null;
        }];
        yield 'vocabularies()->create()' => ['vocabularies/create', static fn(Client $c, RequestOptions $o): mixed => $c->vocabularies()->create(
            new VocabularyCreate(name: 'Labels', slug: 'labels', applicationId: 'harness-app'),
            $o,
        )];
        yield 'vocabularies()->get()' => ['vocabularies/get', static fn(Client $c, RequestOptions $o): mixed => $c->vocabularies()->get('voc_1', $o)];
        yield 'vocabularies()->list()' => ['vocabularies/list', static fn(Client $c, RequestOptions $o): mixed => $c->vocabularies()->list(
            new VocabularyListParams(applicationId: 'harness-app'),
            $o,
        )];
        yield 'vocabularies()->update()' => ['vocabularies/update', static fn(Client $c, RequestOptions $o): mixed => $c->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(description: 'Labels the merchandising team curates'),
            $o,
        )];
        yield 'vocabularies()->delete()' => ['vocabularies/delete', static function (Client $c, RequestOptions $o): mixed {
            $c->vocabularies()->delete('voc_1', $o);

            return null;
        }];
    }

    /**
     * The list methods that take include_global, keyed by the captured
     * operation that answers them.
     *
     * @return iterable<string, array{string, \Closure(Client, bool): mixed}>
     */
    public static function includeGlobalLists(): iterable
    {
        yield 'tags()->list()' => ['tags/list', static fn(Client $c, bool $g): mixed => $c->tags()->list(
            new TagListParams(applicationId: 'harness-app', includeGlobal: $g),
        )];
        yield 'vocabularies()->list()' => ['vocabularies/list-include-global', static fn(Client $c, bool $g): mixed => $c->vocabularies()->list(
            new VocabularyListParams(applicationId: 'harness-app', includeGlobal: $g),
        )];
    }
}
