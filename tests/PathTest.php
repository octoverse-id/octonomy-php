<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Internal\Path;
use Octoverse\Octonomy\Model\TagUpdate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Path::of() builds every path a service sends below the api_version prefix
 * (#23): each segment percent-encoded, so a value cannot add a segment of its
 * own, and a dot segment encoded so no HTTP client removes it. An empty
 * segment is sent as it is.
 */
final class PathTest extends SdkTestCase
{
    public function testEachSegmentIsPercentEncodedOnItsOwn(): void
    {
        self::assertSame('tags', Path::of('tags'));
        self::assertSame('tags/7d1c', Path::of('tags', '7d1c'));
        self::assertSame(
            'resources/product/a%2Fb%3Fc%23d%20e%25/tags',
            Path::of('resources', 'product', 'a/b?c#d e%', 'tags'),
        );
        self::assertSame('resources/product/%C3%A9t%C3%A9/tags', Path::of('resources', 'product', 'été', 'tags'));
        // RFC 3986's unreserved characters stay as they are.
        self::assertSame('resources/a-b_c.d~e/x', Path::of('resources', 'a-b_c.d~e', 'x'));
        // Dots inside a segment are not a dot segment.
        self::assertSame('resources/product/.../tags', Path::of('resources', 'product', '...', 'tags'));
        self::assertSame('resources/product/.a./tags', Path::of('resources', 'product', '.a.', 'tags'));
    }

    /**
     * "." and ".." are valid resource types and ids on the server, so they are
     * sent, encoded whole: libcurl would remove them as dot segments, sending
     * resources/product/../tags to resources/tags.
     */
    public function testADotSegmentIsEncodedWhole(): void
    {
        self::assertSame('resources/product/%2E/tags', Path::of('resources', 'product', '.', 'tags'));
        self::assertSame('resources/product/%2E%2E/tags', Path::of('resources', 'product', '..', 'tags'));
        self::assertSame('resources/%2E%2E/%2E/audit-logs', Path::of('resources', '..', '.', 'audit-logs'));
    }

    /**
     * A resource whose id is ".." is addressed as itself, through the SDK's own
     * request.
     */
    public function testADotSegmentReachesTheClientAsOneSegment(): void
    {
        $client = $this->clientWith([self::captured('v2/resources/tags')]);

        $client->resources()->listTags('product', '..', 'harness-app');

        self::assertSame('/api/v2/resources/product/%2E%2E/tags', $this->lastRequest()->getUri()->getPath());
    }

    public function testAnEmptySegmentIsKeptAsItIs(): void
    {
        self::assertSame('tags/', Path::of('tags', ''));
        self::assertSame('resources/product//tags', Path::of('resources', 'product', '', 'tags'));
    }

    /**
     * An empty id is the server's to answer, not the SDK's to refuse (AGENTS.md,
     * Product Rules): it is sent, and the 404 the server answers it with, since
     * no route matches an empty segment, becomes a NotFoundException that names
     * no resource. The status alone chooses the exception, so the captured 404
     * stands in for the server's routing 404.
     *
     * @param \Closure(Client): void $call
     */
    #[DataProvider('emptyIdCalls')]
    public function testAnEmptyIdIsSentAndItsNotFoundIsRaised(\Closure $call, string $path): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        try {
            $call($client);
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertStringStartsWith('octonomy: the resource was not found', $e->getMessage());
            self::assertSame($path, $this->lastRequest()->getUri()->getPath());
        }
    }

    /**
     * @return iterable<string, array{\Closure(Client): void, string}>
     */
    public static function emptyIdCalls(): iterable
    {
        yield 'an empty tag id' => [
            static function (Client $c): void {
                $c->tags()->update('', new TagUpdate(description: 'Featured'));
            },
            '/api/v2/tags/',
        ];
        yield 'an empty vocabulary id' => [
            static function (Client $c): void {
                $c->vocabularies()->get('');
            },
            '/api/v2/vocabularies/',
        ];
        yield 'an empty resource id' => [
            static function (Client $c): void {
                $c->resources()->listTags('product', '', 'cms');
            },
            '/api/v2/resources/product//tags',
        ];
        yield 'an empty alias id' => [
            static function (Client $c): void {
                $c->aliases()->delete('');
            },
            '/api/v2/tag-aliases/',
        ];
    }
}
