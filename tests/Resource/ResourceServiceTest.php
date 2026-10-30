<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\Model\ResourceTag;
use Octoverse\Octonomy\Model\ResourceTagListParams;
use Octoverse\Octonomy\Model\ResourceTagsReplace;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagResource;
use Octoverse\Octonomy\Model\TagResourceListParams;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use Octoverse\Octonomy\Tests\SdkTestCase;

/**
 * The tags on a resource (ResourceService::listTags(), replaceTags()) and the
 * resources a tag is on (TagService::listResources()), on the default surface,
 * api_version v2. The v2 responses were captured inside merchant A's
 * namespace, so the calls that replay them name that namespace too.
 *
 * The error envelopes were captured from the tag endpoints. The SDK maps an
 * error by its status and code alone, so they exercise these calls the same
 * way.
 */
final class ResourceServiceTest extends SdkTestCase
{
    public function testListTags(): void
    {
        $client = $this->clientWith([self::captured('v2/resources/tags')]);
        $rows = self::capturedData('v2/resources/tags');

        $page = $client->resources()->listTags('product', 'sku-001', 'harness-app', options: self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/v2/resources/product/sku-001/tags', $request->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());

        self::assertCount(count($rows), $page->data);
        self::assertSame(count($rows), $page->pagination->count);
        foreach ($page->data as $i => $resourceTag) {
            self::assertInstanceOf(ResourceTag::class, $resourceTag);
            self::assertSame($rows[$i]['assignment_id'], $resourceTag->assignmentId);
            self::assertSame('merchant', $resourceTag->namespaceType);
            self::assertSame('harness-merchant-a', $resourceTag->namespaceId);
            self::assertSame($rows[$i]['assigned_by'], $resourceTag->assignedBy);
            self::assertEquals(new \DateTimeImmutable($rows[$i]['assigned_at']), $resourceTag->assignedAt);
            self::assertInstanceOf(Tag::class, $resourceTag->tag);
            self::assertSame($rows[$i]['tag']['id'], $resourceTag->tag->id);
            self::assertSame($rows[$i]['tag']['slug'], $resourceTag->tag->slug);
        }
    }

    public function testListTagsSendsAllParams(): void
    {
        $client = $this->clientWith([self::captured('v2/resources/tags')]);

        $client->resources()->listTags('product', 'sku-001', 'harness-app', new ResourceTagListParams(
            includeInactive: true,
            type: 'label',
            limit: 10,
            offset: 20,
            includeGlobal: true,
        ), self::merchantA());

        self::assertSame([
            'application_id' => 'harness-app',
            'include_inactive' => 'true',
            'type' => 'label',
            'limit' => '10',
            'offset' => '20',
            'include_global' => 'true',
        ], $this->lastQuery());
    }

    /**
     * The resource's type and id are path segments, each encoded on its own.
     */
    public function testListTagsEncodesTheResource(): void
    {
        $client = $this->clientWith([self::captured('v2/resources/tags')]);

        $client->resources()->listTags('product', 'sku/001 a', 'harness-app');

        self::assertSame('/api/v2/resources/product/sku%2F001%20a/tags', $this->lastRequest()->getUri()->getPath());
    }

    /**
     * A row's tag hydrates as Tag does, so a field it lacks is reported at
     * its path.
     */
    public function testANestedTagFaultIsReportedAtItsPath(): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom('v2/resources/tags', SyntheticNegative::MISSING_FIELD, static function (array $body): array {
            unset($body['data'][1]['tag']['usage_count']);

            return $body;
        })]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('field "data[1].tag.usage_count" is missing');

