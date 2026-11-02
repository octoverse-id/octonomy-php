<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagResolution;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TagService::resolve() (GET /tag-resolution). The v2 responses were captured
 * inside merchant A's namespace, so the calls that replay them name it too.
 *
 * The error envelopes were captured from the tag endpoints. The SDK maps an
 * error by its status and code alone, so they exercise resolve() the same way.
 */
final class TagResolutionTest extends SdkTestCase
{
    public function testResolvingATagsOwnSlug(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-resolution/tag')]);
        $data = self::capturedData('v2/tag-resolution/tag');

        $resolution = $client->tags()->resolve('sale', 'harness-app', 'label', options: self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/v2/tag-resolution', $request->getUri()->getPath());
        self::assertSame(['slug' => 'sale', 'type' => 'label', 'application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame('', $this->lastRawBody());

        self::assertSame(TagResolution::MATCHED_TAG, $resolution->matchedType);
        self::assertNull($resolution->matchedAlias);
        self::assertInstanceOf(Tag::class, $resolution->tag);
        self::assertSame($data['tag']['id'], $resolution->tag->id);
        self::assertSame('sale', $resolution->tag->slug);
        self::assertSame('merchant', $resolution->tag->namespaceType);
        self::assertSame($data['tag']['usage_count'], $resolution->tag->usageCount);
    }

    public function testResolvingAnAliasSlugReachesItsTag(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-resolution/alias')]);
        $data = self::capturedData('v2/tag-resolution/alias');

        $resolution = $client->tags()->resolve('promo', 'harness-app', options: self::merchantA());

        self::assertSame(['slug' => 'promo', 'application_id' => 'harness-app'], $this->lastQuery());
        self::assertSame(TagResolution::MATCHED_ALIAS, $resolution->matchedType);
        self::assertInstanceOf(TagAlias::class, $resolution->matchedAlias);
        self::assertSame($data['matched_alias']['id'], $resolution->matchedAlias->id);
        self::assertSame('promo', $resolution->matchedAlias->slug);
        self::assertSame($resolution->tag->id, $resolution->matchedAlias->tagId);
        self::assertSame('sale', $resolution->tag->slug);
    }

    public function testResolveSendsEveryParameter(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-resolution/tag')]);

        $client->tags()->resolve('sale', 'harness-app', 'label', TagResolution::SCOPE_MERCHANT, true, self::merchantA());

        self::assertSame([
            'slug' => 'sale',
            'type' => 'label',
            'scope' => 'merchant',
            'application_id' => 'harness-app',
            'include_global' => 'true',
        ], $this->lastQuery());
    }

    public function testResolveWithASlugAloneSendsTheSlugAlone(): void
    {
        $client = $this->clientWith([self::captured('v1/tag-resolution/alias')], ['api_version' => ApiVersion::V1]);

        $resolution = $client->tags()->resolve('scarlet');

        self::assertSame('/api/v1/tag-resolution', $this->lastRequest()->getUri()->getPath());
        self::assertSame('slug=scarlet', $this->lastRequest()->getUri()->getQuery());
        // v1 sends no namespace pair, on the alias or on the tag.
        self::assertSame(TagResolution::MATCHED_ALIAS, $resolution->matchedType);
        self::assertNotNull($resolution->matchedAlias);
        self::assertNull($resolution->matchedAlias->namespaceType);
        self::assertNull($resolution->tag->namespaceType);
    }

    public function testIncludeGlobalIsRefusedOnV1(): void
    {
        $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('octonomy: include_global requires api_version v2');

        $client->tags()->resolve('red', includeGlobal: true);
    }

    /**
     * A slug that matches nothing is the server's 400.
     */
    public function testANoMatchIsAValidationException(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/validation-error')]);

        try {
            $client->tags()->resolve('no-such-slug', 'harness-app', options: self::merchantA());
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame('/api/v2/tag-resolution', $e->context->path);
            self::assertSame(['slug' => 'no-such-slug', 'application_id' => 'harness-app'], $e->context->query);
        }
    }

    public function testANotFoundMapsToNotFoundException(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        $this->expectException(NotFoundException::class);
        $client->tags()->resolve('sale', 'harness-app', options: self::merchantA());
    }

    public function testAConflictMapsToConflictException(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/conflict')]);

        $this->expectException(ConflictException::class);
        $client->tags()->resolve('sale', 'harness-app', options: self::merchantA());
    }

    /**
     * The nested alias and tag hydrate as their own models do, so a field
     * either lacks is reported at its path through the resolution.
     *
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $break
     */
    #[DataProvider('nestedFaults')]
    public function testANestedFaultIsReportedAtItsPath(string $fixture, string $kind, \Closure $break, string $expected): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom($fixture, $kind, $break)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v2/tag-resolution (api_version v2): ' . $expected,
        );

        $client->tags()->resolve('promo', 'harness-app', options: self::merchantA());
    }

    /**
     * @return iterable<string, array{string, string, \Closure(array<array-key, mixed>): array<array-key, mixed>, string}>
     */
    public static function nestedFaults(): iterable
    {
        yield 'a field the tag lacks' => ['v2/tag-resolution/alias', SyntheticNegative::MISSING_FIELD, static function (array $body): array {
            unset($body['data']['tag']['slug']);

            return $body;
        }, 'field "data.tag.slug" is missing'];
        yield 'a field the alias lacks' => ['v2/tag-resolution/alias', SyntheticNegative::MISSING_FIELD, static function (array $body): array {
            unset($body['data']['matched_alias']['tag_id']);

            return $body;
        }, 'field "data.matched_alias.tag_id" is missing'];
        yield 'an alias that is not an object' => ['v2/tag-resolution/alias', SyntheticNegative::WRONG_TYPE, static function (array $body): array {
            $body['data']['matched_alias'] = 'promo';

            return $body;
        }, 'field "data.matched_alias" must be an object or null, got a string'];
        yield 'a tag that is null' => ['v2/tag-resolution/tag', SyntheticNegative::WRONG_TYPE, static function (array $body): array {
            $body['data']['tag'] = null;

            return $body;
        }, 'field "data.tag" must be an object, got null'];
        yield 'an absent matched_alias, which the server always sends' => ['v2/tag-resolution/tag', SyntheticNegative::MISSING_FIELD, static function (array $body): array {
            unset($body['data']['matched_alias']);

            return $body;
        }, 'field "data.matched_alias" is missing'];
    }

    /**
     * The namespace the v2 responses were captured in.
     */
    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
