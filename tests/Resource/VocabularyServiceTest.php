<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyListParams;
use Octoverse\Octonomy\Model\VocabularyUpdate;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\SdkTestCase;
use Octoverse\Octonomy\Undefined;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * VocabularyService on the default surface, api_version v2. Most v2 responses
 * were captured inside merchant A's namespace, so the calls that replay them
 * name that namespace too; list-global was read with none.
 */
final class VocabularyServiceTest extends SdkTestCase
{
    public function testCreate(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/create')]);

        $vocab = $client->vocabularies()->create(new VocabularyCreate(
            name: 'Labels',
            slug: 'labels',
            applicationId: 'harness-app',
            description: 'Merchandising labels',
            metadata: ['owner' => 'catalog'],
        ), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v2/vocabularies', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame([
            'name' => 'Labels',
            'slug' => 'labels',
            'application_id' => 'harness-app',
            'description' => 'Merchandising labels',
            'metadata' => ['owner' => 'catalog'],
        ], $this->lastJsonBody());

        self::assertInstanceOf(Vocabulary::class, $vocab);
        self::assertSame(self::capturedData('v2/vocabularies/create')['id'], $vocab->id);
        self::assertSame('harness-merchant-a', $vocab->namespaceId);
        self::assertTrue($vocab->isActive);
    }

    public function testGet(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/get')]);

        $vocab = $client->vocabularies()->get('voc_1', 'harness-app', options: self::merchantA());

        self::assertSame('/api/v2/vocabularies/voc_1', $this->lastRequest()->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame(self::capturedData('v2/vocabularies/get')['id'], $vocab->id);
        self::assertSame('labels', $vocab->slug);
    }

    public function testListDecodesEnvelope(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/list-global')]);

        $page = $client->vocabularies()->list(new VocabularyListParams(
            includeShared: true,
            limit: 10,
            offset: 20,
        ));

        $request = $this->lastRequest();
        self::assertSame('/api/v2/vocabularies', $request->getUri()->getPath());
        // The global partition is the absence of a namespace, not a value of it.
        self::assertFalse($request->hasHeader('X-Namespace-Type'));
        self::assertFalse($request->hasHeader('X-Namespace-ID'));
        self::assertSame(['include_shared' => 'true', 'limit' => '10', 'offset' => '20'], $this->lastQuery());

        $rows = self::capturedData('v2/vocabularies/list-global');
        self::assertCount(count($rows), $page->data);
        self::assertSame(array_column($rows, 'id'), array_map(static fn(Vocabulary $v): string => $v->id, $page->data));
        self::assertSame(count($rows), $page->pagination->count);
        self::assertNull($page->pagination->next);
    }

    public function testListIncludeGlobalWithinANamespace(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/list-include-global')]);

        $page = $client->vocabularies()->list(
            new VocabularyListParams(applicationId: 'harness-app', includeGlobal: true),
            self::merchantA(),
        );

        self::assertSame(['application_id' => 'harness-app', 'include_global' => 'true'], $this->lastQuery());
        self::assertSame('harness-merchant-a', $this->lastRequest()->getHeaderLine('X-Namespace-ID'));
        // The namespace's own rows and the global ones, side by side.
        $namespaces = array_map(static fn(Vocabulary $v): ?string => $v->namespaceId, $page->data);
        self::assertContains('harness-merchant-a', $namespaces);
        self::assertContains(null, $namespaces);
    }

    public function testUpdateSendsOnlyTheFieldsSet(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/update')]);

        $vocab = $client->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(description: 'Labels the merchandising team curates'),
            'harness-app',
            self::merchantA(),
        );

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/v2/vocabularies/voc_1', $request->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame(['description' => 'Labels the merchandising team curates'], $this->lastJsonBody());
        self::assertSame('Labels the merchandising team curates', $vocab->description);
    }

    /**
     * A string field has three states (#20): Undefined::VALUE, its default,
     * omits the key; null sends an explicit null; a string sends the string.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('vocabularyUpdateBodies')]
    public function testUpdateSendsEachFieldInItsState(VocabularyUpdate $input, array $body): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/update')]);

        $client->vocabularies()->update('voc_1', $input, 'harness-app', self::merchantA());

        self::assertSame($body, $this->lastJsonBody());
    }

    /**
     * @return iterable<string, array{VocabularyUpdate, array<string, mixed>}>
     */
    public static function vocabularyUpdateBodies(): iterable
    {
        // isActive keeps each body non-empty, so the omitted keys are the point.
        yield 'every string field left at its default is omitted' => [
            new VocabularyUpdate(isActive: false),
            ['is_active' => false],
        ];
        yield 'a string field passed Undefined::VALUE is omitted' => [
            new VocabularyUpdate(
                name: Undefined::VALUE,
                slug: Undefined::VALUE,
                applicationId: Undefined::VALUE,
                description: Undefined::VALUE,
                isActive: false,
            ),
            ['is_active' => false],
        ];
        // The named argument that skips the fields before it is the call the PHP
        // 7.4 downgrade once crashed on (issue #5).
        yield 'one string field passed null is sent as null, alone' => [
            new VocabularyUpdate(description: null),
            ['description' => null],
        ];
        // The server refuses some of these nulls; the SDK sends them regardless.
        yield 'every string field passed null is sent as null' => [
            new VocabularyUpdate(name: null, slug: null, applicationId: null, description: null),
            ['name' => null, 'slug' => null, 'application_id' => null, 'description' => null],
        ];
        yield 'every field passed a value is sent as that value' => [
            new VocabularyUpdate(
                name: 'Labels',
                slug: 'labels',
                applicationId: 'harness-app',
                description: 'Merchandising labels',
                metadata: ['owner' => 'catalog'],
                isActive: true,
            ),
            [
                'name' => 'Labels',
                'slug' => 'labels',
                'application_id' => 'harness-app',
                'description' => 'Merchandising labels',
                'metadata' => ['owner' => 'catalog'],
                'is_active' => true,
            ],
        ];
        // The server has no null for these two, so null cannot be a third state.
        yield 'metadata and isActive passed null are omitted' => [
            new VocabularyUpdate(name: 'Labels', metadata: null, isActive: null),
            ['name' => 'Labels'],
        ];
    }

    /**
     * An explicit null clears the description, which the server confirmed when
     * the fixture was captured: the same PATCH answered 200 with it cleared.
     */
    public function testUpdateClearsTheDescriptionWithNull(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/update-clear')]);
        $row = self::capturedData('v2/vocabularies/update-clear');

        $vocab = $client->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(description: null),
            'harness-app',
            self::merchantA(),
        );

        self::assertSame('PATCH', $this->lastRequest()->getMethod());
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame(['description' => null], $this->lastJsonBody());

        self::assertNull($vocab->description);
        // Fields the body did not name are untouched.
        self::assertSame($row['name'], $vocab->name);
        self::assertSame($row['metadata'], $vocab->metadata);
    }

    /**
     * An update with nothing set sends {} (#46), not the [] an empty PHP array
     * encodes as, which the server refuses.
     */
    public function testUpdateWithNothingSetSendsAnEmptyObject(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/update')]);

        $client->vocabularies()->update('voc_1', new VocabularyUpdate(), 'harness-app', self::merchantA());

        self::assertSame('{}', $this->lastRawBody());
    }

    /**
     * metadata: [] empties the stored object (#46). It goes out as {}, the JSON
     * object the server requires, and the fixture is the server's answer.
     */
    public function testUpdateEmptiesMetadataWithAnEmptyArray(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/update-empty-metadata')]);

        $vocab = $client->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(metadata: []),
            'harness-app',
            self::merchantA(),
        );

        self::assertSame('{"metadata":{}}', $this->lastRawBody());
        self::assertSame([], $vocab->metadata);
    }

    public function testCreateSendsAnEmptyMetadataAsAnObject(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/create')]);

        $client->vocabularies()->create(new VocabularyCreate(
            name: 'Labels',
            slug: 'labels',
            applicationId: 'harness-app',
            metadata: [],
        ), self::merchantA());

        self::assertSame(
            '{"name":"Labels","slug":"labels","application_id":"harness-app","metadata":{}}',
            $this->lastRawBody(),
        );
    }

    /**
     * Metadata stored as {"0": "a", "1": "b"} reads as [0 => 'a', 1 => 'b'],
     * which json_encode() alone writes as the list ["a", "b"], a 400. The DTOs
     * send metadata as an object whatever its keys, so it goes back out as it
     * was read (#48). The fixture's request is the first update, so its
     * response also answers the second, which sends the same body.
     */
    public function testMetadataKeyedLikeAListGoesBackOutAsAnObject(): void
    {
        $captured = self::captured('v2/vocabularies/update-numeric-metadata');
        $client = $this->clientWith([$captured, $captured, self::captured('v2/vocabularies/create')]);

        $vocab = $client->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(metadata: ['a', 'b']),
            'harness-app',
            self::merchantA(),
        );
        self::assertSame('{"metadata":{"0":"a","1":"b"}}', $this->lastRawBody());
        self::assertSame(['a', 'b'], $vocab->metadata);

        $client->vocabularies()->update($vocab->id, new VocabularyUpdate(metadata: $vocab->metadata), 'harness-app', self::merchantA());
        self::assertSame('{"metadata":{"0":"a","1":"b"}}', $this->lastRawBody());

        $client->vocabularies()->create(new VocabularyCreate(
            name: 'Copy',
            slug: 'copy',
            applicationId: 'harness-app',
            metadata: $vocab->metadata,
        ), self::merchantA());
        self::assertSame(
            '{"name":"Copy","slug":"copy","application_id":"harness-app","metadata":{"0":"a","1":"b"}}',
            $this->lastRawBody(),
        );
    }

    public function testDelete(): void
    {
        $client = $this->clientWith([self::captured('v2/vocabularies/delete')]);

        $client->vocabularies()->delete('voc_1', 'harness-app', self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v2/vocabularies/voc_1', $request->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
    }

    /**
     * The namespace most v2 responses were captured in.
     */
    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
