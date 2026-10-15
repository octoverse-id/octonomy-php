<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Http;

use GuzzleHttp\Psr7\Response;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the three ways Transport reads a 2xx — one(), many(), none() — and the
 * envelope each one enforces. The malformed bodies here are deliberately
 * synthetic: a conforming server cannot produce them.
 */
final class TransportTest extends SdkTestCase
{
    public function testOneUnwrapsTheDataObject(): void
    {
        $transport = $this->transportWith([new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"data":{"id":"tag_1","metadata":{"color":"red","sizes":[{"eu":42}]},"parent_id":null}}',
        )]);

        // Nested objects come back as arrays, as models expect.
        self::assertSame(
            ['id' => 'tag_1', 'metadata' => ['color' => 'red', 'sizes' => [['eu' => 42]]], 'parent_id' => null],
            $transport->one('GET', 'tags/tag_1'),
        );
    }

    #[DataProvider('bodiesWithoutDataObject')]
    public function testOneRejectsABodyWithoutADataObject(string $raw): void
    {
        $transport = $this->transportWith([new Response(200, ['Content-Type' => 'application/json'], $raw)]);

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
        $transport = $this->transportWith([new Response(200, ['Content-Type' => 'text/html'], '<html>proxy</html>')]);

        $this->expectException(TransportException::class);

        $transport->one('GET', 'tags/tag_1');
    }

    public function testOneRaisesTheTypedApiExceptionForANon2xx(): void
    {
        $transport = $this->transportWith([
            self::jsonResponse(404, ['error' => ['code' => 'not_found', 'message' => 'Resource not found.']]),
        ]);

        $this->expectException(NotFoundException::class);

        $transport->one('GET', 'tags/missing');
    }

    public function testManyReturnsRowsAndPagination(): void
    {
        $transport = $this->transportWith([
            self::jsonResponse(200, [
                'data' => [['id' => 'tag_1'], ['id' => 'tag_2']],
                'pagination' => ['limit' => 2, 'offset' => 4, 'count' => 9, 'next' => 'https://n', 'previous' => null],
            ]),
        ]);

        $page = $transport->many('GET', 'tags', ['limit' => '2', 'offset' => '4']);

        self::assertSame([['id' => 'tag_1'], ['id' => 'tag_2']], $page->rows);
        self::assertSame(2, $page->pagination->limit);
        self::assertSame(4, $page->pagination->offset);
        self::assertSame(9, $page->pagination->count);
        self::assertSame('https://n', $page->pagination->next);
        self::assertNull($page->pagination->previous);
        self::assertSame(['limit' => '2', 'offset' => '4'], $this->lastQuery());
    }

    public function testManyAcceptsAnEmptyPage(): void
    {
        $transport = $this->transportWith([
            self::jsonResponse(200, ['data' => [], 'pagination' => ['limit' => 50, 'offset' => 0, 'count' => 0]]),
        ]);

        $page = $transport->many('GET', 'tags');

        self::assertSame([], $page->rows);
        self::assertSame(0, $page->pagination->count);
    }

    #[DataProvider('bodiesWithBadData')]
    public function testManyRejectsDataThatIsNotAListOfObjects(string $raw, string $expected): void
    {
        $transport = $this->transportWith([new Response(200, ['Content-Type' => 'application/json'], $raw)]);

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
        $transport = $this->transportWith([new Response(200, ['Content-Type' => 'text/html'], '<html>proxy</html>')]);

        $this->expectException(TransportException::class);

        $transport->many('GET', 'tags');
    }

    #[DataProvider('bodiesWithBadPagination')]
    public function testManyRejectsMissingOrInvalidPagination(string $raw): void
    {
        $transport = $this->transportWith([new Response(200, ['Content-Type' => 'application/json'], $raw)]);

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
        $transport = $this->transportWith([new Response(204)]);

        $transport->none('DELETE', 'tag-assignments', [], ['tag_id' => 'tag_1', 'resource_id' => 'r1']);

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v1/tag-assignments', $request->getUri()->getPath());
        self::assertSame(['tag_id' => 'tag_1', 'resource_id' => 'r1'], $this->lastJsonBody());
    }

    #[DataProvider('unexpectedSuccessBodies')]
    public function testNoneIgnoresAnUnexpectedSuccessBody(int $status, string $raw): void
    {
        $transport = $this->transportWith([new Response($status, [], $raw)]);

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
        $transport = $this->transportWith([
            self::jsonResponse(404, ['error' => ['code' => 'not_found', 'message' => 'Resource not found.']]),
        ]);

        $this->expectException(NotFoundException::class);

        $transport->none('DELETE', 'tags/missing');
    }
}
