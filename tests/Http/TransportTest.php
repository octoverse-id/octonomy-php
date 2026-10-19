<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Http;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the three ways Transport reads a 2xx — one(), many(), none() — the
 * envelope each one enforces, and how a field the hydrator rejects is placed in
 * the response it came from. Well-formed responses are captured from a real
 * server; the malformed ones are synthetic negatives, because a conforming
 * server cannot produce them.
 */
final class TransportTest extends SdkTestCase
{
    public function testOneUnwrapsTheDataObject(): void
    {
        $transport = $this->transportWith([self::captured('v1/tags/get')]);

        $data = $transport->one('GET', 'tags/tag_1', self::asDecoded());

        self::assertSame(self::capturedData('v1/tags/get'), $data);
        // Nested objects come back as arrays, as models expect.
        self::assertSame(['color' => 'red'], $data['metadata']);
    }

    #[DataProvider('bodiesWithoutDataObject')]
    public function testOneRejectsABodyWithoutADataObject(string $kind, string $raw): void
    {
        $transport = $this->transportWith([self::syntheticNegative($kind, 200, $raw)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v1/tags/tag_1 (api_version v1): expected a non-empty JSON object under "data"',
        );

        $transport->one('GET', 'tags/tag_1', self::asDecoded());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesWithoutDataObject(): iterable
    {
        yield 'unwrapped resource (the pre-fix fixture shape)' => [SyntheticNegative::MISSING_ENVELOPE, '{"id":"tag_1","name":"Featured"}'];
        yield 'empty body' => [SyntheticNegative::MISSING_ENVELOPE, ''];
        yield 'data is null' => [SyntheticNegative::WRONG_TYPE, '{"data":null}'];
        yield 'data is an empty object' => [SyntheticNegative::MISSING_FIELD, '{"data":{}}'];
        yield 'data is an empty list' => [SyntheticNegative::WRONG_TYPE, '{"data":[]}'];
        yield 'data is a list' => [SyntheticNegative::WRONG_TYPE, '{"data":[{"id":"tag_1"}]}'];
        yield 'data is a scalar' => [SyntheticNegative::WRONG_TYPE, '{"data":"tag_1"}'];
        yield 'body is a JSON list' => [SyntheticNegative::MISSING_ENVELOPE, '[{"id":"tag_1"}]'];
    }

    public function testOneRaisesTransportExceptionForANonJson2xx(): void
    {
        $transport = $this->transportWith([
            self::syntheticNegative(SyntheticNegative::GATEWAY, 200, '<html>proxy</html>', ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(TransportException::class);

        $transport->one('GET', 'tags/tag_1', self::asDecoded());
    }

    public function testOneRaisesTheTypedApiExceptionForANon2xx(): void
    {
        $transport = $this->transportWith([self::captured('v1/errors/not-found')]);

        $this->expectException(NotFoundException::class);

        $transport->one('GET', 'tags/missing', self::asDecoded());
    }

    public function testOneHydratesWithItsApiVersionAndCallsThatSurface(): void
    {
        $transport = $this->transportWith([self::captured('v2/tags/get')], ApiVersion::V2);

        $tag = $transport->one('GET', 'tags/tag_1', Tag::fromArray(...));

        self::assertSame('/api/v2/tags/tag_1', $this->lastRequest()->getUri()->getPath());
        self::assertSame(self::capturedData('v2/tags/get')['namespace_type'], $tag->namespaceType);
        self::assertSame(self::capturedData('v2/tags/get')['namespace_id'], $tag->namespaceId);
    }

    public function testOnePlacesAFieldTheHydratorRejectsAtTheRequest(): void
    {
        $transport = $this->transportWith([self::syntheticNegativeFrom(
            'v1/tags/get',
            SyntheticNegative::WRONG_TYPE,
            static fn(array $body): array => array_replace_recursive($body, ['data' => ['usage_count' => '5']]),
        )]);

        try {
            $transport->one('GET', 'tags/tag_1', Tag::fromArray(...));
            self::fail('expected a MalformedResponseException');
        } catch (MalformedResponseException $e) {
            self::assertSame(
                'octonomy: malformed response from GET /api/v1/tags/tag_1 (api_version v1): field "data.usage_count" must be an integer, got a string',
                $e->getMessage(),
            );
            self::assertSame('data.usage_count', $e->field);
            // The model's own report, before Transport placed it, stays chained.
            $previous = $e->getPrevious();
            self::assertInstanceOf(MalformedResponseException::class, $previous);
            self::assertSame('usage_count', $previous->field);
        }
    }

    public function testManyReturnsRowsAndPagination(): void
    {
        // A middle page, so both links are set.
        $captured = CapturedFixture::load('v1/tags/list-page');
        $pagination = $captured->json()['pagination'];
        $transport = $this->transportWith([$captured]);

        $page = $transport->many('GET', 'tags', self::asDecoded(), ['limit' => '1', 'offset' => '1']);

        self::assertSame($captured->data(), $page->data);
        self::assertCount(1, $page->data);
        self::assertSame(1, $page->pagination->limit);
        self::assertSame(1, $page->pagination->offset);
        self::assertSame($pagination['count'], $page->pagination->count);
        self::assertGreaterThan(2, $page->pagination->count);
        self::assertSame($pagination['next'], $page->pagination->next);
        self::assertSame($pagination['previous'], $page->pagination->previous);
        self::assertNotNull($page->pagination->next);
        self::assertNotNull($page->pagination->previous);
        self::assertSame(['limit' => '1', 'offset' => '1'], $this->lastQuery());
    }

    public function testManyAcceptsAnEmptyPage(): void
    {
        $transport = $this->transportWith([self::captured('v1/tags/list-empty')]);

        $page = $transport->many('GET', 'tags', self::asDecoded());

        self::assertSame([], $page->data);
        self::assertSame(0, $page->pagination->count);
    }

    #[DataProvider('bodiesWithBadData')]
    public function testManyRejectsDataThatIsNotAListOfObjects(string $kind, string $raw, string $expected): void
    {
        $transport = $this->transportWith([self::syntheticNegative($kind, 200, $raw)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('octonomy: malformed response from GET /api/v1/tags (api_version v1): ' . $expected);

        $transport->many('GET', 'tags', self::asDecoded());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function bodiesWithBadData(): iterable
    {
        $pagination = '"pagination":{"limit":50,"offset":0,"count":1}';
        $notArray = 'expected a JSON array under "data"';
        $notRow = 'expected every "data" item to be a non-empty JSON object';

        yield 'bare list (the spec shape)' => [SyntheticNegative::MISSING_ENVELOPE, '[{"id":"tag_1"}]', $notArray];
        yield 'data missing' => [SyntheticNegative::MISSING_ENVELOPE, '{' . $pagination . '}', $notArray];
        yield 'data is an object' => [SyntheticNegative::WRONG_TYPE, '{"data":{"id":"tag_1"},' . $pagination . '}', $notArray];
        // Both decode to a PHP list in array mode; they must still be rejected.
        yield 'data is an empty object' => [SyntheticNegative::WRONG_TYPE, '{"data":{},' . $pagination . '}', $notArray];
        yield 'data is an object with numeric keys' => [SyntheticNegative::WRONG_TYPE, '{"data":{"0":{"id":"tag_1"}},' . $pagination . '}', $notArray];
        yield 'row is a scalar' => [SyntheticNegative::WRONG_TYPE, '{"data":["tag_1"],' . $pagination . '}', $notRow];
        yield 'row is a list' => [SyntheticNegative::WRONG_TYPE, '{"data":[["tag_1"]],' . $pagination . '}', $notRow];
        yield 'row is an empty list' => [SyntheticNegative::WRONG_TYPE, '{"data":[[]],' . $pagination . '}', $notRow];
        yield 'row is an empty object' => [SyntheticNegative::MISSING_FIELD, '{"data":[{}],' . $pagination . '}', $notRow];
    }

    public function testManyRaisesTransportExceptionForANonJson2xx(): void
    {
        $transport = $this->transportWith([
            self::syntheticNegative(SyntheticNegative::GATEWAY, 200, '<html>proxy</html>', ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(TransportException::class);

        $transport->many('GET', 'tags', self::asDecoded());
    }

    #[DataProvider('bodiesWithBadPagination')]
    public function testManyRejectsMissingOrInvalidPagination(string $kind, string $raw): void
    {
        $transport = $this->transportWith([self::syntheticNegative($kind, 200, $raw)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v1/tags (api_version v1): expected a JSON object under "pagination"',
        );

        $transport->many('GET', 'tags', self::asDecoded());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesWithBadPagination(): iterable
    {
        yield 'pagination missing' => [SyntheticNegative::MISSING_ENVELOPE, '{"data":[]}'];
        yield 'pagination is null' => [SyntheticNegative::WRONG_TYPE, '{"data":[],"pagination":null}'];
        yield 'pagination is a list' => [SyntheticNegative::WRONG_TYPE, '{"data":[],"pagination":[50,0,1]}'];
        yield 'pagination is an empty list' => [SyntheticNegative::WRONG_TYPE, '{"data":[],"pagination":[]}'];
    }

    public function testManyNamesTheRowAFieldFaultIsIn(): void
    {
        $transport = $this->transportWith([self::syntheticNegativeFrom(
            'v1/tags/list',
            SyntheticNegative::MISSING_FIELD,
            static function (array $body): array {
                unset($body['data'][2]['slug']);

                return $body;
            },
        )]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v1/tags (api_version v1): field "data[2].slug" is missing',
        );

        $transport->many('GET', 'tags', Tag::fromArray(...));
    }

    /**
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $break
     */
    #[DataProvider('incompletePaginations')]
    public function testManyRejectsAPaginationFieldThatIsAbsentOrMistyped(string $kind, \Closure $break, string $expected): void
    {
        $transport = $this->transportWith([self::syntheticNegativeFrom('v1/tags/list', $kind, $break)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('octonomy: malformed response from GET /api/v1/tags (api_version v1): ' . $expected);

        $transport->many('GET', 'tags', self::asDecoded());
    }

    /**
     * @return iterable<string, array{string, \Closure(array<array-key, mixed>): array<array-key, mixed>, string}>
     */
    public static function incompletePaginations(): iterable
    {
        $without = static fn(string $key): \Closure => static function (array $body) use ($key): array {
            unset($body['pagination'][$key]);

            return $body;
        };
        $with = static fn(string $key, mixed $value): \Closure => static fn(array $body): array => array_replace_recursive(
            $body,
            ['pagination' => [$key => $value]],
        );

        yield 'count absent' => [SyntheticNegative::MISSING_FIELD, $without('count'), 'field "pagination.count" is missing'];
        // null at an edge, never absent.
        yield 'next absent' => [SyntheticNegative::MISSING_FIELD, $without('next'), 'field "pagination.next" is missing'];
        yield 'limit as a numeric string' => [SyntheticNegative::WRONG_TYPE, $with('limit', '50'), 'field "pagination.limit" must be an integer, got a string'];
        yield 'previous as a number' => [SyntheticNegative::WRONG_TYPE, $with('previous', 0), 'field "pagination.previous" must be a string or null, got an integer'];
    }

    public function testManyChecksTheWholeEnvelopeBeforeHydratingARow(): void
    {
        // A row the model would reject and no pagination: the envelope is
        // reported, because no row is hydrated until the envelope holds.
        $transport = $this->transportWith([self::syntheticNegativeFrom(
            'v1/tags/list',
            SyntheticNegative::MISSING_ENVELOPE,
            static function (array $body): array {
                unset($body['data'][0]['id'], $body['pagination']);

                return $body;
            },
        )]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('expected a JSON object under "pagination"');

        $transport->many('GET', 'tags', Tag::fromArray(...));
    }

    public function testNoneSendsTheRequestAndReturnsNothing(): void
    {
        $transport = $this->transportWith([self::captured('v1/tag-assignments/delete')]);

        $transport->none('DELETE', 'tag-assignments', [], ['tag_id' => 'tag_1', 'resource_id' => 'r1']);

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v1/tag-assignments', $request->getUri()->getPath());
        self::assertSame(['tag_id' => 'tag_1', 'resource_id' => 'r1'], $this->lastJsonBody());
    }

    #[DataProvider('unexpectedSuccessBodies')]
    public function testNoneIgnoresAnUnexpectedSuccessBody(int $status, string $raw): void
    {
        $transport = $this->transportWith([self::syntheticNegative(SyntheticNegative::STRAY_BODY, $status, $raw, [])]);

        $transport->none('DELETE', 'tags/tag_1');

        self::assertSame('DELETE', $this->lastRequest()->getMethod());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function unexpectedSuccessBodies(): iterable
    {
        yield '204 with a JSON body' => [204, '{"data":{"id":"tag_1"}}'];
        yield '200 with a JSON body' => [200, '{"detail":"ok"}'];
        yield '200 with a non-JSON body' => [200, '<html>ok</html>'];
    }

    public function testNoneStillRaisesForANon2xx(): void
    {
        $transport = $this->transportWith([self::captured('v1/errors/not-found')]);

        $this->expectException(NotFoundException::class);

        $transport->none('DELETE', 'tags/missing');
    }
}
