<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyListParams;
use Octoverse\Octonomy\Model\VocabularyUpdate;
use Octoverse\Octonomy\Tests\SdkTestCase;

final class VocabularyServiceTest extends SdkTestCase
{
    public function testCreate(): void
    {
        $client = $this->clientWith([
            self::jsonResponse(201, ['id' => 'voc_1', 'name' => 'Labels', 'slug' => 'labels', 'is_active' => true]),
        ]);

        $vocab = $client->vocabularies()->create(new VocabularyCreate(name: 'Labels', slug: 'labels'));

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/vocabularies', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['name' => 'Labels', 'slug' => 'labels'], $this->lastJsonBody());

        self::assertInstanceOf(Vocabulary::class, $vocab);
        self::assertSame('voc_1', $vocab->id);
        self::assertTrue($vocab->isActive);
    }

    public function testGet(): void
    {
        $client = $this->clientWith([self::jsonResponse(200, ['id' => 'voc_1', 'name' => 'Labels'])]);

        $vocab = $client->vocabularies()->get('voc_1');

        self::assertSame('/api/v1/vocabularies/voc_1', $this->lastRequest()->getUri()->getPath());
        self::assertSame('voc_1', $vocab->id);
    }

    public function testListDecodesEnvelope(): void
    {
        $client = $this->clientWith([
            self::jsonResponse(200, [
                'data' => [['id' => 'voc_1'], ['id' => 'voc_2']],
                'pagination' => ['limit' => 10, 'offset' => 20, 'count' => 2, 'next' => null, 'previous' => null],
            ]),
        ]);

        $page = $client->vocabularies()->list(new VocabularyListParams(
            includeShared: true,
            limit: 10,
            offset: 20,
        ));

        $query = $this->lastQuery();
        self::assertSame('true', $query['include_shared'] ?? null);
        self::assertSame('10', $query['limit'] ?? null);
        self::assertSame('20', $query['offset'] ?? null);

        self::assertCount(2, $page->data);
        self::assertSame('voc_1', $page->data[0]->id);
        self::assertSame(2, $page->pagination->count);
        self::assertNull($page->pagination->next);
    }

    public function testUpdateOmitsNullFields(): void
    {
        $client = $this->clientWith([self::jsonResponse(200, ['id' => 'voc_1', 'name' => 'Renamed'])]);

        $vocab = $client->vocabularies()->update('voc_1', new VocabularyUpdate(name: 'Renamed'));

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/v1/vocabularies/voc_1', $request->getUri()->getPath());
        self::assertSame(['name' => 'Renamed'], $this->lastJsonBody());
        self::assertSame('Renamed', $vocab->name);
    }

    public function testDelete(): void
    {
        $client = $this->clientWith([new \GuzzleHttp\Psr7\Response(204)]);

        $client->vocabularies()->delete('voc_1');

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v1/vocabularies/voc_1', $request->getUri()->getPath());
    }
}
