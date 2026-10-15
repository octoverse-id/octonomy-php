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
 * nothing. Each case below sends the real wrapped shape with every field set to
 * a non-default value, then asserts every field arrives.
 */
final class WrappedResponseRegressionTest extends SdkTestCase
{
    private const TAG = [
        'id' => 'tag_1',
        'tenant_id' => 'tenant-1',
        'application_id' => 'commerce',
        'name' => 'Featured',
        'slug' => 'featured',
        'type' => 'label',
        'description' => 'Hand-picked items',
        'parent_id' => 'tag_parent',
        'vocabulary_id' => 'voc_1',
        'metadata' => ['color' => 'red'],
        'is_active' => true,
        'usage_count' => 7,
        'created_at' => '2026-06-08T12:00:00+00:00',
        'updated_at' => '2026-06-09T08:30:00+00:00',
    ];

    private const VOCABULARY = [
        'id' => 'voc_1',
        'tenant_id' => 'tenant-1',
        'application_id' => 'commerce',
        'name' => 'Labels',
        'slug' => 'labels',
        'description' => 'Merchandising labels',
        'metadata' => ['owner' => 'catalog'],
        'is_active' => true,
        'created_at' => '2026-06-08T12:00:00+00:00',
        'updated_at' => '2026-06-09T08:30:00+00:00',
    ];

    /**
     * @param \Closure(Client): Tag $call
     */
    #[DataProvider('tagCalls')]
    public function testTagMethodHydratesEveryFieldFromTheWrappedShape(int $status, \Closure $call): void
    {
        $client = $this->clientWith([self::jsonResponse($status, ['data' => self::TAG])]);

        $tag = $call($client);

        self::assertSame('tag_1', $tag->id);
        self::assertSame('tenant-1', $tag->tenantId);
        self::assertSame('commerce', $tag->applicationId);
        self::assertSame('Featured', $tag->name);
        self::assertSame('featured', $tag->slug);
        self::assertSame('label', $tag->type);
        self::assertSame('Hand-picked items', $tag->description);
        self::assertSame('tag_parent', $tag->parentId);
        self::assertSame('voc_1', $tag->vocabularyId);
        self::assertSame(['color' => 'red'], $tag->metadata);
        self::assertTrue($tag->isActive);
        self::assertSame(7, $tag->usageCount);
        self::assertSame('2026-06-08T12:00:00+00:00', $tag->createdAt->format(DATE_ATOM));
        self::assertSame('2026-06-09T08:30:00+00:00', $tag->updatedAt->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{int, \Closure(Client): Tag}>
     */
    public static function tagCalls(): iterable
    {
        yield 'tags()->create()' => [201, static fn(Client $c): Tag => $c->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label'),
        )];
        yield 'tags()->get()' => [200, static fn(Client $c): Tag => $c->tags()->get('tag_1')];
        yield 'tags()->update()' => [200, static fn(Client $c): Tag => $c->tags()->update(
            'tag_1',
            new TagUpdate(name: 'Featured'),
        )];
    }

    /**
     * @param \Closure(Client): Vocabulary $call
     */
    #[DataProvider('vocabularyCalls')]
    public function testVocabularyMethodHydratesEveryFieldFromTheWrappedShape(int $status, \Closure $call): void
    {
        $client = $this->clientWith([self::jsonResponse($status, ['data' => self::VOCABULARY])]);

        $vocabulary = $call($client);

        self::assertSame('voc_1', $vocabulary->id);
        self::assertSame('tenant-1', $vocabulary->tenantId);
        self::assertSame('commerce', $vocabulary->applicationId);
        self::assertSame('Labels', $vocabulary->name);
        self::assertSame('labels', $vocabulary->slug);
        self::assertSame('Merchandising labels', $vocabulary->description);
        self::assertSame(['owner' => 'catalog'], $vocabulary->metadata);
        self::assertTrue($vocabulary->isActive);
        self::assertSame('2026-06-08T12:00:00+00:00', $vocabulary->createdAt->format(DATE_ATOM));
        self::assertSame('2026-06-09T08:30:00+00:00', $vocabulary->updatedAt->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{int, \Closure(Client): Vocabulary}>
     */
    public static function vocabularyCalls(): iterable
    {
        yield 'vocabularies()->create()' => [201, static fn(Client $c): Vocabulary => $c->vocabularies()->create(
            new VocabularyCreate(name: 'Labels', slug: 'labels'),
        )];
        yield 'vocabularies()->get()' => [200, static fn(Client $c): Vocabulary => $c->vocabularies()->get('voc_1')];
        yield 'vocabularies()->update()' => [200, static fn(Client $c): Vocabulary => $c->vocabularies()->update(
            'voc_1',
            new VocabularyUpdate(name: 'Labels'),
        )];
    }
}
