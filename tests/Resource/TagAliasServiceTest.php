<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\ErrorCode;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagAliasCreate;
use Octoverse\Octonomy\Model\TagAliasListParams;
use Octoverse\Octonomy\Model\TagAliasUpdate;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\SdkTestCase;
use Octoverse\Octonomy\Undefined;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TagAliasService, and TagService::listAliases(), on the default surface,
 * api_version v2. The v2 responses were captured inside merchant A's
 * namespace, so the calls that replay them name that namespace too.
 *
 * The error envelopes were captured from the tag endpoints. The SDK maps an
 * error by its status and code alone, so they exercise the alias calls the
 * same way; only the message's resource kind comes from the alias path.
 */
final class TagAliasServiceTest extends SdkTestCase
{
    public function testCreate(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/create')]);
        $row = self::capturedData('v2/tag-aliases/create');

        $alias = $client->aliases()->create(new TagAliasCreate(
            tagId: $row['tag_id'],
            name: 'Staff Pick',
            slug: 'staff-pick',
            applicationId: 'harness-app',
            metadata: ['source' => 'editorial'],
        ), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v2/tag-aliases', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('merchant', $request->getHeaderLine('X-Namespace-Type'));
        self::assertSame('harness-merchant-a', $request->getHeaderLine('X-Namespace-ID'));
        self::assertSame([
            'tag_id' => $row['tag_id'],
            'name' => 'Staff Pick',
            'slug' => 'staff-pick',
            'application_id' => 'harness-app',
            'metadata' => ['source' => 'editorial'],
        ], $this->lastJsonBody());

        self::assertInstanceOf(TagAlias::class, $alias);
        self::assertSame($row['id'], $alias->id);
        self::assertSame($row['tenant_id'], $alias->tenantId);
        self::assertSame('harness-app', $alias->applicationId);
        self::assertSame('merchant', $alias->namespaceType);
        self::assertSame('harness-merchant-a', $alias->namespaceId);
        self::assertSame($row['tag_id'], $alias->tagId);
        self::assertSame('Staff Pick', $alias->name);
        self::assertSame('staff-pick', $alias->slug);
        self::assertSame(['source' => 'editorial'], $alias->metadata);
        self::assertTrue($alias->isActive);
        self::assertEquals(new \DateTimeImmutable($row['created_at']), $alias->createdAt);
        self::assertEquals(new \DateTimeImmutable($row['updated_at']), $alias->updatedAt);
    }

    public function testCreateSendsOnlyWhatIsSet(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/create')]);

        $client->aliases()->create(new TagAliasCreate(tagId: 'tag_1', name: 'Staff Pick', slug: 'staff-pick'));
        self::assertSame('{"tag_id":"tag_1","name":"Staff Pick","slug":"staff-pick"}', $this->lastRawBody());
    }

    public function testCreateSendsAnEmptyMetadataAsAnObjectAndIsActive(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/create')]);

        $client->aliases()->create(new TagAliasCreate(
            tagId: 'tag_1',
            name: 'Staff Pick',
            slug: 'staff-pick',
            metadata: [],
            isActive: false,
        ));

        self::assertSame(
            '{"tag_id":"tag_1","name":"Staff Pick","slug":"staff-pick","metadata":{},"is_active":false}',
            $this->lastRawBody(),
        );
    }

    public function testCreateConflictThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/conflict')]);

        $this->expectException(ConflictException::class);
        $client->aliases()->create(new TagAliasCreate(tagId: 'tag_1', name: 'Promo', slug: 'promo', applicationId: 'harness-app'), self::merchantA());
    }

    public function testCreateValidationErrorThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/validation-error')]);

