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

    public function testUpdateOmitsNullFields(): void
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