        $client->resources()->listTags('product', 'sku-001', 'harness-app', options: self::merchantA());
    }

    /**
     * The server requires the application on this read, so a request without
     * one is its 400.
     */
    public function testListTagsValidationErrorThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/validation-error')]);

        $this->expectException(ValidationException::class);
        $client->resources()->listTags('product', 'sku-001', '', options: self::merchantA());
    }

    public function testListTagsNotFoundThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        $this->expectException(NotFoundException::class);
        $client->resources()->listTags('product', 'sku-001', 'harness-app', options: self::merchantA());
    }

    public function testReplaceTags(): void
    {
        $client = $this->clientWith([self::captured('v2/resources/replace')]);
        $data = self::capturedData('v2/resources/replace');

        $result = $client->resources()->replaceTags('product', 'sku-004', new ResourceTagsReplace(
            applicationId: 'harness-app',
            tagIds: [$data['tags'][0]['id']],
            assignedBy: 'octonomy-php-capture',
        ), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v2/resources/product/sku-004/tags', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame([
            'application_id' => 'harness-app',
            'tag_ids' => [$data['tags'][0]['id']],
            'assigned_by' => 'octonomy-php-capture',
        ], $this->lastJsonBody());

        // The summary object the server sends, not the spec's bare array.
        self::assertSame(1, $result->created);
        self::assertSame(1, $result->removed);
        self::assertCount(1, $result->tags);
        self::assertSame($data['tags'][0]['id'], $result->tags[0]->id);
        self::assertSame($data['tags'][0]['usage_count'], $result->tags[0]->usageCount);
    }

    /**
     * Clearing is never a default: tagIds is required, and [] is sent as the
     * empty list it is.
     */
    public function testReplaceTagsSendsAnEmptyListToClear(): void
    {
        $client = $this->clientWith([self::captured('v2/resources/replace')]);

        $client->resources()->replaceTags('product', 'sku-004', new ResourceTagsReplace('harness-app', []));
        self::assertSame('{"application_id":"harness-app","tag_ids":[]}', $this->lastRawBody());
    }

    public function testReplaceTagsSendsAliasSlugsAsAList(): void
    {
        $client = $this->clientWith([self::captured('v2/resources/replace')]);

        $client->resources()->replaceTags('product', 'sku-004', new ResourceTagsReplace(
            'harness-app',
            [1 => 'tag_1'],
            ['x' => 'promo'],
        ));

        self::assertSame(
            '{"application_id":"harness-app","tag_ids":["tag_1"],"alias_slugs":["promo"]}',
            $this->lastRawBody(),
        );
    }

    public function testReplaceTagsConflictThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/conflict')]);

        $this->expectException(ConflictException::class);
        $client->resources()->replaceTags('product', 'sku-004', new ResourceTagsReplace('harness-app', ['tag_1']), self::merchantA());
    }

    public function testReplaceTagsValidationErrorThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/validation-error')]);

        $this->expectException(ValidationException::class);
        $client->resources()->replaceTags('product', 'sku-004', new ResourceTagsReplace('harness-app', ['not-a-uuid']), self::merchantA());
    }

    public function testReplaceTagsReportsATagFaultAtItsPlace(): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom('v2/resources/replace', SyntheticNegative::WRONG_TYPE, static function (array $body): array {
            $body['data']['tags'][0]['is_active'] = 'true';

            return $body;
        })]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('field "data.tags[0].is_active" must be a boolean, got a string');

        $client->resources()->replaceTags('product', 'sku-004', new ResourceTagsReplace('harness-app', ['tag_1']), self::merchantA());
    }

    public function testTagsListResources(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/resources')]);
        $rows = self::capturedData('v2/tags/resources');

        $page = $client->tags()->listResources('tag_1', new TagResourceListParams(
            applicationId: 'harness-app',
            resourceType: 'product',
            limit: 5,
            offset: 0,
            includeGlobal: false,
        ), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/v2/tags/tag_1/resources', $request->getUri()->getPath());
        self::assertSame([
            'application_id' => 'harness-app',
            'resource_type' => 'product',
            'limit' => '5',
            'offset' => '0',
            'include_global' => 'false',
        ], $this->lastQuery());

        self::assertCount(count($rows), $page->data);
        $resource = $page->data[0];
        self::assertInstanceOf(TagResource::class, $resource);
        self::assertSame($rows[0]['application_id'], $resource->applicationId);
        self::assertSame('merchant', $resource->namespaceType);
        self::assertSame('harness-merchant-a', $resource->namespaceId);
        self::assertSame($rows[0]['resource_type'], $resource->resourceType);
        self::assertSame($rows[0]['resource_id'], $resource->resourceId);
        self::assertSame($rows[0]['assigned_by'], $resource->assignedBy);
        self::assertEquals(new \DateTimeImmutable($rows[0]['assigned_at']), $resource->assignedAt);
    }

    public function testTagsListResourcesWithoutParams(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/resources')]);

        $client->tags()->listResources('tag_1');

        self::assertSame('', $this->lastRequest()->getUri()->getQuery());
    }

    /**
     * A tag the request cannot see is a 404, about the tag.
     */
    public function testTagsListResourcesNotFoundThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        $this->expectException(NotFoundException::class);
        $client->tags()->listResources('missing', new TagResourceListParams(applicationId: 'harness-app'), self::merchantA());
    }

    /**
     * v1 sends no namespace pair on the row or on its tag, and both read null.
     */
    public function testV1ListsTagsWithoutANamespace(): void
    {
        $client = $this->clientWith([self::captured('v1/resources/tags')], ['api_version' => ApiVersion::V1]);

        $page = $client->resources()->listTags('product', 'sku-001', 'harness-app');

        self::assertSame('/api/v1/resources/product/sku-001/tags', $this->lastRequest()->getUri()->getPath());
        self::assertNotEmpty($page->data);
        foreach ($page->data as $row) {
            self::assertNull($row->namespaceType);
            self::assertNull($row->tag->namespaceId);
        }
    }

    public function testIncludeGlobalIsRefusedOnV1(): void
    {
        $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('octonomy: include_global requires api_version v2');

        $client->resources()->listTags('product', 'sku-001', 'harness-app', new ResourceTagListParams(includeGlobal: true));
    }

    /**
     * The namespace the v2 responses were captured in.
     */
    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
