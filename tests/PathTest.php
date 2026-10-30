<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Internal\Path;
use Octoverse\Octonomy\Model\TagUpdate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Path::of() builds every path a service sends below the api_version prefix
 * (#23): each segment percent-encoded, so a value cannot add a segment of its
 * own, and a segment no URL can carry refused before anything is sent.
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
    }

    /**
     * Only an empty segment and a dot segment are refused: no encoding carries
     * either.
     */
    #[DataProvider('unaddressable')]
    public function testASegmentNoUrlCanCarryIsRefused(string $segment, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Path::of('resources', 'product', $segment, 'tags');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unaddressable(): iterable
    {
        yield 'empty' => ['', 'octonomy: an id or another path segment is empty, so the request would name no single'
            . ' resource. Nothing was sent. Fix: pass the id of the resource you mean.'];
        $dots = ' which a URL path cannot carry: an HTTP client or a proxy removes a dot segment (RFC 3986, section'
            . ' 5.2.4), which would send the request to another endpoint. Nothing was sent. Fix: pass the id of the'
            . ' resource you mean. A resource whose type or id is "." or ".." can still be assigned tags through'
            . ' assignments(), which send it in the body, but no endpoint that names it in the path can reach it.';
        yield 'one dot' => ['.', 'octonomy: an id or another path segment is ".",' . $dots];
        yield 'two dots' => ['..', 'octonomy: an id or another path segment is "..",' . $dots];
    }

    /**
     * A service refuses the call before sending anything, from frames that
     * keep none of their arguments: the service's frame holds the body.
     *
     * @param \Closure(Client): void $call
     */
    #[DataProvider('serviceCalls')]
    public function testAServiceRefusesTheCallBeforeSendingIt(\Closure $call, string $frame): void
    {
        $client = $this->clientWith([]);

        try {
            $call($client);
            self::fail('expected an \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith('octonomy: an id or another path segment is ', $e->getMessage());
            $this->assertNothingSent();
            $frames = [];
            foreach ($e->getTrace() as $f) {
                $name = ($f['class'] ?? '') . '::' . $f['function'];
                $frames[] = $name;
                if (str_starts_with($name, 'Octoverse\\Octonomy\\')) {
                    self::assertArrayNotHasKey('args', $f, "{$name} kept its arguments");
                }
            }
            self::assertContains($frame, $frames);
        }
    }

    /**
     * @return iterable<string, array{\Closure(Client): void, string}>
     */
    public static function serviceCalls(): iterable
    {
        yield 'an empty tag id' => [
            static function (Client $c): void {
                $c->tags()->update('', new TagUpdate(description: 'secret-ish'));
            },
            'Octoverse\\Octonomy\\Resource\\TagService::update',
        ];
        yield 'a dot vocabulary id' => [
            static function (Client $c): void {
                $c->vocabularies()->get('.');
            },
            'Octoverse\\Octonomy\\Resource\\VocabularyService::get',
        ];
        yield 'a dot-dot resource id' => [
            static function (Client $c): void {
                $c->resources()->listTags('product', '..', 'cms');
            },
            'Octoverse\\Octonomy\\Resource\\ResourceService::listTags',
        ];
        yield 'an empty alias id' => [
            static function (Client $c): void {
                $c->aliases()->delete('');
            },
            'Octoverse\\Octonomy\\Resource\\TagAliasService::delete',
        ];
    }
}
