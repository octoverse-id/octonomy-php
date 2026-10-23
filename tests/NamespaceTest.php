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

    /**
     * A header cannot carry a control character, and Guzzle's refusal would be
     * thrown from frames that hold the token and the body (Redaction).
     */
    #[DataProvider('unsendablePairs')]
    public function testANamespaceAHeaderCannotCarryIsRefused(string $type, string $id, string $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        new NamespaceScope($type, $id);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unsendablePairs(): iterable
    {
        yield 'a type with a newline' => [
            "merchant\n",
            'm-42',
            'octonomy: the namespace type holds a line break or another control character, which the X-Namespace-Type header cannot carry;',
        ];
        yield 'an id with a carriage return' => [
            'merchant',
            "m-42\r",
            'octonomy: the namespace id holds a line break or another control character, which the X-Namespace-ID header cannot carry;',
        ];
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
     * Each call also names its application, which the server requires of a
     * namespaced call: in the body on create, in the query everywhere else.
     *
     * @param \Closure(Client, RequestOptions): mixed $call
     * @param array<string, string> $query
     */
    #[DataProvider('calls')]
    public function testAV2RequestCarriesTheNamespacePair(string $operation, \Closure $call, array $query): void
    {
        $client = $this->clientWith([self::captured("v2/{$operation}")]);

        $call($client, new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a')));

        $request = $this->lastRequest();
        self::assertSame('merchant', $request->getHeaderLine('X-Namespace-Type'));
        self::assertSame('harness-merchant-a', $request->getHeaderLine('X-Namespace-ID'));
        self::assertSame($query, $this->lastQuery());
        // The pair rides beside the credentials, not in place of them.
        self::assertSame('tenant-1', $request->getHeaderLine('X-Tenant-ID'));
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    public function testANamespaceAndAnActorTravelTogether(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')]);

        $client->tags()->get('tag_1', 'harness-app', options: new RequestOptions(actorId: 'svc-call', namespace: new NamespaceScope('merchant', 'harness-merchant-a')));

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
     * @param array<string, string> $query
     */
    #[DataProvider('calls')]
    public function testAV1ClientRefusesANamespaceBeforeSending(string $operation, \Closure $call, array $query): void
    {
        $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);

        try {
            $call($client, new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a')));
            self::fail("expected {$operation} to be refused");
        } catch (\LogicException $e) {
            self::assertSame(
                'octonomy: a namespace requires api_version v2; this client is v1, and /api/v1 has no namespaces.'
                . " Fix: build the Client with 'api_version' => 'v2' (Octonomy server 2.0.0 or newer), or leave the"
                . ' namespace out of RequestOptions to use the global partition.',
                $e->getMessage(),
            );
        }
        $this->assertNothingSent();
    }

    /**
     * Guard path 3: v2 + include_global sends it, true or false.
     *
     * @param \Closure(Client, bool): mixed $list
     */
    #[DataProvider('includeGlobalReads')]
    public function testAV2ReadSendsIncludeGlobal(string $operation, \Closure $list): void
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
    #[DataProvider('includeGlobalReads')]
    public function testAV1ClientRefusesIncludeGlobalBeforeSending(string $operation, \Closure $list): void
    {
        foreach ([true, false] as $includeGlobal) {
            $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);

            try {
                $list($client, $includeGlobal);
                self::fail("expected {$operation} to be refused");
            } catch (\LogicException $e) {
                self::assertSame(
                    'octonomy: include_global requires api_version v2; this client is v1, and /api/v1 would ignore it.'
                    . " Fix: build the Client with 'api_version' => 'v2' (Octonomy server 2.0.0 or newer), or leave"
                    . ' include_global null.',
                    $e->getMessage(),
                );
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
     * Every service method, keyed by the captured operation that answers it,
     * with the query it must send.
     *
     * @return iterable<string, array{string, \Closure(Client, RequestOptions): mixed, array<string, string>}>
     */
    public static function calls(): iterable
    {
        $app = ['application_id' => 'harness-app'];

        yield 'tags()->create()' => ['tags/create', static fn(Client $c, RequestOptions $o): mixed => $c->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label', applicationId: 'harness-app'),
            $o,
        ), []];
        yield 'tags()->get()' => ['tags/get', static fn(Client $c, RequestOptions $o): mixed => $c->tags()->get('tag_1', 'harness-app', options: $o), $app];
        yield 'tags()->list()' => ['tags/list', static fn(Client $c, RequestOptions $o): mixed => $c->tags()->list(
            new TagListParams(applicationId: 'harness-app'),
            $o,
        ), $app];
        yield 'tags()->update()' => ['tags/update', static fn(Client $c, RequestOptions $o): mixed => $c->tags()->update(
            'tag_1',
            new TagUpdate(description: 'Hand-picked by the merchandising team'),
            'harness-app',
            $o,
        ), $app];
        yield 'tags()->delete()' => ['tags/delete', static function (Client $c, RequestOptions $o): mixed {
            $c->tags()->delete('tag_1', 'harness-app', $o);

            return null;
        }, $app];
        yield 'vocabularies()->create()' => ['vocabularies/create', static fn(Client $c, RequestOptions $o): mixed => $c->vocabularies()->create(
            new VocabularyCreate(name: 'Labels', slug: 'labels', applicationId: 'harness-app'),
            $o,
        ), []];
        yield 'vocabularies()->get()' => ['vocabularies/get', static fn(Client $c, RequestOptions $o): mixed => $c->vocabularies()->get('voc_1', 'harness-app', options: $o), $app];
        yield 'vocabularies()->list()' => ['vocabularies/list', static fn(Client $c, RequestOptions $o): mixed => $c->vocabularies()->list(
            new VocabularyListParams(applicationId: 'harness-app'),
            $o,
        ), $app];
        yield 'vocabularies()->update()' => ['vocabularies/update', static fn(Client $c, RequestOptions $o): mixed => $c->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(description: 'Labels the merchandising team curates'),
            'harness-app',
            $o,
        ), $app];
        yield 'vocabularies()->delete()' => ['vocabularies/delete', static function (Client $c, RequestOptions $o): mixed {
            $c->vocabularies()->delete('voc_1', 'harness-app', $o);

            return null;
        }, $app];
    }

    /**
     * The reads that take include_global, keyed by the captured operation that
     * answers them.
     *
     * @return iterable<string, array{string, \Closure(Client, bool): mixed}>
     */
    public static function includeGlobalReads(): iterable
    {
        yield 'tags()->get()' => ['tags/get', static fn(Client $c, bool $g): mixed => $c->tags()->get('tag_1', 'harness-app', $g)];
        yield 'vocabularies()->get()' => ['vocabularies/get', static fn(Client $c, bool $g): mixed => $c->vocabularies()->get('voc_1', 'harness-app', $g)];
        yield 'tags()->list()' => ['tags/list', static fn(Client $c, bool $g): mixed => $c->tags()->list(
            new TagListParams(applicationId: 'harness-app', includeGlobal: $g),
        )];
        yield 'vocabularies()->list()' => ['vocabularies/list-include-global', static fn(Client $c, bool $g): mixed => $c->vocabularies()->list(
            new VocabularyListParams(applicationId: 'harness-app', includeGlobal: $g),
        )];
    }
}
