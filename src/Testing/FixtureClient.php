<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Testing;

use GuzzleHttp\Psr7\Response;
use Octoverse\Octonomy\Internal\Redaction;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client for testing your own code against the SDK without a server.
 * Pass it as http_client, and it answers each request the SDK makes with a
 * response a real Octonomy sent, from the captured corpus this package ships
 * (resources/fixtures):
 *
 *     $fake = FixtureClient::withCapturedFixtures();
 *     $client = new Client([...$config, 'http_client' => $fake]);
 *     $tag = $client->tags()->get('t1');      // the captured tag: slug 'featured'
 *     $fake->assertSent('GET', 'tags/t1');
 *
 * It matches a request by its method and its path below /api/v1 or /api/v2,
 * or a health probe's path, and answers with the one response captured for
 * that endpoint from that api_version. Whatever path base_url has of its own
 * is skipped. The ids in the path, the query, the headers and the body play
 * no part: get('t1') and get('t2') both get the captured tag, whose id is
 * neither. A request it has no response for throws FixtureClientException,
 * naming the method and the path; the SDK reports that as a
 * TransportException that quotes it.
 *
 * It replays, and nothing more (#28):
 * - It keeps no state, so a tag you create is not there to get, and it
 *   persists no write.
 * - It does not page: a walk*() whose captured page says more rows follow
 *   stops with a WalkInterruptedException when it asks for the next page.
 * - It knows nothing of what one api_version allows and the other refuses.
 * - It cannot be made to fail, or be handed a response of your own.
 *
 * A fake that did any of that would be a second Octonomy, drifting from the
 * real one and teaching wrong behaviour with a green suite. Keep a fake of
 * your own for anything stateful, and answer with a PSR-18 client of your own
 * to test how your code handles an error.
 *
 * The bodies are what the server the corpus was captured from sent, so they
 * change when the corpus is re-captured, which the CHANGELOG records. Its
 * methods, and which endpoints it answers, are public API, versioned like the
 * rest (docs/versioning.md).
 */
final class FixtureClient implements ClientInterface
{
    /**
     * Each endpoint the SDK calls below /api/{version}, by method and path,
     * with the one captured exchange that answers it. '*' stands for one
     * non-empty path segment, such as an id. Where the corpus holds several
     * exchanges for one endpoint (an empty list, a later page, an update that
     * clears a field), the plain one answers. Every fixture named here is
     * captured for both api_versions.
     *
     * @var list<array{string, string, string}>
     */
    private const ROUTES = [
        ['GET', 'audit-logs', 'audit-logs/list'],
        ['GET', 'resources/*/*/audit-logs', 'resources/audit-logs'],
        ['GET', 'resources/*/*/tags', 'resources/tags'],
        ['POST', 'resources/*/*/tags', 'resources/replace'],
        ['GET', 'tag-aliases', 'tag-aliases/list'],
        ['POST', 'tag-aliases', 'tag-aliases/create'],
        ['GET', 'tag-aliases/*', 'tag-aliases/get'],
        ['PATCH', 'tag-aliases/*', 'tag-aliases/update'],
        ['DELETE', 'tag-aliases/*', 'tag-aliases/delete'],
        ['POST', 'tag-assignments', 'tag-assignments/create'],
        ['DELETE', 'tag-assignments', 'tag-assignments/delete'],
        ['POST', 'tag-assignments/bulk-assign', 'tag-assignments/bulk-assign'],
        ['POST', 'tag-assignments/bulk-remove', 'tag-assignments/bulk-remove'],
        ['GET', 'tag-resolution', 'tag-resolution/tag'],
        ['GET', 'tags', 'tags/list'],
        ['POST', 'tags', 'tags/create'],
        ['GET', 'tags/*', 'tags/get'],
        ['PATCH', 'tags/*', 'tags/update'],
        ['DELETE', 'tags/*', 'tags/delete'],
        ['GET', 'tags/*/aliases', 'tags/aliases'],
        ['GET', 'tags/*/audit-logs', 'tags/audit-logs'],
        ['GET', 'tags/*/resources', 'tags/resources'],
        ['GET', 'vocabularies', 'vocabularies/list'],
        ['POST', 'vocabularies', 'vocabularies/create'],
        ['GET', 'vocabularies/*', 'vocabularies/get'],
        ['PATCH', 'vocabularies/*', 'vocabularies/update'],
        ['DELETE', 'vocabularies/*', 'vocabularies/delete'],
    ];

    /**
     * The health probes, which the server serves outside /api with no
     * api_version (#24). Each is named by its path, and so is its fixture.
     */
    private const PROBES = ['health/live', 'health/ready'];

    /**
     * Each request it answered, with its method and its path below
     * /api/{version} (or the probe's), percent-decoded, for assertSent().
     *
     * @var list<array{string, string, RequestInterface}>
     */
    private array $answered = [];

    private function __construct() {}

    /**
     * A client that answers from the captured corpus this package ships. It is
     * the only way to make one: there is nothing to configure.
     */
    public static function withCapturedFixtures(): self
    {
        return new self();
    }

    /**
     * Answer $request with the response captured for its endpoint, as a new
     * response each time.
     *
     * @throws FixtureClientException when it has no response for the request, naming its method and path,
     *                                or when the shipped corpus cannot be read
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $match = self::match($method, $path);
        if ($match === null) {
            // Scrubbed, since this frame holds the request and its
            // Authorization header (AGENTS.md: Redaction).
            throw Redaction::scrub(new FixtureClientException(sprintf(
                'octonomy: FixtureClient has no captured response for %s %s. It answers each endpoint the SDK'
                . ' calls, by method and by path below /api/v1 or /api/v2 or a health probe\'s path, and this'
                . ' request matches none of them. Fix: send it through the SDK, or answer it with an http_client'
                . ' of your own; FixtureClient cannot be given responses.',
                $method,
                $path,
            )));
        }
        [$fixture, $relative] = $match;
        $response = self::replay($fixture, $method, $path);
        $this->answered[] = [$method, rawurldecode($relative), $request];

        return $response;
    }

    /**
     * Assert that it answered a $method request for $path, and return the last
     * such request, to assert its query, headers or body.
     *
     * $path is below /api/{version}, as 'tags/t1', or a probe's, as
     * 'health/ready'. Percent-encoding is ignored on both sides: 'tags/a b' and
     * 'tags/a%20b' are the same path.
     *
     * It throws a PHP \AssertionError, which PHPUnit and Pest report as a
     * failed assertion, so it works under any test framework. PHPUnit does not
     * count a passing one as an assertion: a test that makes no other one is
     * reported as risky.
     *
     * @throws \AssertionError when it answered no such request, naming each one it did answer
     */
    public function assertSent(string $method, string $path): RequestInterface
    {
        $wanted = rawurldecode(ltrim($path, '/'));
        for ($i = count($this->answered) - 1; $i >= 0; --$i) {
            [$sentMethod, $sentPath, $request] = $this->answered[$i];
            if ($sentMethod === $method && $sentPath === $wanted) {
                return $request;
            }
        }

        $answered = array_map(
            static fn(array $sent): string => "{$sent[0]} {$sent[1]}",
            $this->answered,
        );

        throw new \AssertionError(sprintf(
            'octonomy: FixtureClient answered no %s request for %s. %s Fix: check that the code under test'
            . ' makes the call, and give the path below /api/{version}, as \'tags/t1\', or a probe\'s, as'
            . ' \'health/ready\'.',
            $method,
            $wanted,
            $answered === [] ? 'It answered no request at all.' : 'It answered: ' . implode(', ', $answered) . '.',
        ));
    }

    /**
     * The fixture that answers $method $path, and the path below /api/{version}
     * that matched, or null when none does.
     *
     * The SDK sends {base_url}/api/{version}/{endpoint}, and base_url can have a
     * path of its own, which may hold "/api/v2/" too. So each place the
     * version prefix appears is tried, left to right, and the first whose
     * remainder is an endpoint wins. A probe is {base_url}/health/live or
     * /health/ready.
     *
     * @return array{string, string}|null the fixture below the corpus, as 'v2/tags/get', and the path
     */
    private static function match(string $method, string $path): ?array
    {
        // The slash after the version is looked ahead at, not consumed: a
        // base_url ending in /api/v2 makes /api/v2/api/v2/..., where the two
        // prefixes share it, and the second is the SDK's.
        preg_match_all('#/api/(v1|v2)(?=/)#', $path, $prefixes, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($prefixes as [[$prefix, $offset], [$version]]) {
            $relative = substr($path, $offset + strlen($prefix) + 1);
            $fixture = self::route($method, $relative);
            if ($fixture !== null) {
                return ["{$version}/{$fixture}", $relative];
            }
        }
        foreach (self::PROBES as $probe) {
            if ($method === 'GET' && str_ends_with($path, "/{$probe}")) {
                return [$probe, $probe];
            }
        }

        return null;
    }

    /**
     * The fixture, below the api_version, of the endpoint $method $relative is,
     * or null when it is none.
     */
    private static function route(string $method, string $relative): ?string
    {
        foreach (self::ROUTES as [$routeMethod, $template, $fixture]) {
            // A segment is percent-encoded on its own (Internal\Path), so an id
            // holding a '/' arrives as %2F and is still one segment.
            $pattern = '#^' . str_replace('\*', '[^/]+', preg_quote($template, '#')) . '$#';
            if ($routeMethod === $method && preg_match($pattern, $relative) === 1) {
                return $fixture;
            }
        }

        return null;
    }

    /**
     * The response captured in $fixture, read from the corpus each time, since
     * a response's body is a stream its reader drains.
     *
     * The body is encoded from the decoded capture with the flags the unit
     * suite's CapturedFixture uses, and its objects are kept as objects, so
     * {} and [] reach the SDK as the server sent them.
     */
    private static function replay(string $fixture, string $method, string $path): ResponseInterface
    {
        $file = dirname(__DIR__, 2) . "/resources/fixtures/{$fixture}.json";
        $raw = @file_get_contents($file);
        $captured = $raw === false ? null : json_decode($raw, false);
        $response = $captured instanceof \stdClass ? ($captured->response ?? null) : null;
        if (
            !$response instanceof \stdClass
            || !is_int($response->status ?? null)
            || !($response->headers ?? null) instanceof \stdClass
            || !property_exists($response, 'body')
        ) {
            throw Redaction::scrub(new FixtureClientException(sprintf(
                'octonomy: FixtureClient cannot read the response captured for %s %s from %s: the file is'
                . ' missing or is not a captured exchange. The SDK cannot tell how the package came to lack it.'
                . ' Fix: reinstall octoverse-id/octonomy-php, whose resources/fixtures it reads.',
                $method,
                $path,
                $file,
            )));
        }

        $headers = [];
        foreach (get_object_vars($response->headers) as $name => $value) {
            $headers[(string) $name] = is_string($value) ? $value : '';
        }
        $body = $response->body === null ? '' : (string) json_encode(
            $response->body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return new Response($response->status, $headers, $body);
    }
}
