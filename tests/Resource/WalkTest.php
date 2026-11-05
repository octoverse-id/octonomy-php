<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\ForbiddenException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Exception\WalkInterruptedException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use Octoverse\Octonomy\Tests\SdkTestCase;

/**
 * What every walk method does, through TagService::walk() (#25): the pages it
 * asks for, how it ends, and the WalkInterruptedException a failed page ends
 * it with, whose reachedOffset resumes it.
 *
 * The pages were captured as a walk sends them, from merchant A's four active
 * tags: tags/walk-first and tags/walk-last at limit 3 (three tags with a next
 * link, then one without), tags/walk-end at the offset after the last tag,
 * and tags/walk-limit-200 at the walk's own limit, which holds all four.
 */
final class WalkTest extends SdkTestCase
{
    public function testWithoutALimitAWalkAsksForPagesOf200(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/walk-limit-200')]);

        $walked = iterator_to_array($client->tags()->walk(new TagListParams(applicationId: 'harness-app'), self::merchantA()));

        self::assertSame(
            [['application_id' => 'harness-app', 'limit' => '200', 'offset' => '0']],
            $this->sentQueries(),
            'one request, at the server\'s maximum limit rather than its default of 50',
        );
        self::assertSame('/api/v2/tags', $this->lastRequest()->getUri()->getPath());
        self::assertSame(self::slugs('v2/tags/walk-limit-200'), self::slugsOf($walked));
        self::assertSame([0, 1, 2, 3], array_keys($walked));
        self::assertContainsOnlyInstancesOf(Tag::class, $walked);
    }

    public function testACallerLimitWinsAndEachPageStartsWhereTheOneBeforeEnded(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/walk-first'), self::captured('v2/tags/walk-last')]);

        $walked = iterator_to_array($client->tags()->walk(
            new TagListParams(applicationId: 'harness-app', type: 'label', limit: 3),
            self::merchantA(),
        ));

        self::assertSame([
            ['application_id' => 'harness-app', 'type' => 'label', 'limit' => '3', 'offset' => '0'],
            ['application_id' => 'harness-app', 'type' => 'label', 'limit' => '3', 'offset' => '3'],
        ], $this->sentQueries(), 'every page carries the caller\'s params and limit, and ends at the page with no next link');
        self::assertSame(
            [...self::slugs('v2/tags/walk-first'), ...self::slugs('v2/tags/walk-last')],
            self::slugsOf($walked),
        );
        self::assertSame([0, 1, 2, 3], array_keys($walked), 'each tag is keyed by its offset in the list');
        self::assertSame(self::slugs('v2/tags/walk-limit-200'), self::slugsOf($walked), 'the same tags as one page of 200');
    }

    public function testAnEmptyListWalksNothing(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/list-empty')]);

        $walked = iterator_to_array($client->tags()->walk(new TagListParams(slug: 'no-such-tag')));

        self::assertSame([], $walked);
        self::assertSame([['slug' => 'no-such-tag', 'limit' => '200', 'offset' => '0']], $this->sentQueries());
    }

    public function testAWalkStartedAtTheEndOfTheListYieldsNothing(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/walk-end')]);

        $walked = iterator_to_array($client->tags()->walk(new TagListParams(limit: 3, offset: 4), self::merchantA()));

        self::assertSame([], $walked);
        self::assertSame([['limit' => '3', 'offset' => '4']], $this->sentQueries());
    }

    public function testNothingIsSentUntilTheWalkIsIterated(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/walk-first'), self::captured('v2/tags/walk-last')]);

        $walk = $client->tags()->walk(new TagListParams(limit: 3));
        $this->assertNothingSent();

        // Each page only once the one before it is used up.
        $seen = [];
        foreach ($walk as $offset => $tag) {
            $seen[$offset] = count($this->sentRequests());
        }
        self::assertSame([0 => 1, 1 => 1, 2 => 1, 3 => 2], $seen);
    }

    public function testLeavingAWalkEarlySendsNoFurtherRequest(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/walk-first'), self::captured('v2/tags/walk-last')]);

        foreach ($client->tags()->walk(new TagListParams(limit: 3)) as $tag) {
            break;
        }

        self::assertCount(1, $this->sentRequests());
    }

