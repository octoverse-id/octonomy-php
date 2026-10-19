<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression test for issue #14 (mandated; do not skip or delete).
 *
 * Hydration used to default an absent or mistyped field to a zero value ('',
 * false, 0, 1970-01-01) and to coerce "5" into 5, so contract drift produced a
 * plausible model instead of an error. Now every field declared for the
 * response's api_version must be present as a key, nullability governs the
 * value only, and a wrong type raises.
 *
 * The declared fields are the model's constructor parameters, read here by
 * reflection rather than restated, so a field added to a model is covered
 * without touching this test. Each field's key is its parameter name in
 * snake_case.
 */
final class StrictHydrationRegressionTest extends SdkTestCase
{
    /**
     * The fields the server omits, rather than sends as null, when they have no
     * value: Tag's two named exceptions to key presence (see Tag::fromArray).
     */
    private const OMITTED_WHEN_NULL = [Tag::class => ['parent_id', 'vocabulary_id'], Vocabulary::class => []];

    /** Declared for api_version v2 only. */
    private const NAMESPACE_PAIR = ['namespace_type', 'namespace_id'];

    /**
     * The mandated case: a declared key absent raises MalformedResponseException
     * naming the field and the endpoint. Nullable fields are not exempt, since
     * the server always emits them; description is nullable.
     *
     * @param \Closure(Client): object $call
     */
    #[DataProvider('getCalls')]
    public function testAnAbsentNullableKeyRaisesNamingTheFieldAndEndpoint(string $fixture, string $path, \Closure $call): void
    {
        self::assertArrayHasKey('description', self::capturedData($fixture));
        $client = $this->clientWith([self::syntheticNegativeFrom($fixture, SyntheticNegative::MISSING_FIELD, static function (array $body): array {
            unset($body['data']['description']);

            return $body;
        })]);

        try {
            $call($client);
            self::fail('expected a MalformedResponseException');
        } catch (MalformedResponseException $e) {
            self::assertSame(
                "octonomy: malformed response from GET {$path} (api_version v1): field \"data.description\" is missing",
                $e->getMessage(),
            );
            self::assertSame('data.description', $e->field);
        }
    }

    /**
     * The mandated case's other half: an explicit null on a nullable field
     * yields null. Read from a captured list, since a real server sends these.
     *
     * @param \Closure(Client): list<Tag|Vocabulary> $list
     */
    #[DataProvider('listCalls')]
    public function testAnExplicitNullOnANullableFieldYieldsNull(string $fixture, string $slug, string $key, \Closure $list): void
    {
        $rows = array_column(self::capturedData($fixture), null, 'slug');
        self::assertArrayHasKey($key, $rows[$slug], "{$fixture} must send {$slug}'s {$key} for this test to mean anything");
        self::assertNull($rows[$slug][$key]);

        $bySlug = [];
        foreach ($list($this->clientWith([self::captured($fixture)])) as $model) {
            $bySlug[$model->slug] = $model;
        }

        self::assertNull($bySlug[$slug]->{self::property($key)});
    }

    /**
     * @return iterable<string, array{string, string, string, \Closure(Client): list<Tag|Vocabulary>}>
     */
    public static function listCalls(): iterable
    {
        $tags = static fn(Client $c): array => $c->tags()->list()->data;
        $vocabularies = static fn(Client $c): array => $c->vocabularies()->list()->data;

        yield 'tag application_id' => ['v1/tags/list', 'green', 'application_id', $tags];
        yield 'tag description' => ['v1/tags/list', 'green', 'description', $tags];
        yield 'vocabulary application_id' => ['v1/vocabularies/list', 'colors', 'application_id', $vocabularies];
        yield 'vocabulary description' => ['v1/vocabularies/list', 'sizes', 'description', $vocabularies];
    }

    /**
     * Every declared field, one at a time, removed from an otherwise captured
     * response, raises naming that field. The provider leaves out only what a
     * conforming server does omit, which the captured-response tests below
     * cover instead: Tag's two fields omitted when null, and the namespace pair
     * on v1, which v1 does not declare.
     *
     * @param class-string<Tag|Vocabulary> $model
     */
    #[DataProvider('requiredFields')]
    public function testEveryRequiredFieldMustBePresent(string $model, string $apiVersion, string $key): void
    {
        $fixture = self::getFixture($model, $apiVersion);
        self::assertArrayHasKey($key, self::capturedData($fixture));
        $transport = $this->transportWith([self::syntheticNegativeFrom($fixture, SyntheticNegative::MISSING_FIELD, static function (array $body) use ($key): array {
            unset($body['data'][$key]);

            return $body;
        })], $apiVersion);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage("(api_version {$apiVersion}): field \"data.{$key}\" is missing");

        $transport->one('GET', 'any', self::hydrator($model));
    }

