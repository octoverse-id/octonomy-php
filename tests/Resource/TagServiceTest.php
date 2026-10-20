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
        $client->tags()->get('missing', self::merchantA());
    }

    public function testUpdate(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/update')]);

        $tag = $client->tags()->update('tag_1', new TagUpdate(
            description: 'Hand-picked by the merchandising team',
            metadata: ['color' => 'red', 'rank' => 1],
        ), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/v2/tags/tag_1', $request->getUri()->getPath());
        self::assertSame([
            'description' => 'Hand-picked by the merchandising team',
            'metadata' => ['color' => 'red', 'rank' => 1],
        ], $this->lastJsonBody());
        self::assertSame('Hand-picked by the merchandising team', $tag->description);
        self::assertSame(['color' => 'red', 'rank' => 1], $tag->metadata);
    }

    public function testDelete(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/delete')]);

        $client->tags()->delete('tag_1', self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v2/tags/tag_1', $request->getUri()->getPath());
    }

    /**
     * The namespace the v2 responses were captured in.
     */
    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