    public function testAFailedPageEndsTheWalkAtTheOffsetThatPageStartsAt(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/walk-first'), self::captured('v2/errors/forbidden')]);

        [$walked, $e] = self::walkUntilInterrupted($client->tags()->walk(new TagListParams(limit: 3), self::merchantA()));

        self::assertSame(self::slugs('v2/tags/walk-first'), self::slugsOf($walked), 'every tag before the failed page');
        self::assertSame(3, $e->reachedOffset);
        $cause = $e->getPrevious();
        self::assertInstanceOf(ForbiddenException::class, $cause);
        self::assertSame('/api/v2/tags', $cause->context->path);
        self::assertSame(['limit' => '3', 'offset' => '3'], $cause->context->query, 'the failed page\'s own request');
        self::assertStringContainsString(
            'a walk stopped at offset 3, the offset the page whose request failed was asked for; it yielded the items'
            . ' from where it started up to there, and none from that page on.',
            $e->getMessage(),
        );
        self::assertStringContainsString(substr($cause->getMessage(), strlen('octonomy: ')), $e->getMessage());
        self::assertStringContainsString('walk again with the same params and offset 3', $e->getMessage());
        self::assertStringNotContainsString('test-token', $e->getMessage());
    }

    public function testTheReachedOffsetResumesTheWalk(): void
    {
        $params = new TagListParams(applicationId: 'harness-app', limit: 3);
        $client = $this->clientWith([self::captured('v2/tags/walk-first'), self::captured('v2/errors/forbidden')]);
        [$before, $e] = self::walkUntilInterrupted($client->tags()->walk($params, self::merchantA()));

        $client = $this->clientWith([self::captured('v2/tags/walk-last')]);
        $after = iterator_to_array($client->tags()->walk(
            new TagListParams(applicationId: 'harness-app', limit: 3, offset: $e->reachedOffset),
            self::merchantA(),
        ));

        self::assertSame([['application_id' => 'harness-app', 'limit' => '3', 'offset' => '3']], $this->sentQueries());
        self::assertSame([3], array_keys($after), 'a resumed walk keys its tags by their offset in the list, too');
        self::assertSame(
            self::slugs('v2/tags/walk-limit-200'),
            [...self::slugsOf($before), ...self::slugsOf($after)],
            'the two halves are the whole list, each tag once',
        );
    }

    public function testAFailedFirstPageReachesTheOffsetTheWalkStartedAt(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/forbidden')]);

        [$walked, $e] = self::walkUntilInterrupted($client->tags()->walk(new TagListParams(limit: 3, offset: 3)));

        self::assertSame([], $walked);
        self::assertSame(3, $e->reachedOffset);
        self::assertInstanceOf(ForbiddenException::class, $e->getPrevious());
    }

    public function testAnErrorStatusWithoutTheEnvelopeEndsTheWalk(): void
    {
        $client = $this->clientWith([
            self::captured('v2/tags/walk-first'),
            self::syntheticNegative(SyntheticNegative::GATEWAY, 503, '<html>Service Unavailable</html>', ['Content-Type' => 'text/html']),
        ]);

        [, $e] = self::walkUntilInterrupted($client->tags()->walk(new TagListParams(limit: 3)));

        self::assertSame(3, $e->reachedOffset);
        $cause = $e->getPrevious();
        self::assertInstanceOf(ApiException::class, $cause);
        self::assertSame(503, $cause->statusCode);
    }

    public function testAPageThatIsNotJsonEndsTheWalk(): void
    {
        $client = $this->clientWith([
            self::captured('v2/tags/walk-first'),
            self::syntheticNegative(SyntheticNegative::GATEWAY, 200, '<html>Please wait</html>', ['Content-Type' => 'text/html']),
        ]);

        [, $e] = self::walkUntilInterrupted($client->tags()->walk(new TagListParams(limit: 3)));

        self::assertSame(3, $e->reachedOffset);
        self::assertInstanceOf(TransportException::class, $e->getPrevious());
    }

    public function testAMalformedPageEndsTheWalkBeforeAnyOfItsItems(): void
    {
        $client = $this->clientWith([
            self::captured('v2/tags/walk-first'),
            self::syntheticNegativeFrom('v2/tags/walk-last', SyntheticNegative::MISSING_FIELD, static function (array $body): array {
                unset($body['data'][0]['slug']);

                return $body;
            }),
        ]);

        [$walked, $e] = self::walkUntilInterrupted($client->tags()->walk(new TagListParams(limit: 3), self::merchantA()));

        self::assertSame(self::slugs('v2/tags/walk-first'), self::slugsOf($walked));
        self::assertSame(3, $e->reachedOffset);
        $cause = $e->getPrevious();
        self::assertInstanceOf(MalformedResponseException::class, $cause);
        self::assertSame('data[0].slug', $cause->field);
    }