    /**
     * Every declared field, one at a time, set to a value of another JSON type
     * than its declared one, null included where the field is not nullable.
     *
     * @param class-string<Tag|Vocabulary> $model
     */
    #[DataProvider('mistypedFields')]
    public function testEveryDeclaredFieldRejectsAnotherType(string $model, string $key, mixed $value, string $expected): void
    {
        $transport = $this->transportWith([self::syntheticNegativeFrom(
            self::getFixture($model, ApiVersion::V2),
            SyntheticNegative::WRONG_TYPE,
            static function (array $body) use ($key, $value): array {
                $body['data'][$key] = $value;

                return $body;
            },
        )], ApiVersion::V2);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage("field \"data.{$key}\" must be {$expected}");

        $transport->one('GET', 'any', self::hydrator($model));
    }

    /**
     * "5" where an integer is declared: the coercion #14 removed.
     */
    public function testANumericStringIsNotAnInteger(): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom('v1/tags/get', SyntheticNegative::WRONG_TYPE, static function (array $body): array {
            $body['data']['usage_count'] = '5';

            return $body;
        })]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v1/tags/tag_1 (api_version v1): field "data.usage_count" must be an integer, got a string',
        );

        $client->tags()->get('tag_1');
    }

    /**
     * Strings that are no RFC 3339 timestamp. Before #14 these became 1970-01-01;
     * "yesterday" and "now" would also pass a bare \DateTimeImmutable parse.
     */
    #[DataProvider('unparseableTimestamps')]
    public function testAnUnparseableTimestampRaises(string $timestamp): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom('v1/tags/get', SyntheticNegative::WRONG_TYPE, static function (array $body) use ($timestamp): array {
            $body['data']['created_at'] = $timestamp;

            return $body;
        })]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(sprintf('field "data.created_at" must be an RFC 3339 timestamp, got "%s"', $timestamp));

        $client->tags()->get('tag_1');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparseableTimestamps(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['yesterday'];
        yield 'now' => ['now'];
        yield 'date only' => ['2026-01-01'];
        yield 'no offset' => ['2026-01-01T00:00:00'];
        yield 'space separator' => ['2026-01-01 00:00:00Z'];
        yield 'no such day' => ['2026-02-30T00:00:00Z'];
        yield 'no such hour' => ['2026-01-01T24:00:00Z'];
    }

    /**
     * v1 never sends the namespace pair (octonomy/core/serializers.py strips
     * it), so a v1 body that carries it is drift. Replayed from a real v2
     * response.
     */
    public function testAV1ResponseCarryingTheNamespacePairRaises(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v1/tags/tag_1 (api_version v1): field "data.namespace_type" is not part of an api_version v1 response',
        );

        $client->tags()->get('tag_1');
    }

    /**
     * v2 always sends the pair, null for a global row, so a v2 body without it
     * is drift. Replayed from a real v1 response.
     *
     * @param class-string<Tag|Vocabulary> $model
     */
    #[DataProvider('models')]
    public function testAV2ResponseMissingTheNamespacePairRaises(string $model): void
    {
        $transport = $this->transportWith([self::captured(self::getFixture($model, ApiVersion::V1))], ApiVersion::V2);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('octonomy: malformed response from GET /api/v2/any (api_version v2): field "data.namespace_type" is missing');

        $transport->one('GET', 'any', self::hydrator($model));
    }

    /**
     * A v1 response without the pair is correct, and the model reads null.
     *
     * @param class-string<Tag|Vocabulary> $model
     */
    #[DataProvider('models')]
    public function testAV1ResponseWithoutTheNamespacePairHydrates(string $model): void
    {
        $fixture = self::getFixture($model, ApiVersion::V1);
        self::assertSame([], array_intersect(self::NAMESPACE_PAIR, array_keys(self::capturedData($fixture))));

        $hydrated = $this->transportWith([self::captured($fixture)])->one('GET', 'any', self::hydrator($model));

        self::assertNull($hydrated->namespaceType);
        self::assertNull($hydrated->namespaceId);
    }

    /**
     * The server omits parent_id and vocabulary_id when they are null. That is
     * a named exception, so an absent key hydrates to null; the captured rows
     * prove the server really sends them this way.
     */
    public function testATagWithoutAParentOrVocabularyHydratesBothAsNull(): void
    {
        $rows = array_column(self::capturedData('v2/tags/list'), null, 'slug');
        self::assertArrayNotHasKey('parent_id', $rows['sale']);
        self::assertArrayNotHasKey('vocabulary_id', $rows['sale']);

        $page = $this->transportWith([self::captured('v2/tags/list')], ApiVersion::V2)->many('GET', 'tags', Tag::fromArray(...));

        $sale = array_values(array_filter($page->data, static fn(Tag $t): bool => $t->slug === 'sale'))[0];
        self::assertNull($sale->parentId);
        self::assertNull($sale->vocabularyId);
    }

    /**
     * A model hydrated outside a request reports the field by its key alone,
     * with the api_version it was read as.
     */
    public function testAModelHydratedDirectlyNamesTheFieldAndApiVersion(): void
    {
        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response (api_version v1): field "namespace_type" is not part of an api_version v1 response',
        );

        Tag::fromArray(self::capturedData('v2/tags/get'), ApiVersion::V1);
    }

    public function testAnUnknownApiVersionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('octonomy: unknown api_version "v3"; expected "v1" or "v2"');

        Tag::fromArray(self::capturedData('v1/tags/get'), 'v3');
    }

    /**
     * @return iterable<string, array{string, string, \Closure(Client): object}>
     */
    public static function getCalls(): iterable
    {
        yield 'tags()->get()' => ['v1/tags/get', '/api/v1/tags/tag_1', static fn(Client $c): Tag => $c->tags()->get('tag_1')];
        yield 'vocabularies()->get()' => ['v1/vocabularies/get', '/api/v1/vocabularies/voc_1', static fn(Client $c): Vocabulary => $c->vocabularies()->get('voc_1')];
    }

    /**
     * @return iterable<string, array{class-string<Tag|Vocabulary>}>
     */
    public static function models(): iterable
    {
        yield 'Tag' => [Tag::class];
        yield 'Vocabulary' => [Vocabulary::class];
    }

    /**
     * Every declared field of each model on each api_version, less the ones a
     * conforming server omits.
     *
     * @return iterable<string, array{class-string<Tag|Vocabulary>, string, string}>
     */
    public static function requiredFields(): iterable
    {
        foreach ([Tag::class, Vocabulary::class] as $model) {
            foreach ([ApiVersion::V1, ApiVersion::V2] as $apiVersion) {
                foreach (self::parameters($model) as $parameter) {
                    $key = self::key($parameter->getName());
                    if (in_array($key, self::OMITTED_WHEN_NULL[$model], true)
                        || ($apiVersion === ApiVersion::V1 && in_array($key, self::NAMESPACE_PAIR, true))) {
                        continue;
                    }
                    yield "{$apiVersion} " . (new \ReflectionClass($model))->getShortName() . ".{$key}" => [$model, $apiVersion, $key];
                }
            }
        }
    }

    /**
     * For each declared field, a value of another JSON type than the one its
     * parameter declares: one per wrong type, plus null for a field that is not
     * nullable.
     *
     * @return iterable<string, array{class-string<Tag|Vocabulary>, string, mixed, string}>
     */
    public static function mistypedFields(): iterable
    {
        foreach ([Tag::class, Vocabulary::class] as $model) {
            foreach (self::parameters($model) as $parameter) {
                $type = $parameter->getType();
                self::assertInstanceOf(\ReflectionNamedType::class, $type);
                $declared = $type->getName();
                [$expected, $wrong] = match ($declared) {
                    'string' => ['a string', [5, true, ['x' => 1]]],
                    'int' => ['an integer', ['5', 5.0, true]],
                    'bool' => ['a boolean', ['true', 1, 0]],
                    'array' => ['an object', ['{}', 1, true]],
                    \DateTimeImmutable::class => ['an RFC 3339 timestamp', [1767225600, true]],
                    default => throw new \LogicException("no wrong values for {$declared}: add them here"),
                };
                if ($type->allowsNull()) {
                    $expected .= ' or null';
                } else {
                    $wrong[] = null;
                }
                $key = self::key($parameter->getName());
                $short = (new \ReflectionClass($model))->getShortName();
                foreach ($wrong as $value) {
                    yield "{$short}.{$key} = " . json_encode($value) => [$model, $key, $value, $expected];
                }
            }
        }
    }

    /**
     * The model's constructor parameters: its declared fields.
     *
     * @param class-string $model
     *
     * @return list<\ReflectionParameter>
     */
    private static function parameters(string $model): array
    {
        $constructor = (new \ReflectionClass($model))->getConstructor();
        self::assertNotNull($constructor);

        return $constructor->getParameters();
    }

    /**
     * The response key for a constructor parameter: createdAt is created_at.
     */
    private static function key(string $parameter): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', $parameter));
    }

    /**
     * The model property for a response key: created_at is createdAt.
     */
    private static function property(string $key): string
    {
        return lcfirst(str_replace('_', '', ucwords($key, '_')));
    }

    /**
     * @param class-string<Tag|Vocabulary> $model
     *
     * @return \Closure(array<string, mixed>, string): (Tag|Vocabulary)
     */
    private static function hydrator(string $model): \Closure
    {
        return $model === Tag::class ? Tag::fromArray(...) : Vocabulary::fromArray(...);
    }

    /**
     * A captured get of $model from $apiVersion, which sets every field.
     */
    private static function getFixture(string $model, string $apiVersion): string
    {
        return $apiVersion . ($model === Tag::class ? '/tags/get' : '/vocabularies/get');
    }
}
