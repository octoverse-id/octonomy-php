<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Model\Assignment;
use Octoverse\Octonomy\Model\AssignmentCreate;
use Octoverse\Octonomy\Model\BulkAssign;
use Octoverse\Octonomy\Model\BulkAssignResult;
use Octoverse\Octonomy\Model\BulkRemove;
use Octoverse\Octonomy\Model\BulkRemoveResult;
use Octoverse\Octonomy\Model\ResourceTagsReplace;
use Octoverse\Octonomy\Model\ResourceTagsReplaceResult;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagAliasCreate;
use Octoverse\Octonomy\Model\TagAliasUpdate;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagResolution;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyUpdate;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression test for issue #6 (mandated; do not skip or delete).
 *
 * Octonomy wraps every single-resource 2xx as {"data": {...}}. The SDK used to
 * hydrate the wrapper itself, so every create/get/update returned a model with
 * id '', isActive false, usageCount 0 and createdAt 1970-01-01, and raised
 * nothing. Each case replays the real server's response to that call, on each
 * api_version, first proves the captured row sets every nullable field (so a
 * zero value could not pass for a hydrated one), then asserts every field
 * arrives.
 */
final class WrappedResponseRegressionTest extends SdkTestCase
{
    /**
     * @param \Closure(Client): Tag $call
     */
    #[DataProvider('tagCalls')]
    public function testTagMethodHydratesEveryFieldFromTheWrappedShape(string $fixture, \Closure $call): void
    {
        $row = self::capturedData($fixture);
        foreach (['application_id', 'description', 'parent_id', 'vocabulary_id'] as $nullable) {
            self::assertIsString($row[$nullable] ?? null, "{$fixture} must set {$nullable} for this test to mean anything");
        }
        self::assertNotEmpty($row['metadata']);
        self::assertTrue($row['is_active']);

        $captured = self::captured($fixture);
        $tag = $call($this->clientWith([$captured], ['api_version' => $captured->apiVersion()]));

        self::assertSame($row['id'], $tag->id);
        self::assertSame($row['tenant_id'], $tag->tenantId);
        self::assertSame($row['application_id'], $tag->applicationId);
        self::assertSame($row['name'], $tag->name);
        self::assertSame($row['slug'], $tag->slug);
        self::assertSame($row['type'], $tag->type);
        // Absent from v1, which reads it as null; set on v2, captured in a namespace.
        self::assertSame($row['namespace_type'] ?? null, $tag->namespaceType);
        self::assertSame($row['namespace_id'] ?? null, $tag->namespaceId);
        self::assertSame($row['description'], $tag->description);
        self::assertSame($row['parent_id'], $tag->parentId);
        self::assertSame($row['vocabulary_id'], $tag->vocabularyId);
        self::assertSame($row['metadata'], $tag->metadata);
        self::assertTrue($tag->isActive);
        self::assertSame($row['usage_count'], $tag->usageCount);
        self::assertEquals(new \DateTimeImmutable($row['created_at']), $tag->createdAt);
        self::assertEquals(new \DateTimeImmutable($row['updated_at']), $tag->updatedAt);
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): Tag}>
     */
    public static function tagCalls(): iterable
    {
        foreach (['v1', 'v2'] as $v) {
            yield "{$v} tags()->create()" => ["{$v}/tags/create", static fn(Client $c): Tag => $c->tags()->create(
                new TagCreate(name: 'Featured', slug: 'featured', type: 'label'),
            )];
            // Captured once the tag is assigned, so usage_count is not the zero
            // value either.
            yield "{$v} tags()->get()" => ["{$v}/tags/get", static fn(Client $c): Tag => $c->tags()->get('tag_1')];
            yield "{$v} tags()->update()" => ["{$v}/tags/update", static fn(Client $c): Tag => $c->tags()->update(
                'tag_1',
                new TagUpdate(name: 'Featured'),
            )];
        }
    }

    /**
     * @param \Closure(Client): Vocabulary $call
     */
    #[DataProvider('vocabularyCalls')]
    public function testVocabularyMethodHydratesEveryFieldFromTheWrappedShape(string $fixture, \Closure $call): void
    {
        $row = self::capturedData($fixture);
        foreach (['application_id', 'description'] as $nullable) {
            self::assertIsString($row[$nullable] ?? null, "{$fixture} must set {$nullable} for this test to mean anything");
        }
        self::assertNotEmpty($row['metadata']);
        self::assertTrue($row['is_active']);

        $captured = self::captured($fixture);
        $vocabulary = $call($this->clientWith([$captured], ['api_version' => $captured->apiVersion()]));

        self::assertSame($row['id'], $vocabulary->id);
        self::assertSame($row['tenant_id'], $vocabulary->tenantId);
        self::assertSame($row['application_id'], $vocabulary->applicationId);
        self::assertSame($row['name'], $vocabulary->name);
        self::assertSame($row['slug'], $vocabulary->slug);
        self::assertSame($row['namespace_type'] ?? null, $vocabulary->namespaceType);
        self::assertSame($row['namespace_id'] ?? null, $vocabulary->namespaceId);
        self::assertSame($row['description'], $vocabulary->description);
        self::assertSame($row['metadata'], $vocabulary->metadata);
        self::assertTrue($vocabulary->isActive);
        self::assertEquals(new \DateTimeImmutable($row['created_at']), $vocabulary->createdAt);
        self::assertEquals(new \DateTimeImmutable($row['updated_at']), $vocabulary->updatedAt);
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): Vocabulary}>
     */
    public static function vocabularyCalls(): iterable
    {
        foreach (['v1', 'v2'] as $v) {
            yield "{$v} vocabularies()->create()" => ["{$v}/vocabularies/create", static fn(Client $c): Vocabulary => $c->vocabularies()->create(
                new VocabularyCreate(name: 'Labels', slug: 'labels'),
            )];
            yield "{$v} vocabularies()->get()" => ["{$v}/vocabularies/get", static fn(Client $c): Vocabulary => $c->vocabularies()->get('voc_1')];
            yield "{$v} vocabularies()->update()" => ["{$v}/vocabularies/update", static fn(Client $c): Vocabulary => $c->vocabularies()->update(
                'voc_1',
                new VocabularyUpdate(name: 'Labels'),
            )];
        }
    }

    /**
     * @param \Closure(Client): TagAlias $call
     */
    #[DataProvider('aliasCalls')]
    public function testAliasMethodHydratesEveryFieldFromTheWrappedShape(string $fixture, \Closure $call): void
    {
        $row = self::capturedData($fixture);
        self::assertIsString($row['application_id'], "{$fixture} must set application_id for this test to mean anything");
        self::assertNotEmpty($row['metadata']);
        self::assertTrue($row['is_active']);

        $captured = self::captured($fixture);
        $alias = $call($this->clientWith([$captured], ['api_version' => $captured->apiVersion()]));

        self::assertSame($row['id'], $alias->id);
        self::assertSame($row['tenant_id'], $alias->tenantId);
        self::assertSame($row['application_id'], $alias->applicationId);
        self::assertSame($row['namespace_type'] ?? null, $alias->namespaceType);
        self::assertSame($row['namespace_id'] ?? null, $alias->namespaceId);
        self::assertSame($row['tag_id'], $alias->tagId);
        self::assertSame($row['name'], $alias->name);
        self::assertSame($row['slug'], $alias->slug);
        self::assertSame($row['metadata'], $alias->metadata);
        self::assertTrue($alias->isActive);
        self::assertEquals(new \DateTimeImmutable($row['created_at']), $alias->createdAt);
        self::assertEquals(new \DateTimeImmutable($row['updated_at']), $alias->updatedAt);
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): TagAlias}>
     */
    public static function aliasCalls(): iterable
    {
        foreach (['v1', 'v2'] as $v) {
            yield "{$v} aliases()->create()" => ["{$v}/tag-aliases/create", static fn(Client $c): TagAlias => $c->aliases()->create(
                new TagAliasCreate(tagId: 'tag_1', name: 'Staff Pick', slug: 'staff-pick'),
            )];
            yield "{$v} aliases()->get()" => ["{$v}/tag-aliases/get", static fn(Client $c): TagAlias => $c->aliases()->get('alias_1')];
            yield "{$v} aliases()->update()" => ["{$v}/tag-aliases/update", static fn(Client $c): TagAlias => $c->aliases()->update(
                'alias_1',
                new TagAliasUpdate(name: 'Staff Picks'),
            )];
        }
    }

    /**
     * Assigning returns the assignment the server wraps, new (201) or
     * existing (200) alike.
     */
    #[DataProvider('assignmentFixtures')]
    public function testAssignmentCreateHydratesEveryFieldFromTheWrappedShape(string $fixture): void
    {
        $row = self::capturedData($fixture);
        self::assertIsString($row['assigned_by'], "{$fixture} must set assigned_by for this test to mean anything");

        $captured = self::captured($fixture);
        $assignment = $this->clientWith([$captured], ['api_version' => $captured->apiVersion()])->assignments()->create(
            AssignmentCreate::forTag('harness-app', 'product', 'sku-003', 'tag_1'),
        );

        self::assertInstanceOf(Assignment::class, $assignment);
        self::assertSame($row['id'], $assignment->id);
        self::assertSame($row['tenant_id'], $assignment->tenantId);
        self::assertSame($row['application_id'], $assignment->applicationId);
        self::assertSame($row['namespace_type'] ?? null, $assignment->namespaceType);
        self::assertSame($row['namespace_id'] ?? null, $assignment->namespaceId);
        self::assertSame($row['tag_id'], $assignment->tagId);
        self::assertSame($row['resource_type'], $assignment->resourceType);
        self::assertSame($row['resource_id'], $assignment->resourceId);
        self::assertSame($row['assigned_by'], $assignment->assignedBy);
        self::assertEquals(new \DateTimeImmutable($row['assigned_at']), $assignment->assignedAt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function assignmentFixtures(): iterable
    {
        foreach (['v1', 'v2'] as $v) {
            yield "{$v} new" => ["{$v}/tag-assignments/create"];
            yield "{$v} existing" => ["{$v}/tag-assignments/create-existing"];
        }
    }

    /**
     * The summary objects the server wraps in place of the spec's bare arrays
     * or empty body, and a resolution, each with what it holds.
     *
     * @param \Closure(Client): object $call
     * @param \Closure(object, array<array-key, mixed>): void $check
     */
    #[DataProvider('summaryCalls')]
    public function testSummaryMethodHydratesFromTheWrappedShape(string $fixture, \Closure $call, \Closure $check): void
    {
        $captured = self::captured($fixture);

        $check($call($this->clientWith([$captured], ['api_version' => $captured->apiVersion()])), self::capturedData($fixture));
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): object, \Closure(object, array<array-key, mixed>): void}>
     */
    public static function summaryCalls(): iterable
    {
        foreach (['v1', 'v2'] as $v) {
            yield "{$v} assignments()->bulkAssign()" => [
                "{$v}/tag-assignments/bulk-assign",
                static fn(Client $c): BulkAssignResult => $c->assignments()->bulkAssign(new BulkAssign('harness-app', 'product', 'sku-003', ['tag_1'])),
                static function (object $result, array $data): void {
                    self::assertInstanceOf(BulkAssignResult::class, $result);
                    self::assertSame([$data['created'], $data['existing'], $data['skipped']], [$result->created, $result->existing, $result->skipped]);
                    self::assertNotEmpty($data['assignments']);
                    self::assertSame(array_column($data['assignments'], 'id'), array_map(static fn(Assignment $a): string => $a->id, $result->assignments));
                },
            ];
            yield "{$v} assignments()->bulkRemove()" => [
                "{$v}/tag-assignments/bulk-remove",
                static fn(Client $c): BulkRemoveResult => $c->assignments()->bulkRemove(new BulkRemove('harness-app', 'product', 'sku-003', ['tag_1'])),
                static function (object $result, array $data): void {
                    self::assertInstanceOf(BulkRemoveResult::class, $result);
                    self::assertGreaterThan(0, $data['removed']);
                    self::assertSame($data['removed'], $result->removed);
                },
            ];
            yield "{$v} resources()->replaceTags()" => [
                "{$v}/resources/replace",
                static fn(Client $c): ResourceTagsReplaceResult => $c->resources()->replaceTags('product', 'sku-004', new ResourceTagsReplace('harness-app', ['tag_1'])),
                static function (object $result, array $data): void {
                    self::assertInstanceOf(ResourceTagsReplaceResult::class, $result);
                    self::assertSame([$data['created'], $data['removed']], [$result->created, $result->removed]);
                    self::assertNotEmpty($data['tags']);
                    self::assertSame(array_column($data['tags'], 'id'), array_map(static fn(Tag $t): string => $t->id, $result->tags));
                },
            ];
            yield "{$v} tags()->resolve()" => [
                "{$v}/tag-resolution/alias",
                static fn(Client $c): TagResolution => $c->tags()->resolve('promo'),
                static function (object $result, array $data): void {
                    self::assertInstanceOf(TagResolution::class, $result);
                    self::assertSame($data['matched_type'], $result->matchedType);
                    self::assertNotNull($result->matchedAlias);
                    self::assertSame($data['matched_alias']['id'], $result->matchedAlias->id);
                    self::assertSame($data['tag']['id'], $result->tag->id);
                    self::assertSame($data['tag']['usage_count'], $result->tag->usageCount);
                },
            ];
        }
    }
}
