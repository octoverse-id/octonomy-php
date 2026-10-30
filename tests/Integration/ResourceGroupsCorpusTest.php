<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\ErrorCode;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\Model\AssignmentCreate;
use Octoverse\Octonomy\Model\AssignmentRemove;
use Octoverse\Octonomy\Model\AuditLog;
use Octoverse\Octonomy\Model\AuditLogListParams;
use Octoverse\Octonomy\Model\BulkAssign;
use Octoverse\Octonomy\Model\BulkRemove;
use Octoverse\Octonomy\Model\ResourceTag;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagAliasListParams;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagResolution;
use Octoverse\Octonomy\Model\TagResource;
use Octoverse\Octonomy\Model\TagResourceListParams;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ResourceGroupsCorpusTest reads the seeded corpus through the resource groups
 * #23 added: aliases, resolution, assignments, the tags on a resource and the
 * resources of a tag, and the audit log. The corpus is the table at
 * seed_corpus in the harness. Globally: the aliases scarlet (of red, shared)
 * and big (of large, harness-app); red and large on product/sku-001; green,
 * and red through scarlet, on product/sku-002. In merchant A: the alias promo
 * of sale, and red and sale on product/sku-001.
 *
 * Every test only reads, so the suite reruns against one harness. The
 * assignment writes are exercised through calls that change nothing: the
 * server refuses them before writing, or they name an assignment that does not
 * exist, which it answers without writing (no row, no audit entry, no outbox
 * event).
 */
final class ResourceGroupsCorpusTest extends IntegrationTestCase
{
    /** A resource no corpus row names, so removing from it removes nothing. */
    private const ABSENT_RESOURCE = 'octonomy-php-integration-absent';