        try {
            $client->aliases()->create(new TagAliasCreate(tagId: 'tag_1', name: '', slug: 'Not A Slug'), self::merchantA());
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame(ErrorCode::VALIDATION, $e->errorCode);
            self::assertArrayHasKey('slug', $e->details);
            self::assertSame('/api/v2/tag-aliases', $e->context->path);
        }
    }

    public function testGetSendsItsScope(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/get')]);
        $row = self::capturedData('v2/tag-aliases/get');

        $alias = $client->aliases()->get($row['id'], 'harness-app', true, self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/v2/tag-aliases/' . $row['id'], $request->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app', 'include_global' => 'true'], $this->lastQuery());
        self::assertSame($row['id'], $alias->id);
        self::assertSame($row['slug'], $alias->slug);
    }

    public function testGetWithoutAScopeSendsNoQuery(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/get')]);

        $client->aliases()->get('alias_1');

        self::assertSame('/api/v2/tag-aliases/alias_1', $this->lastRequest()->getUri()->getPath());
        self::assertSame('', $this->lastRequest()->getUri()->getQuery());
    }

    public function testGetEncodesTheId(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/get')]);

        $client->aliases()->get('a/b c');

        self::assertSame('/api/v2/tag-aliases/a%2Fb%20c', $this->lastRequest()->getUri()->getPath());
    }

    /**
     * The 404 message names the resource the alias path asked for, by kind.
     */
    public function testGetNotFoundThrowsNamingTheAlias(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        try {
            $client->aliases()->get('alias_1', 'harness-app', options: self::merchantA());
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertStringStartsWith('octonomy: tag alias "alias_1" was not found (not_found, HTTP 404).', $e->getMessage());
            self::assertSame('/api/v2/tag-aliases/alias_1', $e->context->path);
        }
    }

    public function testListSendsAllParams(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/list')]);

        $page = $client->aliases()->list(new TagAliasListParams(
            applicationId: 'harness-app',
            includeShared: false,
            isActive: true,
            query: 'pro',
            slug: 'promo',
            tagId: 'tag_1',
            limit: 25,
            offset: 50,
            includeGlobal: false,
        ), self::merchantA());

        self::assertSame('/api/v2/tag-aliases', $this->lastRequest()->getUri()->getPath());
        self::assertSame([
            'application_id' => 'harness-app',
            'include_shared' => 'false',
            'is_active' => 'true',
            'q' => 'pro',
            'slug' => 'promo',
            'tag_id' => 'tag_1',
            'limit' => '25',
            'offset' => '50',
            'include_global' => 'false',
        ], $this->lastQuery());

        $rows = self::capturedData('v2/tag-aliases/list');
        self::assertSame(array_column($rows, 'slug'), array_map(static fn(TagAlias $a): string => $a->slug, $page->data));
        self::assertSame(count($rows), $page->pagination->count);
    }

    public function testListWithoutParams(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/list')]);

        $client->aliases()->list();

        self::assertSame('', $this->lastRequest()->getUri()->getQuery());
    }

    public function testUpdate(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/update')]);
        $row = self::capturedData('v2/tag-aliases/update');

        $alias = $client->aliases()->update($row['id'], new TagAliasUpdate(name: 'Staff Picks'), 'harness-app', self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/v2/tag-aliases/' . $row['id'], $request->getUri()->getPath());
        // The application scopes the request; it is not a field of the patch.
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame('{"name":"Staff Picks"}', $this->lastRawBody());
        self::assertSame('Staff Picks', $alias->name);
        self::assertEquals(new \DateTimeImmutable($row['updated_at']), $alias->updatedAt);
    }

    /**
     * Each string field has three states (#20), as on TagUpdate. $body is the
     * JSON sent, exactly.
     */
    #[DataProvider('aliasUpdateBodies')]
    public function testUpdateSendsEachFieldInItsState(TagAliasUpdate $input, string $body): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/update')]);

        $client->aliases()->update('alias_1', $input, 'harness-app', self::merchantA());

        self::assertSame($body, $this->lastRawBody());
    }

    /**
     * @return iterable<string, array{TagAliasUpdate, string}>
     */
    public static function aliasUpdateBodies(): iterable
    {
        yield 'nothing set sends an empty object (#46)' => [new TagAliasUpdate(), '{}'];
        yield 'a string field passed Undefined::VALUE is omitted' => [
            new TagAliasUpdate(
                tagId: Undefined::VALUE,
                name: Undefined::VALUE,
                slug: Undefined::VALUE,
                applicationId: Undefined::VALUE,
                isActive: false,
            ),
            '{"is_active":false}',
        ];
        // The server refuses these nulls; the SDK sends them regardless.
        yield 'every string field passed null is sent as null' => [
            new TagAliasUpdate(tagId: null, name: null, slug: null, applicationId: null),
            '{"tag_id":null,"name":null,"slug":null,"application_id":null}',
        ];
        yield 're-pointing the alias at another tag sends the tag alone' => [
            new TagAliasUpdate(tagId: 'tag_2'),
            '{"tag_id":"tag_2"}',
        ];
        yield 'every field passed a value is sent as that value' => [
            new TagAliasUpdate(
                tagId: 'tag_2',
                name: 'Staff Picks',
                slug: 'staff-picks',
                applicationId: 'harness-app',
                metadata: ['source' => 'editorial'],
                isActive: true,
            ),
            '{"tag_id":"tag_2","name":"Staff Picks","slug":"staff-picks","application_id":"harness-app",'
            . '"metadata":{"source":"editorial"},"is_active":true}',
        ];
        yield 'an empty metadata is sent as an object, which empties it' => [
            new TagAliasUpdate(metadata: []),
            '{"metadata":{}}',
        ];
        yield 'metadata and isActive passed null are omitted' => [
            new TagAliasUpdate(name: 'Staff Picks', metadata: null, isActive: null),
            '{"name":"Staff Picks"}',
        ];
    }

    /**
     * An alias's metadata goes back out as the server sent it (#48): the
     * captured list holds an alias whose metadata is {}.
     */
    public function testAnEmptyMetadataReadFromAnAliasRoundTrips(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/list'), self::captured('v2/tag-aliases/update')]);

        $alias = $client->aliases()->list(new TagAliasListParams(applicationId: 'harness-app'), self::merchantA())->data[0];
        self::assertSame([], $alias->metadata);

        $client->aliases()->update($alias->id, new TagAliasUpdate(metadata: $alias->metadata), 'harness-app', self::merchantA());

        self::assertSame('{"metadata":{}}', $this->lastRawBody());
    }

    /**
     * Deletion deactivates the alias; the server answers 204.
     */
    public function testDelete(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-aliases/delete')]);

        $client->aliases()->delete('alias_1', 'harness-app', self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v2/tag-aliases/alias_1', $request->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame('', $this->lastRawBody());
    }

    public function testTagsListAliases(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/aliases')]);
        $rows = self::capturedData('v2/tags/aliases');

        $page = $client->tags()->listAliases('tag_1', new TagAliasListParams(applicationId: 'harness-app', isActive: true), self::merchantA());

        self::assertSame('GET', $this->lastRequest()->getMethod());
        self::assertSame('/api/v2/tags/tag_1/aliases', $this->lastRequest()->getUri()->getPath());
        self::assertSame(['application_id' => 'harness-app', 'is_active' => 'true'], $this->lastQuery());
        self::assertSame(array_column($rows, 'id'), array_map(static fn(TagAlias $a): string => $a->id, $page->data));
        self::assertSame(array_column($rows, 'tag_id'), array_map(static fn(TagAlias $a): string => $a->tagId, $page->data));
    }

    public function testTagsListAliasesWithoutParams(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/aliases')]);

        $client->tags()->listAliases('tag_1');

        self::assertSame('', $this->lastRequest()->getUri()->getQuery());
    }

    /**
     * A tag the request cannot see is a 404, about the tag.
     */
    public function testTagsListAliasesNotFoundThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        $this->expectException(NotFoundException::class);
        $client->tags()->listAliases('missing', new TagAliasListParams(applicationId: 'harness-app'), self::merchantA());
    }

    /**
     * v1 serves the same aliases without the namespace pair, and the model
     * reads it as null.
     */
    public function testV1ReadsTheGlobalAliasWithoutANamespace(): void
    {
        $client = $this->clientWith([self::captured('v1/tag-aliases/get')], ['api_version' => ApiVersion::V1]);
        $row = self::capturedData('v1/tag-aliases/get');

        $alias = $client->aliases()->get($row['id']);

        self::assertSame('/api/v1/tag-aliases/' . $row['id'], $this->lastRequest()->getUri()->getPath());
        self::assertSame($row['slug'], $alias->slug);
        self::assertNull($alias->namespaceType);
        self::assertNull($alias->namespaceId);
    }

    public function testIncludeGlobalIsRefusedOnV1(): void
    {
        $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);

        try {
            $client->aliases()->list(new TagAliasListParams(includeGlobal: true));
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertStringStartsWith('octonomy: include_global requires api_version v2', $e->getMessage());
            $this->assertNothingSent();
        }
    }

    /**
     * The namespace the v2 responses were captured in.
     */
    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
