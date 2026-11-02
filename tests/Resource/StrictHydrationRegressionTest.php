<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Model\Assignment;
use Octoverse\Octonomy\Model\AuditLog;
use Octoverse\Octonomy\Model\BulkAssignResult;
use Octoverse\Octonomy\Model\BulkRemoveResult;
use Octoverse\Octonomy\Model\ResourceTag;
use Octoverse\Octonomy\Model\ResourceTagsReplaceResult;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagResolution;
use Octoverse\Octonomy\Model\TagResource;
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
 * snake_case. Every response model is listed in MODELS, so a model added
 * without a row there is not covered: add it.
 */
final class StrictHydrationRegressionTest extends SdkTestCase
{
    /**
     * Every response model, with the captured operation whose response sets
     * every field the model declares, on both api_versions, and where one model
     * sits in that body: the whole "data" object (one), or the first row of a
     * list (many).
     */
    private const MODELS = [
        Tag::class => ['tags/get', 'one'],
        Vocabulary::class => ['vocabularies/get', 'one'],
        TagAlias::class => ['tag-aliases/get', 'one'],
        TagResolution::class => ['tag-resolution/alias', 'one'],
        Assignment::class => ['tag-assignments/create', 'one'],
        BulkAssignResult::class => ['tag-assignments/bulk-assign', 'one'],
        BulkRemoveResult::class => ['tag-assignments/bulk-remove', 'one'],
        ResourceTagsReplaceResult::class => ['resources/replace', 'one'],
        ResourceTag::class => ['resources/tags', 'many'],
        TagResource::class => ['tags/resources', 'many'],
        AuditLog::class => ['audit-logs/list', 'many'],
    ];

    /**
     * The fields the server omits, rather than sends as null, when they have no
     * value: Tag's two named exceptions to key presence (see Tag::fromArray).
     */
    private const OMITTED_WHEN_NULL = [Tag::class => ['parent_id', 'vocabulary_id']];