    public function testGlobalAliasesHydrateFromListAndGet(): void
    {
        $client = $this->harnessClient(ApiVersion::V2);
        $red = $this->tag($client, 'red', 'color');

        $page = $client->aliases()->list();
        $bySlug = self::bySlug($page->data);
        self::assertSame(['big', 'scarlet'], array_keys($bySlug));
        self::assertSame(2, $page->pagination->count);

        $scarlet = $bySlug['scarlet'];
        self::assertSame($red->id, $scarlet->tagId);
        self::assertNull($scarlet->applicationId, 'scarlet is seeded shared, with no application');
        self::assertNull($scarlet->namespaceType);
        self::assertNull($scarlet->namespaceId);
        self::assertSame('Scarlet', $scarlet->name);
        self::assertSame([], $scarlet->metadata);
        self::assertTrue($scarlet->isActive);
        self::assertSame(self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'), $bySlug['big']->applicationId);

        // The {data: {...}} single-resource envelope, against the real server.
        self::assertEquals($scarlet, $client->aliases()->get($scarlet->id));

        $own = $client->tags()->listAliases($red->id)->data;
        self::assertSame(['scarlet'], array_keys(self::bySlug($own)));
    }

    public function testANamespacedAliasListSeesThatNamespaceAlone(): void
    {
        $app = self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID');
        $a = self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID');

        $aliases = $this->harnessClient(ApiVersion::V2)->aliases()->list(new TagAliasListParams(applicationId: $app), self::in($a))->data;

        self::assertSame(['promo'], array_keys(self::bySlug($aliases)));
        self::assertSame('merchant', $aliases[0]->namespaceType);
        self::assertSame($a, $aliases[0]->namespaceId);
    }

    public function testV1ListsTheGlobalAliasesWithoutANamespace(): void
    {
        $aliases = $this->harnessClient(ApiVersion::V1)->aliases()->list()->data;

        self::assertSame(['big', 'scarlet'], array_keys(self::bySlug($aliases)));
        foreach ($aliases as $alias) {
            self::assertNull($alias->namespaceType);
        }
    }

    /**
     * @param \Closure(Client): TagResolution $resolve
     */
    #[DataProvider('resolutions')]
    public function testASlugResolvesToItsTag(string $apiVersion, \Closure $resolve, string $matchedType, ?string $alias, string $tag, ?string $namespace): void
    {
        $resolution = $resolve($this->harnessClient($apiVersion));

        self::assertSame($matchedType, $resolution->matchedType);
        self::assertSame($alias, $resolution->matchedAlias?->slug);
        if ($resolution->matchedAlias !== null) {
            self::assertSame($resolution->tag->id, $resolution->matchedAlias->tagId);
        }
        self::assertSame($tag, $resolution->tag->slug);
        self::assertSame($namespace === null ? null : self::harnessEnv($namespace), $resolution->tag->namespaceId);
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): TagResolution, string, string|null, string, string|null}>
     */
    public static function resolutions(): iterable
    {
        yield 'a global tag\'s own slug' => [
            ApiVersion::V2,
            static fn(Client $c): TagResolution => $c->tags()->resolve('red', type: 'color'),
            TagResolution::MATCHED_TAG, null, 'red', null,
        ];
        yield 'a global alias' => [
            ApiVersion::V2,
            static fn(Client $c): TagResolution => $c->tags()->resolve('scarlet'),
            TagResolution::MATCHED_ALIAS, 'scarlet', 'red', null,
        ];
        yield 'a global alias on v1' => [
            ApiVersion::V1,
            static fn(Client $c): TagResolution => $c->tags()->resolve('scarlet'),
            TagResolution::MATCHED_ALIAS, 'scarlet', 'red', null,
        ];
        // Merchant A's own red wins over the global one inside its namespace.
        yield 'a namespaced tag over the global one' => [
            ApiVersion::V2,
            static fn(Client $c): TagResolution => $c->tags()->resolve(
                'red',
                self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'),
                'color',
                options: self::in(self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID')),
            ),
            TagResolution::MATCHED_TAG, null, 'red', 'OCTONOMY_TEST_NAMESPACE_A_ID',
        ];
        yield 'a namespaced alias' => [
            ApiVersion::V2,
            static fn(Client $c): TagResolution => $c->tags()->resolve(
                'promo',
                self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'),
                options: self::in(self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID')),
            ),
            TagResolution::MATCHED_ALIAS, 'promo', 'sale', 'OCTONOMY_TEST_NAMESPACE_A_ID',
        ];
    }

    public function testASlugThatMatchesNothingIsAValidationException(): void
    {
        try {
            $this->harnessClient(ApiVersion::V2)->tags()->resolve('octonomy-php-no-such-slug');
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(ErrorCode::VALIDATION, $e->errorCode);
            self::assertArrayHasKey('slug', $e->details);
        }
    }

    /**
     * The server parses each body the SDK sends, by the field names the
     * server reads, and refuses it before writing: the error names the field
     * the SDK put the tag in.
     *
     * @param \Closure(Client, string): mixed $write
     */
    #[DataProvider('refusedWrites')]
    public function testAnAssignmentWriteNamingNoTagIsRefusedBeforeWriting(\Closure $write, string $field): void
    {
        try {
            $write($this->harnessClient(ApiVersion::V2), self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'));
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(ErrorCode::VALIDATION, $e->errorCode);
            self::assertArrayHasKey($field, $e->details);
        }
    }

    /**
     * @return iterable<string, array{\Closure(Client, string): mixed, string}>
     */
    public static function refusedWrites(): iterable
    {
        $missing = 'octonomy-php-no-such-alias';
        yield 'create by an unknown tag id' => [
            static fn(Client $c, string $app): mixed => $c->assignments()->create(
                AssignmentCreate::forTag($app, 'product', 'sku-001', '00000000-0000-4000-8000-000000000000'),
            ),
            'tag_id',
        ];
        yield 'create by an unknown alias id' => [
            static fn(Client $c, string $app): mixed => $c->assignments()->create(
                AssignmentCreate::forAliasId($app, 'product', 'sku-001', '00000000-0000-4000-8000-000000000000'),
            ),
            'alias_id',
        ];
        yield 'create by an unknown alias slug' => [
            static fn(Client $c, string $app): mixed => $c->assignments()->create(
                AssignmentCreate::forAliasSlug($app, 'product', 'sku-001', $missing),
            ),
            'alias_slug',
        ];
        yield 'bulk assign by an unknown alias slug' => [
            static fn(Client $c, string $app): mixed => $c->assignments()->bulkAssign(
                new BulkAssign($app, 'product', 'sku-001', aliasSlugs: [$missing]),
            ),
            'alias_slugs',
        ];
    }

    /**
     * Removing an assignment that does not exist writes nothing, and the
     * server says so: remove() gets its 204, bulkRemove() a count of 0.
     */
    public function testRemovingAnAbsentAssignmentRemovesNothing(): void
    {
        $client = $this->harnessClient(ApiVersion::V2);
        $app = self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID');
        $red = $this->tag($client, 'red', 'color');

        $client->assignments()->remove(new AssignmentRemove($app, 'product', self::ABSENT_RESOURCE, $red->id));
        $result = $client->assignments()->bulkRemove(new BulkRemove($app, 'product', self::ABSENT_RESOURCE, [$red->id]));

        self::assertSame(0, $result->removed);
        // And the assignments that do exist are where the corpus put them.
        self::assertSame(['large', 'red'], self::tagSlugs($client->resources()->listTags('product', 'sku-001', $app)->data));
    }

    public function testTheTagsOnAResourceAreThoseOfItsPartition(): void
    {
        $client = $this->harnessClient(ApiVersion::V2);
        $app = self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID');
        $a = self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID');

        $global = $client->resources()->listTags('product', 'sku-001', $app)->data;
        self::assertSame(['large', 'red'], self::tagSlugs($global));
        foreach ($global as $row) {
            self::assertNull($row->namespaceId);
            self::assertNull($row->tag->namespaceId);
            self::assertSame('octonomy-php-harness', $row->assignedBy);
        }

        $inA = $client->resources()->listTags('product', 'sku-001', $app, options: self::in($a))->data;
        self::assertSame(['red', 'sale'], self::tagSlugs($inA));
        foreach ($inA as $row) {
            self::assertSame($a, $row->namespaceId);
            self::assertSame($a, $row->tag->namespaceId);
        }

        // v1 sees the global partition, without the namespace pair.
        self::assertSame(
            ['large', 'red'],
            self::tagSlugs($this->harnessClient(ApiVersion::V1)->resources()->listTags('product', 'sku-001', $app)->data),
        );
    }

    /**
     * Red is on sku-001 directly and on sku-002 through the scarlet alias.
     */
    public function testTheResourcesOfATag(): void
    {
        $client = $this->harnessClient(ApiVersion::V2);
        $red = $this->tag($client, 'red', 'color');

        $resources = $client->tags()->listResources($red->id)->data;
        $ids = array_map(static fn(TagResource $r): string => $r->resourceId, $resources);
        sort($ids);
        self::assertSame(['sku-001', 'sku-002'], $ids);
        foreach ($resources as $resource) {
            self::assertSame('product', $resource->resourceType);
            self::assertSame(self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'), $resource->applicationId);
            self::assertNull($resource->namespaceId);
        }

        self::assertSame([], $client->tags()->listResources($red->id, new TagResourceListParams(resourceType: 'article'))->data);
    }

    public function testTheAuditLogNarrowsToATagAndToAResource(): void
    {
        $client = $this->harnessClient(ApiVersion::V2);
        $red = $this->tag($client, 'red', 'color');

        $byFilter = $client->auditLogs()->list(new AuditLogListParams(tagId: $red->id))->data;
        self::assertNotEmpty($byFilter);
        foreach ($byFilter as $log) {
            self::assertSame($red->id, $log->tagId);
            self::assertNull($log->namespaceId);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $log->operationId);
        }
        $actions = array_map(static fn(AuditLog $l): string => $l->action, $byFilter);
        self::assertContains('tag.created', $actions);
        self::assertContains('assignment.created', $actions);

        // The tag's own list is the same filter.
        self::assertSame(self::ids($byFilter), self::ids($client->tags()->listAuditLogs($red->id)->data));

        $created = array_values(array_filter($byFilter, static fn(AuditLog $l): bool => $l->action === 'tag.created'))[0];
        self::assertInstanceOf(\stdClass::class, $created->changes['after']);
        self::assertSame('red', $created->changes['after']->slug);

        $sku002 = $client->resources()->listAuditLogs('product', 'sku-002')->data;
        self::assertCount(2, $sku002, 'green, and red through scarlet, were assigned to sku-002');
        foreach ($sku002 as $log) {
            self::assertSame('assignment.created', $log->action);
            self::assertSame(['product', 'sku-002'], [$log->resourceType, $log->resourceId]);
        }
    }

    public function testANamespacedAuditLogSeesThatNamespaceAlone(): void
    {
        $a = self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID');
        $logs = $this->harnessClient(ApiVersion::V2)->auditLogs()->list(
            new AuditLogListParams(applicationId: self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID')),
            self::in($a),
        )->data;

        self::assertNotEmpty($logs);
        foreach ($logs as $log) {
            self::assertSame('merchant', $log->namespaceType);
            self::assertSame($a, $log->namespaceId);
        }
    }

    private function tag(Client $client, string $slug, string $type): Tag
    {
        $tags = $client->tags()->list(new TagListParams(slug: $slug, type: $type))->data;
        self::assertCount(1, $tags, "the global partition holds one {$type}/{$slug}");

        return $tags[0];
    }

    private static function in(string $merchant): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', $merchant));
    }

    /**
     * @param list<TagAlias> $aliases
     *
     * @return array<string, TagAlias>
     */
    private static function bySlug(array $aliases): array
    {
        $out = [];
        foreach ($aliases as $alias) {
            $out[$alias->slug] = $alias;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param list<ResourceTag> $rows
     *
     * @return list<string>
     */
    private static function tagSlugs(array $rows): array
    {
        $slugs = array_map(static fn(ResourceTag $r): string => $r->tag->slug, $rows);
        sort($slugs);

        return $slugs;
    }

    /**
     * @param list<AuditLog> $logs
     *
     * @return list<string>
     */
    private static function ids(array $logs): array
    {
        return array_map(static fn(AuditLog $l): string => $l->id, $logs);
    }
}
