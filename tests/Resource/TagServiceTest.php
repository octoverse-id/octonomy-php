<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\Tests\SdkTestCase;

final class TagServiceTest extends SdkTestCase
{
    public function testCreate(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/create')]);

        $tag = $client->tags()->create(new TagCreate(
            name: 'Featured',
            slug: 'featured',
            type: 'label',
            applicationId: 'harness-app',
            description: 'Hand-picked items',
            metadata: ['color' => 'red'],
        ));

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/tags', $request->getUri()->getPath());
        self::assertSame([
            'name' => 'Featured',
            'slug' => 'featured',
            'type' => 'label',
            'application_id' => 'harness-app',
            'description' => 'Hand-picked items',
            'metadata' => ['color' => 'red'],
        ], $this->lastJsonBody());

        $row = self::capturedData('v1/tags/create');
        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame($row['id'], $tag->id);
        self::assertSame('Featured', $tag->name);
        // A new tag is in use nowhere yet.
        self::assertSame(0, $tag->usageCount);
        self::assertEquals(new \DateTimeImmutable($row['created_at']), $tag->createdAt);
    }

    public function testCreateConflictThrows(): void
    {
        $client = $this->clientWith([self::captured('v1/errors/conflict')]);

        $this->expectException(ConflictException::class);
        $client->tags()->create(new TagCreate(name: 'Featured', slug: 'featured', type: 'label'));
    }

    public function testListSendsAllParams(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/list')]);

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
        ));

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
        ], $this->lastQuery());

        $rows = self::capturedData('v1/tags/list');
        self::assertCount(count($rows), $page->data);
        self::assertSame(array_column($rows, 'slug'), array_map(static fn(Tag $t): string => $t->slug, $page->data));
        self::assertSame(count($rows), $page->pagination->count);
    }

    public function testListWithoutParams(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/list-empty')]);

        $page = $client->tags()->list();

        self::assertSame('', $this->lastRequest()->getUri()->getQuery());
        self::assertCount(0, $page->data);
        self::assertSame(0, $page->pagination->count);
    }

    public function testGetNotFoundThrows(): void
    {
        $client = $this->clientWith([self::captured('v1/errors/not-found')]);

        $this->expectException(NotFoundException::class);
        $client->tags()->get('missing');
    }

    public function testUpdate(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/update')]);

        $tag = $client->tags()->update('tag_1', new TagUpdate(
            description: 'Hand-picked by the merchandising team',
            metadata: ['color' => 'red', 'rank' => 1],
        ));

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/v1/tags/tag_1', $request->getUri()->getPath());
        self::assertSame([
            'description' => 'Hand-picked by the merchandising team',
            'metadata' => ['color' => 'red', 'rank' => 1],
        ], $this->lastJsonBody());
        self::assertSame('Hand-picked by the merchandising team', $tag->description);
        self::assertSame(['color' => 'red', 'rank' => 1], $tag->metadata);
    }

    public function testDelete(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/delete')]);

        $client->tags()->delete('tag_1');

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v1/tags/tag_1', $request->getUri()->getPath());
    }
}
