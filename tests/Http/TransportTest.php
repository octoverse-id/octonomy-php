<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Http;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the three ways Transport reads a 2xx — one(), many(), none() — and the
 * envelope each one enforces. Well-formed responses are captured from a real
 * server; the malformed ones are synthetic negatives, because a conforming
 * server cannot produce them.
 */
final class TransportTest extends SdkTestCase
{
    public function testOneUnwrapsTheDataObject(): void
    {
        $transport = $this->transportWith([self::captured('v1/tags/get')]);

        $data = $transport->one('GET', 'tags/tag_1');

        self::assertSame(self::capturedData('v1/tags/get'), $data);
        // Nested objects come back as arrays, as models expect.
        self::assertSame(['color' => 'red'], $data['metadata']);
    }

    #[DataProvider('bodiesWithoutDataObject')]
    public function testOneRejectsABodyWithoutADataObject(string $raw): void
    {
        $transport = $this->transportWith([self::syntheticNegative(200, $raw)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v1/tags/tag_1: expected a non-empty JSON object under "data"',
        );

        $transport->one('GET', 'tags/tag_1');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesWithoutDataObject(): iterable
    {
        yield 'unwrapped resource (the pre-fix fixture shape)' => ['{"id":"tag_1","name":"Featured"}'];
        yield 'empty body' => [''];
        yield 'data is null' => ['{"data":null}'];
        yield 'data is an empty object' => ['{"data":{}}'];
        yield 'data is an empty list' => ['{"data":[]}'];
        yield 'data is a list' => ['{"data":[{"id":"tag_1"}]}'];
        yield 'data is a scalar' => ['{"data":"tag_1"}'];
        yield 'body is a JSON list' => ['[{"id":"tag_1"}]'];
    }

    public function testOneRaisesTransportExceptionForANonJson2xx(): void
    {
        $transport = $this->transportWith([self::syntheticNegative(200, '<html>proxy</html>', ['Content-Type' => 'text/html'])]);

        $this->expectException(TransportException::class);

        $transport->one('GET', 'tags/tag_1');
    }

    public function testOneRaisesTheTypedApiExceptionForANon2xx(): void
    {
        $transport = $this->transportWith([self::captured('v1/errors/not-found')]);

        $this->expectException(NotFoundException::class);

        $transport->one('GET', 'tags/missing');
    }

    public function testManyReturnsRowsAndPagination(): void
    {
        // A middle page, so both links are set.
        $captured = CapturedFixture::load('v1/tags/list-page');
        $pagination = $captured->json()['pagination'];
        $transport = $this->transportWith([$captured->response()]);

        $page = $transport->many('GET', 'tags', ['limit' => '1', 'offset' => '1']);

        self::assertSame($captured->data(), $page->rows);
        self::assertCount(1, $page->rows);
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

        $page = $transport->many('GET', 'tags');

        self::assertSame([], $page->rows);
        self::assertSame(0, $page->pagination->count);
    }

    #[DataProvider('bodiesWithBadData')]
    public function testManyRejectsDataThatIsNotAListOfObjects(string $raw, string $expected): void
    {
        $transport = $this->transportWith([self::syntheticNegative(200, $raw)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('octonomy: malformed response from GET /api/v1/tags: ' . $expected);

        $transport->many('GET', 'tags');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesWithBadData(): iterable
    {
        $pagination = '"pagination":{"limit":50,"offset":0,"count":1}';

        yield 'bare list (the spec shape)' => ['[{"id":"tag_1"}]', 'expected a JSON array under "data"'];
        yield 'data missing' => ['{' . $pagination . '}', 'expected a JSON array under "data"'];
        yield 'data is an object' => ['{"data":{"id":"tag_1"},' . $pagination . '}', 'expected a JSON array under "data"'];
        // Both decode to a PHP list in array mode; they must still be rejected.
        yield 'data is an empty object' => ['{"data":{},' . $pagination . '}', 'expected a JSON array under "data"'];
        yield 'data is an object with numeric keys' => ['{"data":{"0":{"id":"tag_1"}},' . $pagination . '}', 'expected a JSON array under "data"'];
        yield 'row is a scalar' => ['{"data":["tag_1"],' . $pagination . '}', 'expected every "data" item to be a non-empty JSON object'];
        yield 'row is a list' => ['{"data":[["tag_1"]],' . $pagination . '}', 'expected every "data" item to be a non-empty JSON object'];
        yield 'row is an empty list' => ['{"data":[[]],' . $pagination . '}', 'expected every "data" item to be a non-empty JSON object'];
        yield 'row is an empty object' => ['{"data":[{}],' . $pagination . '}', 'expected every "data" item to be a non-empty JSON object'];
    }

    public function testManyRaisesTransportExceptionForANonJson2xx(): void
    {
        $transport = $this->transportWith([self::syntheticNegative(200, '<html>proxy</html>', ['Content-Type' => 'text/html'])]);

        $this->expectException(TransportException::class);

        $transport->many('GET', 'tags');
    }

    #[DataProvider('bodiesWithBadPagination')]
    public function testManyRejectsMissingOrInvalidPagination(string $raw): void
    {
        $transport = $this->transportWith([self::syntheticNegative(200, $raw)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from GET /api/v1/tags: expected a JSON object under "pagination"',
        );

        $transport->many('GET', 'tags');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesWithBadPagination(): iterable
    {
        yield 'pagination missing' => ['{"data":[]}'];
        yield 'pagination is null' => ['{"data":[],"pagination":null}'];
        yield 'pagination is a list' => ['{"data":[],"pagination":[50,0,1]}'];
        yield 'pagination is an empty list' => ['{"data":[],"pagination":[]}'];
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
        $transport = $this->transportWith([self::syntheticNegative($status, $raw, [])]);

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