    /**
     * The array fields that hold a JSON list of nested models, rather than a
     * JSON object of caller-written members, as every other array field does.
     */
    private const LIST_FIELDS = [BulkAssignResult::class => ['assignments'], ResourceTagsReplaceResult::class => ['tags']];

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
        })], ['api_version' => ApiVersion::V1]);

        try {
            $call($client);
            self::fail('expected a MalformedResponseException');
        } catch (MalformedResponseException $e) {
            self::assertSame(
                "octonomy: malformed response from GET {$path} (api_version v1): field \"data.description\" is missing."
                . ' The SDK cannot tell which side is out of step. Fix: check that base_url is the Octonomy API\'s address'
                . " and that this SDK release supports the server's version; if both hold, report the response to the"
                . " SDK's maintainers as a contract mismatch.",
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
        foreach ($list($this->clientWith([self::captured($fixture)], ['api_version' => ApiVersion::V1])) as $model) {
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
     * @param class-string $model
     */
    #[DataProvider('requiredFields')]
    public function testEveryRequiredFieldMustBePresent(string $model, string $apiVersion, string $key): void
    {
        $fixture = self::fixture($model, $apiVersion);
        $row = self::row($model, self::capturedData($fixture));
        self::assertArrayHasKey($key, $row);
        $transport = $this->transportWith([self::syntheticNegativeFrom($fixture, SyntheticNegative::MISSING_FIELD, static fn(array $body): array => self::editRow($model, $body, static function (array $row) use ($key): array {
            unset($row[$key]);

            return $row;
        }))], $apiVersion);

        $this->expectException(MalformedResponseException::class);
        // A model with one field (BulkRemoveResult) is left empty, which the
        // envelope check refuses before the model reads it.
        $this->expectExceptionMessage(count($row) === 1
            ? sprintf('(api_version %s): expected a non-empty JSON object under "data"', $apiVersion)
            : sprintf('(api_version %s): field "%s.%s" is missing', $apiVersion, self::placeOf($model), $key));

        self::read($transport, $model);
    }

    /**
     * Every declared field, one at a time, set to a value of another JSON type
     * than its declared one, null included where the field is not nullable.
     *
     * @param class-string $model
     */
    #[DataProvider('mistypedFields')]
    public function testEveryDeclaredFieldRejectsAnotherType(string $model, string $key, mixed $value, string $expected): void
    {
        $transport = $this->transportWith([self::syntheticNegativeFrom(
            self::fixture($model, ApiVersion::V2),
            SyntheticNegative::WRONG_TYPE,
            static fn(array $body): array => self::editRow($model, $body, static function (array $row) use ($key, $value): array {
                $row[$key] = $value;

                return $row;
            }),
        )], ApiVersion::V2);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(sprintf('field "%s.%s" must be %s', self::placeOf($model), $key, $expected));

        self::read($transport, $model);
    }

    /**
     * "5" where an integer is declared: the coercion #14 removed.
     */
    public function testANumericStringIsNotAnInteger(): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom('v1/tags/get', SyntheticNegative::WRONG_TYPE, static function (array $body): array {
            $body['data']['usage_count'] = '5';

            return $body;
        })], ['api_version' => ApiVersion::V1]);

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
        })], ['api_version' => ApiVersion::V1]);

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
     * it), so a v1 body that carries it, even as nulls, is drift.
     *
     * @param array{namespace_type: string|null, namespace_id: string|null} $pair
     */
    #[DataProvider('namespacePairs')]
    public function testAV1ResponseCarryingTheNamespacePairRaises(array $pair): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom('v1/tags/get', SyntheticNegative::UNDECLARED_FIELD, static function (array $body) use ($pair): array {
            $body['data'] += $pair;

            return $body;
        })], ['api_version' => ApiVersion::V1]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v1/tags/tag_1 (api_version v1): field "data.namespace_type" is not part of an api_version v1 response',
        );

        $client->tags()->get('tag_1');
    }

    /**
     * @return iterable<string, array{array{namespace_type: string|null, namespace_id: string|null}}>
     */
    public static function namespacePairs(): iterable
    {
        yield 'a namespaced pair' => [['namespace_type' => 'merchant', 'namespace_id' => 'harness-merchant-a']];
        yield 'a global pair' => [['namespace_type' => null, 'namespace_id' => null]];
    }

    /**
     * v2 always sends the pair, null for a global row, so a v2 body without it
     * is drift. (testEveryRequiredFieldMustBePresent removes each half alone.)
     *
     * @param class-string $model
     */
    #[DataProvider('models')]
    public function testAV2ResponseMissingTheNamespacePairRaises(string $model): void
    {
        $transport = $this->transportWith([self::syntheticNegativeFrom(
            self::fixture($model, ApiVersion::V2),
            SyntheticNegative::MISSING_FIELD,
            static fn(array $body): array => self::editRow($model, $body, static function (array $row): array {
                unset($row['namespace_type'], $row['namespace_id']);

                return $row;
            }),
        )], ApiVersion::V2);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(sprintf(
            'octonomy: malformed response from GET /api/v2/any (api_version v2): field "%s.namespace_type" is missing',
            self::placeOf($model),
        ));

        self::read($transport, $model);
    }

    /**
     * A v1 response without the pair is correct, and the model reads null.
     *
     * @param class-string $model
     */
    #[DataProvider('models')]
    public function testAV1ResponseWithoutTheNamespacePairHydrates(string $model): void
    {
        $fixture = self::fixture($model, ApiVersion::V1);
        self::assertSame([], array_intersect(self::NAMESPACE_PAIR, array_keys(self::row($model, self::capturedData($fixture)))));

        $hydrated = self::read($this->transportWith([self::captured($fixture)], ApiVersion::V1), $model);

        self::assertTrue(property_exists($hydrated, 'namespaceType') && property_exists($hydrated, 'namespaceId'));
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
     * with the api_version it was read as, whichever reader rejected it.
     */
    #[DataProvider('directFaults')]
    public function testAModelHydratedDirectlyNamesTheFieldAndApiVersion(string $kind, \Closure $break, string $expected): void
    {
        $members = self::members(self::syntheticNegativeFrom('v1/tags/get', $kind, $break));

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('octonomy: malformed response (api_version v1): ' . $expected);

        Tag::fromArray($members, ApiVersion::V1);
    }

    /**
     * @return iterable<string, array{string, \Closure(array<array-key, mixed>): array<array-key, mixed>, string}>
     */
    public static function directFaults(): iterable
    {
        yield 'missing field' => [SyntheticNegative::MISSING_FIELD, static function (array $body): array {
            unset($body['data']['description']);

            return $body;
        }, 'field "description" is missing'];
        yield 'wrong type' => [SyntheticNegative::WRONG_TYPE, static function (array $body): array {
            $body['data']['usage_count'] = '5';

            return $body;
        }, 'field "usage_count" must be an integer, got a string'];
        yield 'undeclared field' => [SyntheticNegative::UNDECLARED_FIELD, static function (array $body): array {
            $body['data'] += ['namespace_type' => 'merchant', 'namespace_id' => 'harness-merchant-a'];

            return $body;
        }, 'field "namespace_type" is not part of an api_version v1 response'];
    }

    /**
     * metadata is a JSON object. The server sends {} for none, and that
     * hydrates to an empty array; a JSON list, even an empty one, is rejected
     * (see testEveryDeclaredFieldRejectsAnotherType), which is why the readers
     * see nested objects before they are turned into arrays.
     */
    public function testAnEmptyMetadataObjectHydratesAsAnEmptyArray(): void
    {
        $captured = self::captured('v1/tags/list');
        self::assertStringContainsString('"metadata":{}', $captured->body);

        $page = $this->clientWith([$captured], ['api_version' => ApiVersion::V1])->tags()->list();

        $green = array_values(array_filter($page->data, static fn(Tag $t): bool => $t->slug === 'green'))[0];
        self::assertSame([], $green->metadata);
    }

    public function testAnUnknownApiVersionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('octonomy: unknown api_version "v3"; expected "v1" or "v2"');

        Tag::fromArray(self::members(self::captured('v1/tags/get')), 'v3');
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
     * Every model that declares the namespace pair.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function models(): iterable
    {
        foreach (array_keys(self::MODELS) as $model) {
            $keys = array_map(static fn(\ReflectionParameter $p): string => self::key($p->getName()), self::parameters($model));
            if (array_intersect(self::NAMESPACE_PAIR, $keys) !== []) {
                yield (new \ReflectionClass($model))->getShortName() => [$model];
            }
        }
    }

    /**
     * Every declared field of each model on each api_version, less the ones a
     * conforming server omits.
     *
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function requiredFields(): iterable
    {
        foreach (array_keys(self::MODELS) as $model) {
            foreach ([ApiVersion::V1, ApiVersion::V2] as $apiVersion) {
                foreach (self::parameters($model) as $parameter) {
                    $key = self::key($parameter->getName());
                    if (in_array($key, self::OMITTED_WHEN_NULL[$model] ?? [], true)
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
     * nullable. A nested model is an object, and a list of them a list.
     *
     * @return iterable<string, array{class-string, string, mixed, string}>
     */
    public static function mistypedFields(): iterable
    {
        foreach (array_keys(self::MODELS) as $model) {
            foreach (self::parameters($model) as $parameter) {
                $type = $parameter->getType();
                self::assertInstanceOf(\ReflectionNamedType::class, $type);
                $declared = $type->getName();
                if ($declared === 'array' && in_array(self::key($parameter->getName()), self::LIST_FIELDS[$model] ?? [], true)) {
                    $declared = 'list';
                }
                [$expected, $wrong] = match ($declared) {
                    'string' => ['a string', [5, true, ['x' => 1]]],
                    'int' => ['an integer', ['5', 5.0, true]],
                    'bool' => ['a boolean', ['true', 1, 0]],
                    'array' => ['an object', [['not-an-object'], [], '{}', 1]],
                    'list' => ['a list', [['x' => 1], 'x', 1, true]],
                    \DateTimeImmutable::class => ['an RFC 3339 timestamp', [1767225600, true]],
                    Tag::class, TagAlias::class => ['an object', [['not-an-object'], [], 'x', 1, true]],
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
     * The captured response of $model's operation on $apiVersion (MODELS).
     */
    private static function fixture(string $model, string $apiVersion): string
    {
        return $apiVersion . '/' . self::MODELS[$model][0];
    }

    /**
     * Where one $model sits in its fixture's body, as a field path starts.
     */
    private static function placeOf(string $model): string
    {
        return self::MODELS[$model][1] === 'one' ? 'data' : 'data[0]';
    }

    /**
     * The one $model in a fixture's "data" member, decoded to arrays.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function row(string $model, array $data): array
    {
        $row = self::MODELS[$model][1] === 'one' ? $data : $data[0];
        self::assertIsArray($row);

        return $row;
    }

    /**
     * $body with the one $model in it changed by $edit.
     *
     * @param array<array-key, mixed>                                     $body
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $edit
     *
     * @return array<array-key, mixed>
     */
    private static function editRow(string $model, array $body, \Closure $edit): array
    {
        if (self::MODELS[$model][1] === 'one') {
            $body['data'] = $edit($body['data']);
        } else {
            $body['data'][0] = $edit($body['data'][0]);
        }

        return $body;
    }

    /**
     * Read the one $model from the response $transport replays, through the
     * Transport method its shape calls for.
     *
     * @param class-string $model
     */
    private static function read(Transport $transport, string $model): object
    {
        $hydrate = match ($model) {
            Tag::class => Tag::fromArray(...),
            Vocabulary::class => Vocabulary::fromArray(...),
            TagAlias::class => TagAlias::fromArray(...),
            TagResolution::class => TagResolution::fromArray(...),
            Assignment::class => Assignment::fromArray(...),
            BulkAssignResult::class => BulkAssignResult::fromArray(...),
            BulkRemoveResult::class => BulkRemoveResult::fromArray(...),
            ResourceTagsReplaceResult::class => ResourceTagsReplaceResult::fromArray(...),
            ResourceTag::class => ResourceTag::fromArray(...),
            TagResource::class => TagResource::fromArray(...),
            AuditLog::class => AuditLog::fromArray(...),
            default => throw new \LogicException("no hydrator for {$model}: add it here and to MODELS"),
        };
        if (self::MODELS[$model][1] === 'one') {
            $one = $transport->one('GET', 'any', $hydrate);
            self::assertIsObject($one);

            return $one;
        }
        $first = $transport->many('GET', 'any', $hydrate)->data[0];
        self::assertIsObject($first);

        return $first;
    }
}
