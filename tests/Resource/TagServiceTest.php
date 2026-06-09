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
        $client = $this->clientWith([
            self::jsonResponse(201, [
                'id' => 'tag_1',
                'name' => 'Featured',
                'slug' => 'featured',
                'type' => 'label',
                'is_active' => true,
                'usage_count' => 0,
                'created_at' => '2026-06-08T12:00:00Z',
                'updated_at' => '2026-06-08T12:00:00Z',
            ]),
        ]);

        $tag = $client->tags()->create(new TagCreate(name: 'Featured', slug: 'featured', type: 'label'));

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/tags', $request->getUri()->getPath());
        self::assertSame(['name' => 'Featured', 'slug' => 'featured', 'type' => 'label'], $this->lastJsonBody());

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('tag_1', $tag->id);
        self::assertSame(0, $tag->usageCount);
        self::assertSame('2026-06-08', $tag->createdAt->format('Y-m-d'));
    }

    public function testCreateConflictThrows(): void
    {
        $client = $this->clientWith([
            self::jsonResponse(409, ['error' => ['code' => 'conflict', 'message' => 'duplicate slug']]),
        ]);

        $this->expectException(ConflictException::class);
        $client->tags()->create(new TagCreate(name: 'Featured', slug: 'featured', type: 'label'));
    }

    public function testListSendsAllParams(): void
    {
        $client = $this->clientWith([
            self::jsonResponse(200, ['data' => [['id' => 'tag_1']], 'pagination' => ['limit' => 25, 'offset' => 50, 'count' => 1]]),
        ]);

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

        self::assertCount(1, $page->data);
        self::assertSame(1, $page->pagination->count);
    }

    public function testListWithoutParams(): void
    {
        $client = $this->clientWith([
            self::jsonResponse(200, ['data' => [], 'pagination' => ['limit' => 50, 'offset' => 0, 'count' => 0]]),
        ]);

        $page = $client->tags()->list();

        self::assertSame('', $this->lastRequest()->getUri()->getQuery());
        self::assertCount(0, $page->data);
    }

    public function testGetNotFoundThrows(): void
    {
        $client = $this->clientWith([
            self::jsonResponse(404, ['error' => ['code' => 'not_found', 'message' => 'Resource not found.']]),
        ]);

        $this->expectException(NotFoundException::class);
        $client->tags()->get('missing');
    }

    public function testUpdate(): void
    {
        $client = $this->clientWith([self::jsonResponse(200, ['id' => 'tag_1', 'is_active' => false])]);

        $tag = $client->tags()->update('tag_1', new TagUpdate(isActive: false));

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/v1/tags/tag_1', $request->getUri()->getPath());
        self::assertSame(['is_active' => false], $this->lastJsonBody());
        self::assertFalse($tag->isActive);
    }

    public function testDelete(): void
    {
        $client = $this->clientWith([new \GuzzleHttp\Psr7\Response(204)]);

        $client->tags()->delete('tag_1');

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v1/tags/tag_1', $request->getUri()->getPath());
    }
}