    public function testAPageFromAnotherOffsetEndsTheWalkRatherThanRepeatIt(): void
    {
        // The first page again, as something that dropped the offset from the
        // second request would answer it. Walked on, it would be asked for
        // again at the same offset, and answered the same way, for ever.
        $client = $this->clientWith([
            self::captured('v2/tags/walk-first'),
            self::syntheticNegativeFrom('v2/tags/walk-first', SyntheticNegative::OTHER_PAGE, static fn(array $body): array => $body),
        ]);

        [$walked, $e] = self::walkUntilInterrupted($client->tags()->walk(new TagListParams(limit: 3), self::merchantA()));

        self::assertSame(self::slugs('v2/tags/walk-first'), self::slugsOf($walked), 'no tag twice');
        self::assertCount(2, $this->sentRequests(), 'and no third request for the same page');
        self::assertSame(3, $e->reachedOffset);
        $cause = $e->getPrevious();
        self::assertInstanceOf(MalformedResponseException::class, $cause);
        self::assertSame('pagination.offset', $cause->field);
        self::assertNotNull($cause->context);
        self::assertSame('GET /api/v2/tags', $cause->context->endpoint());
        self::assertSame(['limit' => '3', 'offset' => '3'], $cause->context->query);
        self::assertStringContainsString('"pagination.offset" is not 3, the offset the walk asked for, got "0"', $cause->getMessage());
    }

    /**
     * The offset is sent as it is, and the walk counts from where the server
     * says the first page starts: it serves a negative offset from 0.
     */
    public function testAWalkCountsFromWhereTheServerSaysItsFirstPageStarts(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/walk-negative-offset'), self::captured('v2/tags/walk-last')]);

        $walked = iterator_to_array($client->tags()->walk(new TagListParams(limit: 3, offset: -1), self::merchantA()));

        self::assertSame([['limit' => '3', 'offset' => '-1'], ['limit' => '3', 'offset' => '3']], $this->sentQueries());
        self::assertSame([0, 1, 2, 3], array_keys($walked));
        self::assertSame(self::slugs('v2/tags/walk-limit-200'), self::slugsOf($walked));
    }

    public function testAFailedFirstPageReachesTheOffsetItWasAskedFor(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/forbidden')]);

        [, $e] = self::walkUntilInterrupted($client->tags()->walk(new TagListParams(offset: -1)));

        self::assertSame(-1, $e->reachedOffset, 'nothing was served, so the walk got no further than it asked');
    }

    public function testAMistakeInTheCallIsThrownAsItIsNotAsAnInterruption(): void
    {
        $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);
        $walk = $client->tags()->walk(new TagListParams(includeGlobal: true));

        try {
            iterator_to_array($walk);
            self::fail('expected a v1 client to refuse include_global');
        } catch (\LogicException $e) {
            // A \LogicException, so not wrapped: WalkInterruptedException is a \RuntimeException.
            self::assertStringContainsString('include_global requires api_version v2', $e->getMessage());
        }
        $this->assertNothingSent();
    }

    /**
     * Iterate $walk until it throws WalkInterruptedException, and return what
     * it yielded, keyed as it keyed them, and the exception.
     *
     * @param \Generator<int, Tag, mixed, void> $walk
     *
     * @return array{array<int, Tag>, WalkInterruptedException}
     */
    private static function walkUntilInterrupted(\Generator $walk): array
    {
        $walked = [];
        try {
            foreach ($walk as $offset => $tag) {
                $walked[$offset] = $tag;
            }
        } catch (WalkInterruptedException $e) {
            return [$walked, $e];
        }
        self::fail('expected the walk to be interrupted');
    }

    /**
     * @return list<string>
     */
    private static function slugs(string $fixture): array
    {
        return array_column(self::capturedData($fixture), 'slug');
    }

    /**
     * @param array<int, Tag> $tags
     *
     * @return list<string>
     */
    private static function slugsOf(array $tags): array
    {
        return array_values(array_map(static fn(Tag $tag): string => $tag->slug, $tags));
    }

    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
