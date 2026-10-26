<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\SdkTestCase;
use Octoverse\Octonomy\Undefined;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TagService on the default surface, api_version v2. The v2 responses were
 * captured inside merchant A's namespace, so the calls that replay them name
 * that namespace too.
 */
final class TagServiceTest extends SdkTestCase
{
    public function testCreate(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/create')]);
        $row = self::capturedData('v2/tags/create');

        $tag = $client->tags()->create(new TagCreate(
            name: 'Featured',
            slug: 'featured',
            type: 'label',
            applicationId: 'harness-app',
            description: 'Hand-picked items',
            parentId: $row['parent_id'],
            vocabularyId: $row['vocabulary_id'],
            metadata: ['color' => 'red'],
        ), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v2/tags', $request->getUri()->getPath());
        self::assertSame('merchant', $request->getHeaderLine('X-Namespace-Type'));
        self::assertSame('harness-merchant-a', $request->getHeaderLine('X-Namespace-ID'));
        self::assertSame([
            'name' => 'Featured',
            'slug' => 'featured',
            'type' => 'label',
            'application_id' => 'harness-app',
            'description' => 'Hand-picked items',
            'parent_id' => $row['parent_id'],
            'vocabulary_id' => $row['vocabulary_id'],
            'metadata' => ['color' => 'red'],
        ], $this->lastJsonBody());

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame($row['id'], $tag->id);
        self::assertSame('Featured', $tag->name);
        self::assertSame('merchant', $tag->namespaceType);
        self::assertSame('harness-merchant-a', $tag->namespaceId);
        // A new tag is in use nowhere yet.
        self::assertSame(0, $tag->usageCount);
        self::assertEquals(new \DateTimeImmutable($row['created_at']), $tag->createdAt);
    }

    public function testCreateConflictThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/conflict')]);

        $this->expectException(ConflictException::class);
        $client->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label', applicationId: 'harness-app'),
            self::merchantA(),
        );
    }

    public function testListSendsAllParams(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/list')]);

        $page = $client->tags()->list(new TagListParams(
            applicationId: 'commerce',
            includeShared: true,
            isActive: false,
            parentId: 'tag_parent',
            query: 'promo',
            slug: 'sale',
            type: 'label',
            vocabularyId: 'voc_1',
            limit: 25,
            offset: 50,
            includeGlobal: true,
        ), self::merchantA());

        self::assertSame('/api/v2/tags', $this->lastRequest()->getUri()->getPath());
        self::assertSame([
            'application_id' => 'commerce',
            'include_shared' => 'true',
            'is_active' => 'false',
            'parent_id' => 'tag_parent',
            'q' => 'promo',
            'slug' => 'sale',
            'type' => 'label',
            'vocabulary_id' => 'voc_1',
            'limit' => '25',
            'offset' => '50',
            'include_global' => 'true',
        ], $this->lastQuery());

        $rows = self::capturedData('v2/tags/list');
        self::assertCount(count($rows), $page->data);
        self::assertSame(array_column($rows, 'slug'), array_map(static fn(Tag $t): string => $t->slug, $page->data));
        self::assertSame(count($rows), $page->pagination->count);
    }

    public function testListWithoutParams(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/list-empty')]);

        $page = $client->tags()->list();

        self::assertSame('', $this->lastRequest()->getUri()->getQuery());
        self::assertCount(0, $page->data);
        self::assertSame(0, $page->pagination->count);
    }

    public function testGetNotFoundThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        $this->expectException(NotFoundException::class);
        $client->tags()->get('missing', 'harness-app', options: self::merchantA());
    }

    public function testUpdate(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/update')]);

        $tag = $client->tags()->update('tag_1', new TagUpdate(
            description: 'Hand-picked by the merchandising team',
            metadata: ['color' => 'red', 'rank' => 1],
        ), 'harness-app', self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/v2/tags/tag_1', $request->getUri()->getPath());
        // The application scopes the request; it is not a field of the patch.
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame([
            'description' => 'Hand-picked by the merchandising team',
            'metadata' => ['color' => 'red', 'rank' => 1],
        ], $this->lastJsonBody());
        self::assertSame('Hand-picked by the merchandising team', $tag->description);
        self::assertSame(['color' => 'red', 'rank' => 1], $tag->metadata);
    }

    /**
     * A string field has three states (#20): Undefined::VALUE, its default,
     * omits the key; null sends an explicit null; a string sends the string.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('tagUpdateBodies')]
    public function testUpdateSendsEachFieldInItsState(TagUpdate $input, array $body): void
    {
        $client = $this->clientWith([self::captured('v2/tags/update')]);

        $client->tags()->update('tag_1', $input, 'harness-app', self::merchantA());

        self::assertSame($body, $this->lastJsonBody());
    }

    /**
     * @return iterable<string, array{TagUpdate, array<string, mixed>}>
     */
    public static function tagUpdateBodies(): iterable
    {
        // isActive keeps each body non-empty, so the omitted keys are the point.
        yield 'every string field left at its default is omitted' => [
            new TagUpdate(isActive: false),
            ['is_active' => false],
        ];
        yield 'a string field passed Undefined::VALUE is omitted' => [
            new TagUpdate(
                name: Undefined::VALUE,
                slug: Undefined::VALUE,
                type: Undefined::VALUE,
                applicationId: Undefined::VALUE,
                description: Undefined::VALUE,
                parentId: Undefined::VALUE,
                vocabularyId: Undefined::VALUE,
                isActive: false,
            ),
            ['is_active' => false],
        ];
        // The named argument that skips the fields before it is the call the PHP
        // 7.4 downgrade once crashed on (issue #5).
        yield 'one string field passed null is sent as null, alone' => [
            new TagUpdate(parentId: null),
            ['parent_id' => null],
        ];
        // The server refuses some of these nulls; the SDK sends them regardless.
        yield 'every string field passed null is sent as null' => [
            new TagUpdate(
                name: null,
                slug: null,
                type: null,
                applicationId: null,
                description: null,
                parentId: null,
                vocabularyId: null,
            ),
            [
                'name' => null,
                'slug' => null,
                'type' => null,
                'application_id' => null,
                'description' => null,
                'parent_id' => null,
                'vocabulary_id' => null,
            ],
        ];
        yield 'every field passed a value is sent as that value' => [
            new TagUpdate(
                name: 'Featured',
                slug: 'featured',
                type: 'label',
                applicationId: 'harness-app',
                description: 'Hand-picked items',
                parentId: 'tag_parent',
                vocabularyId: 'voc_1',
                metadata: ['color' => 'red'],
                isActive: true,
            ),
            [
                'name' => 'Featured',
                'slug' => 'featured',
                'type' => 'label',
                'application_id' => 'harness-app',
                'description' => 'Hand-picked items',
                'parent_id' => 'tag_parent',
                'vocabulary_id' => 'voc_1',
                'metadata' => ['color' => 'red'],
                'is_active' => true,
            ],
        ];
        // The server has no null for these two, so null cannot be a third state.
        yield 'metadata and isActive passed null are omitted' => [
            new TagUpdate(name: 'Featured', metadata: null, isActive: null),
            ['name' => 'Featured'],
        ];
    }

    /**
     * An explicit null clears a field, which the server confirmed when the
     * fixture was captured: the same PATCH answered 200 with all three cleared.
     */
    public function testUpdateClearsNullableFieldsWithNull(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/update-clear')]);
        $row = self::capturedData('v2/tags/update-clear');

        $tag = $client->tags()->update('tag_1', new TagUpdate(
            description: null,
            parentId: null,
            vocabularyId: null,
        ), 'harness-app', self::merchantA());

        self::assertSame('PATCH', $this->lastRequest()->getMethod());
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame([
            'description' => null,
            'parent_id' => null,
            'vocabulary_id' => null,
        ], $this->lastJsonBody());

        self::assertNull($tag->description);
        self::assertNull($tag->parentId);
        self::assertNull($tag->vocabularyId);
        // Fields the body did not name are untouched.
        self::assertSame($row['name'], $tag->name);
        self::assertSame($row['metadata'], $tag->metadata);
    }

    /**
     * An update with nothing set sends {} (#46), which the server answers with
     * 200, changing nothing: the fixture is that exchange. Encoded as is, the
     * empty PHP array would be [], a list the server refuses.
     */
    public function testUpdateWithNothingSetSendsAnEmptyObject(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/update-nothing')]);
        $row = self::capturedData('v2/tags/update-nothing');

        $tag = $client->tags()->update('tag_1', new TagUpdate(), 'harness-app', self::merchantA());

        self::assertSame('PATCH', $this->lastRequest()->getMethod());
        self::assertSame('application/json', $this->lastRequest()->getHeaderLine('Content-Type'));
        self::assertSame('{}', $this->lastRawBody());
        self::assertSame($row['name'], $tag->name);
    }

    /**
     * metadata: [] empties the stored object (#46). It goes out as {}, the JSON
     * object the server requires, and the fixture is the server's answer.
     */
    public function testUpdateEmptiesMetadataWithAnEmptyArray(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/update-empty-metadata')]);

        $tag = $client->tags()->update('tag_1', new TagUpdate(metadata: []), 'harness-app', self::merchantA());

        self::assertSame('{"metadata":{}}', $this->lastRawBody());
        self::assertSame([], $tag->metadata);
    }

    public function testCreateSendsAnEmptyMetadataAsAnObject(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/create')]);

        $client->tags()->create(new TagCreate(
            name: 'Featured',
            slug: 'featured',
            type: 'label',
            applicationId: 'harness-app',
            metadata: [],
        ), self::merchantA());

        self::assertSame(
            '{"name":"Featured","slug":"featured","type":"label","application_id":"harness-app","metadata":{}}',
            $this->lastRawBody(),
        );
    }

    /**
     * Only an empty metadata itself becomes {}: an empty array inside it stays
     * the JSON list it encodes as, and a list keeps its order.
     */
    public function testMetadataKeepsTheListsInsideIt(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/update')]);

        $client->tags()->update(
            'tag_1',
            new TagUpdate(metadata: ['labels' => [], 'ranks' => [2, 1]]),
            'harness-app',
            self::merchantA(),
        );

        self::assertSame('{"metadata":{"labels":[],"ranks":[2,1]}}', $this->lastRawBody());
    }

    /**
     * A tag's empty metadata hydrates to [] and goes back out as {}, so it
     * round-trips into an update unchanged.
     */
    public function testAnEmptyMetadataReadFromATagRoundTrips(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/list'), self::captured('v2/tags/update')]);

        $empty = array_values(array_filter(
            $client->tags()->list(new TagListParams(applicationId: 'harness-app'), self::merchantA())->data,
            static fn(Tag $tag): bool => $tag->metadata === [],
        ));
        self::assertNotEmpty($empty, 'v2/tags/list must hold a tag with empty metadata for this test to mean anything');

        $client->tags()->update($empty[0]->id, new TagUpdate(metadata: $empty[0]->metadata), 'harness-app', self::merchantA());

        self::assertSame('{"metadata":{}}', $this->lastRawBody());
    }

    public function testDelete(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/delete')]);

        $client->tags()->delete('tag_1', 'harness-app', self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v2/tags/tag_1', $request->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
    }

    public function testGetSendsItsScope(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')]);

        $tag = $client->tags()->get('tag_1', 'harness-app', true, self::merchantA());

        self::assertSame('/api/v2/tags/tag_1', $this->lastRequest()->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app', 'include_global' => 'true'], $this->lastQuery());
        self::assertSame(self::capturedData('v2/tags/get')['id'], $tag->id);
    }

    public function testADetailCallWithoutAScopeSendsNoQuery(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get'), self::captured('v2/tags/delete')]);

        $client->tags()->get('tag_1');
        $get = $this->lastRequest();
        $client->tags()->delete('tag_1');
        $delete = $this->lastRequest();

        self::assertSame(['GET', ''], [$get->getMethod(), $get->getUri()->getQuery()]);
        self::assertSame(['DELETE', ''], [$delete->getMethod(), $delete->getUri()->getQuery()]);
    }

    /**
     * The namespace the v2 responses were captured in.
     */
    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
