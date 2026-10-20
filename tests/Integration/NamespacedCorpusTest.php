<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\ForbiddenException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyListParams;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;

/**
 * NamespacedCorpusTest reads the seeded merchant namespaces through /api/v2,
 * where the SDK sends the X-Namespace-* pair (#15). The unit suite proves the
 * headers leave the SDK; only a real server can say they select the partition
 * the caller named. The corpus is the table at seed_corpus in the harness: in
 * merchant A, color/red and label/sale; in merchant B, color/red and
 * label/clearance. Every test only reads.
 */
final class NamespacedCorpusTest extends IntegrationTestCase
{
    public function testAV2ReadWithoutANamespaceSeesTheGlobalPartition(): void
    {
        $client = $this->harnessClient(ApiVersion::V2);

        // color/red is seeded globally and once in each merchant namespace.
        $red = $client->tags()->list(new TagListParams(slug: 'red', type: 'color'))->data;
        self::assertCount(1, $red);
        self::assertNull($red[0]->namespaceType);
        self::assertNull($red[0]->namespaceId);

        // Labels exist only inside the merchant namespaces.
        self::assertSame([], $client->tags()->list(new TagListParams(type: 'label'))->data);
    }

    public function testANamespacedReadSeesThatNamespaceAlone(): void
    {
        $a = self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID');
        $tags = $this->harnessClient(ApiVersion::V2)->tags()->list(
            new TagListParams(applicationId: self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID')),
            self::in($a),
        )->data;

        $bySlug = [];
        foreach ($tags as $tag) {
            self::assertSame('merchant', $tag->namespaceType);
            self::assertSame($a, $tag->namespaceId);
            $bySlug[$tag->slug] = $tag;
        }
        ksort($bySlug);
        self::assertSame(['red', 'sale'], array_keys($bySlug));

        // usage_count is namespace-scoped on v2: merchant A's red is assigned to
        // sku-001 there, once. The global red reads 2 on v1 (SeededCorpusTest).
        self::assertSame(1, $bySlug['red']->usageCount);
    }

    /**
     * A namespaced detail read must name its application, and reaches a global
     * row only with include_global; get() sends both as query parameters.
     */
    public function testANamespacedGetNamesItsApplicationAndOptsIntoGlobalRows(): void
    {
        $client = $this->harnessClient(ApiVersion::V2);
        $app = self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID');
        $inA = self::in(self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID'));

        $sale = $client->tags()->list(new TagListParams(applicationId: $app, slug: 'sale'), $inA)->data[0];
        self::assertEquals($sale, $client->tags()->get($sale->id, $app, options: $inA));

        $globalRed = $client->tags()->list(new TagListParams(slug: 'red', type: 'color'))->data[0];
        self::assertNull($globalRed->namespaceId);
        self::assertSame($globalRed->id, $client->tags()->get($globalRed->id, $app, true, $inA)->id);

        try {
            $client->tags()->get($globalRed->id, $app, options: $inA);
            self::fail('expected a namespaced read without include_global to miss the global row');
        } catch (NotFoundException) {
        }

        $this->expectException(ForbiddenException::class);
        $client->tags()->get($sale->id, options: $inA);
    }

    public function testIncludeGlobalAddsTheGlobalRowsToANamespacedRead(): void
    {
        $a = self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID');
        $client = $this->harnessClient(ApiVersion::V2);
        $params = static fn(?bool $includeGlobal): VocabularyListParams => new VocabularyListParams(
            applicationId: self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'),
            includeGlobal: $includeGlobal,
        );

        $own = self::slugs($client->vocabularies()->list($params(null), self::in($a))->data);
        $widened = self::slugs($client->vocabularies()->list($params(true), self::in($a))->data);

        self::assertContains('harness-probe-a', $own);
        self::assertNotContains('colors', $own);
        self::assertContains('harness-probe-a', $widened);
        self::assertContains('colors', $widened);
    }

    public function testAnExactMerchantGrantIsRefusedInAnotherNamespace(): void
    {
        $client = $this->harnessClient(ApiVersion::V2, 'OCTONOMY_TEST_NAMESPACE_A_TOKEN');
        $params = new TagListParams(applicationId: self::harnessEnv('OCTONOMY_TEST_APPLICATION_ID'));

        // Inside its own namespace the grant reads; the namespace the SDK sent
        // is the one the server authorized against.
        $own = $client->tags()->list($params, self::in(self::harnessEnv('OCTONOMY_TEST_NAMESPACE_A_ID')))->data;
        self::assertNotSame([], $own);

        $this->expectException(ForbiddenException::class);
        $client->tags()->list($params, self::in(self::harnessEnv('OCTONOMY_TEST_NAMESPACE_B_ID')));
    }

    private static function in(string $merchant): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', $merchant));
    }

    /**
     * @param list<Vocabulary> $vocabularies
     *
     * @return list<string>
     */
    private static function slugs(array $vocabularies): array
    {
        return array_map(static fn(Vocabulary $v): string => $v->slug, $vocabularies);
    }
}
