<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\Vocabulary;

/**
 * SeededCorpusTest reads the corpus that scripts/octonomy-harness.sh seeds,
 * through the SDK, from a real server.
 *
 * The unit suite replays hand-written bodies, and for as long as the envelope
 * bug (#6) existed those bodies agreed with the code instead of the server.
 * Only a real server can say whether a Tag hydrates. The corpus is fixed (the
 * table at seed_corpus in the harness), so these assertions can name exact rows.
 *
 * The SDK targets /api/v1, which cannot address a namespace. The merchant rows
 * are seeded all the same, and the v1 assertions here prove they stay out of
 * view. Every test only reads, so the suite can run any number of times
 * against one harness.
 */
final class SeededCorpusTest extends IntegrationTestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function testGlobalTagHydratesFullyFromBothListAndGet(): void
    {
        $client = $this->harnessClient();

        // color/red is seeded three times: globally and in each merchant
        // namespace. /api/v1 sees only the global partition, so one row.
        $page = $client->tags()->list(new TagListParams(slug: 'red', type: 'color'));
        self::assertCount(1, $page->data);
        self::assertSame(1, $page->pagination->count);

        $red = $page->data[0];
        self::assertMatchesRegularExpression(self::UUID, $red->id);
        self::assertSame(self::harnessEnv('OCTONOMY_TEST_TENANT_ID'), $red->tenantId);
        self::assertNull($red->applicationId, 'red is seeded shared, with no application');
        self::assertSame('Red', $red->name);
        self::assertSame('Primary red', $red->description);
        self::assertSame(['hex' => '#cc0000'], $red->metadata);
        self::assertSame($this->vocabulary('colors')->id, $red->vocabularyId);
        self::assertNull($red->parentId);
        self::assertTrue($red->isActive);
        // product/sku-001 directly, and product/sku-002 through the scarlet alias.
        self::assertSame(2, $red->usageCount);
        self::assertGreaterThan(new \DateTimeImmutable('2020-01-01'), $red->createdAt);

        // The {data: {...}} single-resource envelope, against the real server.
        self::assertEquals($red, $client->tags()->get($red->id));
    }

    public function testApplicationScopedTagCarriesItsApplication(): void
    {
        $large = $this->onlyTag('large', 'size');

        self::assertSame(self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'), $large->applicationId);
        self::assertSame($this->vocabulary('sizes')->id, $large->vocabularyId);
        self::assertNull($large->description);
        self::assertSame(1, $large->usageCount);
    }

    public function testChildTagPointsAtItsParent(): void
    {
        $maroon = $this->onlyTag('maroon', 'color');

        self::assertSame($this->onlyTag('red', 'color')->id, $maroon->parentId);
        self::assertSame(0, $maroon->usageCount);
    }

    public function testSeededVocabulariesAreListedWithTheirScope(): void
    {
        $colors = $this->vocabulary('colors');
        self::assertNull($colors->applicationId);
        self::assertSame('Shared by every application in the tenant', $colors->description);

        $sizes = $this->vocabulary('sizes');
        self::assertSame(self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'), $sizes->applicationId);
        self::assertNull($sizes->description);
    }

    public function testNamespacedRowsAreInvisibleToV1(): void
    {
        $client = $this->harnessClient();

        // label tags exist only inside the merchant namespaces (sale in A,
        // clearance in B), and so does the harness's probe vocabulary.
        self::assertSame([], $client->tags()->list(new TagListParams(type: 'label'))->data);

        $slugs = array_map(
            static fn(Vocabulary $v): string => $v->slug,
            $client->vocabularies()->list()->data,
        );
        self::assertNotContains('harness-probe', $slugs);
        self::assertNotContains('harness-probe-a', $slugs);
    }

    public function testUnknownTagRaisesNotFoundFromTheRealErrorEnvelope(): void
    {
        $client = $this->harnessClient();

        try {
            $client->tags()->get('00000000-0000-4000-8000-000000000000');
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->statusCode);
            self::assertSame('not_found', $e->errorCode);
            self::assertNotNull($e->requestId);
        }
    }

    /**
     * The single global tag with this slug and type.
     */
    private function onlyTag(string $slug, string $type): Tag
    {
        $page = $this->harnessClient()->tags()->list(new TagListParams(slug: $slug, type: $type));
        self::assertCount(1, $page->data, "expected exactly one global {$type}/{$slug} tag in the seeded corpus");

        return $page->data[0];
    }

    /**
     * The seeded global vocabulary with this slug.
     */
    private function vocabulary(string $slug): Vocabulary
    {
        foreach ($this->harnessClient()->vocabularies()->list()->data as $vocabulary) {
            if ($vocabulary->slug === $slug) {
                return $vocabulary;
            }
        }
        self::fail("expected the seeded corpus to hold a global vocabulary {$slug}");
    }
}
