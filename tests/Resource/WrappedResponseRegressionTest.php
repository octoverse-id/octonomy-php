<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagCreate;
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
 * nothing. Each case replays the real server's response to that call, first
 * proves the captured row sets every nullable field (so a zero value could not
 * pass for a hydrated one), then asserts every field arrives.
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

        $tag = $call($this->clientWith([self::captured($fixture)]));

        self::assertSame($row['id'], $tag->id);
        self::assertSame($row['tenant_id'], $tag->tenantId);
        self::assertSame($row['application_id'], $tag->applicationId);
        self::assertSame($row['name'], $tag->name);
        self::assertSame($row['slug'], $tag->slug);
        self::assertSame($row['type'], $tag->type);
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
        yield 'tags()->create()' => ['v1/tags/create', static fn(Client $c): Tag => $c->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label'),
        )];
        // Captured once the tag is assigned, so usage_count is not the zero
        // value either.
        yield 'tags()->get()' => ['v1/tags/get', static fn(Client $c): Tag => $c->tags()->get('tag_1')];
        yield 'tags()->update()' => ['v1/tags/update', static fn(Client $c): Tag => $c->tags()->update(
            'tag_1',
            new TagUpdate(name: 'Featured'),
        )];
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

        $vocabulary = $call($this->clientWith([self::captured($fixture)]));

        self::assertSame($row['id'], $vocabulary->id);
        self::assertSame($row['tenant_id'], $vocabulary->tenantId);
        self::assertSame($row['application_id'], $vocabulary->applicationId);
        self::assertSame($row['name'], $vocabulary->name);
        self::assertSame($row['slug'], $vocabulary->slug);
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
        yield 'vocabularies()->create()' => ['v1/vocabularies/create', static fn(Client $c): Vocabulary => $c->vocabularies()->create(
            new VocabularyCreate(name: 'Labels', slug: 'labels'),
        )];
        yield 'vocabularies()->get()' => ['v1/vocabularies/get', static fn(Client $c): Vocabulary => $c->vocabularies()->get('voc_1')];
        yield 'vocabularies()->update()' => ['v1/vocabularies/update', static fn(Client $c): Vocabulary => $c->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(name: 'Labels'),
        )];
    }
}
