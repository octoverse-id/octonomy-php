<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Exception\WalkInterruptedException;
use Octoverse\Octonomy\Model\TagListParams;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * walk() against the real server (#25): an interrupted walk resumes from its
 * reachedOffset to exactly the list an uninterrupted walk reads, and a walk
 * with no limit of its own asks for pages of 200, a limit the server serves.
 *
 * Every walk reads the global partition's tags, which the harness seeds and
 * nothing in the suite writes, so the list holds still between the walks a
 * test compares. The failure is a request InterruptingClient drops, since the
 * suite cannot make the server fail a read.
 */
final class WalkTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function apiVersions(): iterable
    {
        yield 'api v1' => [ApiVersion::V1];
        yield 'api v2' => [ApiVersion::V2];
    }

    #[DataProvider('apiVersions')]
    public function testAnInterruptedWalkResumesFromItsReachedOffset(string $apiVersion): void
    {
        // One tag per page, so the walk spans as many pages as the list has
        // tags, and the third request is the third page.
        $params = new TagListParams(limit: 1);
        $whole = self::walkedIds($this->harnessClient($apiVersion), $params);
        self::assertGreaterThanOrEqual(3, count($whole), 'the seeded global partition holds four active tags');
        self::assertSame(array_keys($whole), range(0, count($whole) - 1));

        $http = new InterruptingClient(failOn: 3);
        $before = [];
        try {
            foreach ($this->harnessClient($apiVersion, http: $http)->tags()->walk($params) as $offset => $tag) {
                $before[$offset] = $tag->id;
            }
            self::fail('expected the dropped third request to interrupt the walk');
        } catch (WalkInterruptedException $e) {
            self::assertSame(2, $e->reachedOffset, 'two pages of one tag each were yielded before the third failed');
            $cause = $e->getPrevious();
            self::assertInstanceOf(TransportException::class, $cause);
            self::assertSame(['limit' => '1', 'offset' => '2'], $cause->context->query);
        }
        self::assertCount(3, $http->sent());
        self::assertSame(array_slice($whole, 0, 2, true), $before);

        $after = self::walkedIds(
            $this->harnessClient($apiVersion),
            new TagListParams(limit: 1, offset: $e->reachedOffset),
        );

        self::assertSame($whole, $before + $after, 'the resumed walk yields the rest of the list, each tag once');
    }

    public function testWithoutALimitAWalkAsksForPagesOf200(): void
    {
        $http = new InterruptingClient();
        $walked = self::walkedIds($this->harnessClient(ApiVersion::V2, http: $http), null);

        self::assertCount(1, $http->sent(), 'the whole list fits on one page of 200');
        $query = [];
        parse_str($http->sent()[0]->getUri()->getQuery(), $query);
        self::assertSame(['limit' => '200', 'offset' => '0'], $query);
        self::assertSame(self::walkedIds($this->harnessClient(ApiVersion::V2), new TagListParams(limit: 1)), $walked);
    }

    /**
     * What makes asking for 200 safe if the server's maximum ever drops: it
     * clamps a larger limit to its maximum rather than refusing it, and the
     * walk advances by what it was served.
     */
    public function testTheServerClampsALimitAboveItsMaximumRatherThanRefuseIt(): void
    {
        $page = $this->harnessClient(ApiVersion::V2)->tags()->list(new TagListParams(limit: 500));

        self::assertSame(200, $page->pagination->limit);
    }

    /**
     * The id of each tag a walk yields, keyed as the walk keyed it.
     *
     * @return array<int, string>
     */
    private static function walkedIds(Client $client, ?TagListParams $params): array
    {
        $ids = [];
        foreach ($client->tags()->walk($params) as $offset => $tag) {
            $ids[$offset] = $tag->id;
        }

        return $ids;
    }
}
