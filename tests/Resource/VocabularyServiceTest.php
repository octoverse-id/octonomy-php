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
        $client = $this->clientWith([self::captured('v1/vocabularies/create')]);

        $vocab = $client->vocabularies()->create(new VocabularyCreate(name: 'Labels', slug: 'labels'));

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/vocabularies', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['name' => 'Labels', 'slug' => 'labels'], $this->lastJsonBody());

        self::assertInstanceOf(Vocabulary::class, $vocab);
        self::assertSame(self::capturedData('v1/vocabularies/create')['id'], $vocab->id);
        self::assertTrue($vocab->isActive);
    }

    public function testGet(): void
    {
        $client = $this->clientWith([self::captured('v1/vocabularies/get')]);

        $vocab = $client->vocabularies()->get('voc_1');

        self::assertSame('/api/v1/vocabularies/voc_1', $this->lastRequest()->getUri()->getPath());
        self::assertSame(self::capturedData('v1/vocabularies/get')['id'], $vocab->id);
        self::assertSame('labels', $vocab->slug);
    }

    public function testListDecodesEnvelope(): void
    {
        $client = $this->clientWith([self::captured('v1/vocabularies/list')]);

        $page = $client->vocabularies()->list(new VocabularyListParams(
            includeShared: true,
            limit: 10,
            offset: 20,
        ));

        $query = $this->lastQuery();
        self::assertSame('true', $query['include_shared'] ?? null);
        self::assertSame('10', $query['limit'] ?? null);
        self::assertSame('20', $query['offset'] ?? null);

        $rows = self::capturedData('v1/vocabularies/list');
        self::assertCount(count($rows), $page->data);
        self::assertSame(array_column($rows, 'id'), array_map(static fn(Vocabulary $v): string => $v->id, $page->data));
        self::assertSame(count($rows), $page->pagination->count);
        self::assertNull($page->pagination->next);
    }

    public function testUpdateOmitsNullFields(): void
    {
        $client = $this->clientWith([self::captured('v1/vocabularies/update')]);

        $vocab = $client->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(description: 'Labels the merchandising team curates'),
        );

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/v1/vocabularies/voc_1', $request->getUri()->getPath());
        self::assertSame(['description' => 'Labels the merchandising team curates'], $this->lastJsonBody());
        self::assertSame('Labels the merchandising team curates', $vocab->description);
    }

    public function testDelete(): void
    {
        $client = $this->clientWith([self::captured('v1/vocabularies/delete')]);

        $client->vocabularies()->delete('voc_1');

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v1/vocabularies/voc_1', $request->getUri()->getPath());
    }
}
