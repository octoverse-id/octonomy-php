<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Testing;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Exception\WalkInterruptedException;
use Octoverse\Octonomy\HealthClient;
use Octoverse\Octonomy\Model\Assignment;
use Octoverse\Octonomy\Model\AssignmentCreate;
use Octoverse\Octonomy\Model\AssignmentRemove;
use Octoverse\Octonomy\Model\BulkAssign;
use Octoverse\Octonomy\Model\BulkAssignResult;
use Octoverse\Octonomy\Model\BulkRemove;
use Octoverse\Octonomy\Model\BulkRemoveResult;
use Octoverse\Octonomy\Model\HealthStatus;
use Octoverse\Octonomy\Model\ResourceTagsReplace;
use Octoverse\Octonomy\Model\ResourceTagsReplaceResult;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagAliasCreate;
use Octoverse\Octonomy\Model\TagAliasUpdate;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagResolution;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyUpdate;
use Octoverse\Octonomy\ResultList;
use Octoverse\Octonomy\Testing\FixtureClient;
use Octoverse\Octonomy\Testing\FixtureClientException;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers Testing\FixtureClient (#28): a consumer's PSR-18 stand-in for the
 * server, which answers each endpoint the SDK calls with its captured response
 * and throws, naming the request, on anything else.
 */
final class FixtureClientTest extends SdkTestCase
{
    private const CONFIG = ['base_url' => 'https://octonomy.test', 'token' => 'test-token', 'tenant_id' => 'tenant-1'];

    public function testItMocksTheSdkInThreeLinesWithoutGuzzle(): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        $client = new Client([...self::CONFIG, 'http_client' => $fake]);

        self::assertSame(self::capturedData('v2/tags/get')['slug'], $client->tags()->get('t1')->slug);
        $request = $fake->assertSent('GET', 'tags/t1');
        self::assertSame('https://octonomy.test/api/v2/tags/t1', (string) $request->getUri());
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    /**
     * Each endpoint the SDK calls, by method and path template ('*' is one
     * segment), with the capture that answers it. Written out here rather than
     * read from FixtureClient, so a route that answers with the wrong capture
     * fails.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function endpoints(): iterable
    {
        yield 'the audit log' => ['GET', 'audit-logs', 'audit-logs/list'];
        yield "a resource's audit log" => ['GET', 'resources/*/*/audit-logs', 'resources/audit-logs'];
        yield "a resource's tags" => ['GET', 'resources/*/*/tags', 'resources/tags'];
        yield "replacing a resource's tags" => ['POST', 'resources/*/*/tags', 'resources/replace'];
        yield 'listing aliases' => ['GET', 'tag-aliases', 'tag-aliases/list'];
        yield 'creating an alias' => ['POST', 'tag-aliases', 'tag-aliases/create'];
        yield 'getting an alias' => ['GET', 'tag-aliases/*', 'tag-aliases/get'];
        yield 'updating an alias' => ['PATCH', 'tag-aliases/*', 'tag-aliases/update'];
        yield 'deleting an alias' => ['DELETE', 'tag-aliases/*', 'tag-aliases/delete'];
        yield 'assigning a tag' => ['POST', 'tag-assignments', 'tag-assignments/create'];
        yield 'removing an assignment' => ['DELETE', 'tag-assignments', 'tag-assignments/delete'];
        yield 'bulk-assigning' => ['POST', 'tag-assignments/bulk-assign', 'tag-assignments/bulk-assign'];
        yield 'bulk-removing' => ['POST', 'tag-assignments/bulk-remove', 'tag-assignments/bulk-remove'];
        yield 'resolving a slug' => ['GET', 'tag-resolution', 'tag-resolution/tag'];
        yield 'listing tags' => ['GET', 'tags', 'tags/list'];
        yield 'creating a tag' => ['POST', 'tags', 'tags/create'];
        yield 'getting a tag' => ['GET', 'tags/*', 'tags/get'];
        yield 'updating a tag' => ['PATCH', 'tags/*', 'tags/update'];
        yield 'deleting a tag' => ['DELETE', 'tags/*', 'tags/delete'];
        yield "a tag's aliases" => ['GET', 'tags/*/aliases', 'tags/aliases'];
        yield "a tag's audit log" => ['GET', 'tags/*/audit-logs', 'tags/audit-logs'];
        yield "a tag's resources" => ['GET', 'tags/*/resources', 'tags/resources'];
        yield 'listing vocabularies' => ['GET', 'vocabularies', 'vocabularies/list'];
        yield 'creating a vocabulary' => ['POST', 'vocabularies', 'vocabularies/create'];
        yield 'getting a vocabulary' => ['GET', 'vocabularies/*', 'vocabularies/get'];
        yield 'updating a vocabulary' => ['PATCH', 'vocabularies/*', 'vocabularies/update'];
        yield 'deleting a vocabulary' => ['DELETE', 'vocabularies/*', 'vocabularies/delete'];
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function endpointsOnEachApiVersion(): iterable
    {
        foreach ([ApiVersion::V1, ApiVersion::V2] as $version) {
            foreach (self::endpoints() as $name => [$method, $template, $fixture]) {
                yield "{$name} on {$version}" => [$version, $method, $template, $fixture];
            }
        }
    }

    #[DataProvider('endpointsOnEachApiVersion')]
    public function testEachEndpointIsAnsweredWithTheResponseCapturedForItOnItsApiVersion(
        string $version,
        string $method,
        string $template,
        string $fixture,
    ): void {
        $captured = self::captured("{$version}/{$fixture}");
        // The capture really is this endpoint's, so the route names the right one.
        self::assertSame($method, $captured->request['method']);
        self::assertMatchesRegularExpression(
            '#^/api/' . $version . '/' . str_replace('\*', '[^/]+', preg_quote($template, '#')) . '$#',
            $captured->request['path'],
        );

        $response = FixtureClient::withCapturedFixtures()->sendRequest(self::request(
            $method,
            "https://octonomy.test/api/{$version}/" . str_replace('*', 'not-a-captured-id', $template),
        ));

        self::assertSame($captured->status, $response->getStatusCode());
        self::assertSame($captured->headers, array_map(
            static fn(array $values): string => implode(', ', $values),
            $response->getHeaders(),
        ));
        self::assertSame($captured->body, (string) $response->getBody());
    }

    /**
     * Every public method of every service that sends a request, with what it
     * returns: a model class, ResultList, or null for a method that returns
     * nothing.
     *
     * @return iterable<string, array{\Closure(Client): mixed, class-string|null}>
     */
    public static function sdkCalls(): iterable
    {
        yield 'tags create' => [static fn(Client $c) => $c->tags()->create(new TagCreate('Featured', 'featured', 'label')), Tag::class];
        yield 'tags get' => [static fn(Client $c) => $c->tags()->get('t1'), Tag::class];
        yield 'tags list' => [static fn(Client $c) => $c->tags()->list(), ResultList::class];
        yield 'tags update' => [static fn(Client $c) => $c->tags()->update('t1', new TagUpdate(name: 'Renamed')), Tag::class];
        yield 'tags delete' => [static fn(Client $c) => $c->tags()->delete('t1'), null];
        yield 'tags resolve' => [static fn(Client $c) => $c->tags()->resolve('red'), TagResolution::class];
        yield 'tags listAliases' => [static fn(Client $c) => $c->tags()->listAliases('t1'), ResultList::class];
        yield 'tags listResources' => [static fn(Client $c) => $c->tags()->listResources('t1'), ResultList::class];
        yield 'tags listAuditLogs' => [static fn(Client $c) => $c->tags()->listAuditLogs('t1'), ResultList::class];
        yield 'vocabularies create' => [static fn(Client $c) => $c->vocabularies()->create(new VocabularyCreate('Colors', 'colors')), Vocabulary::class];
        yield 'vocabularies get' => [static fn(Client $c) => $c->vocabularies()->get('v1'), Vocabulary::class];
        yield 'vocabularies list' => [static fn(Client $c) => $c->vocabularies()->list(), ResultList::class];
        yield 'vocabularies update' => [static fn(Client $c) => $c->vocabularies()->update('v1', new VocabularyUpdate(name: 'Hues')), Vocabulary::class];
        yield 'vocabularies delete' => [static fn(Client $c) => $c->vocabularies()->delete('v1'), null];
        yield 'aliases create' => [static fn(Client $c) => $c->aliases()->create(new TagAliasCreate('t1', 'Scarlet', 'scarlet')), TagAlias::class];
        yield 'aliases get' => [static fn(Client $c) => $c->aliases()->get('a1'), TagAlias::class];
        yield 'aliases list' => [static fn(Client $c) => $c->aliases()->list(), ResultList::class];
        yield 'aliases update' => [static fn(Client $c) => $c->aliases()->update('a1', new TagAliasUpdate(name: 'Crimson')), TagAlias::class];
        yield 'aliases delete' => [static fn(Client $c) => $c->aliases()->delete('a1'), null];
        yield 'assignments create' => [static fn(Client $c) => $c->assignments()->create(AssignmentCreate::forTag('app', 'product', 'sku-1', 't1')), Assignment::class];
        yield 'assignments remove' => [static fn(Client $c) => $c->assignments()->remove(new AssignmentRemove('app', 'product', 'sku-1', 't1')), null];
        yield 'assignments bulkAssign' => [static fn(Client $c) => $c->assignments()->bulkAssign(new BulkAssign('app', 'product', 'sku-1', tagIds: ['t1'])), BulkAssignResult::class];
        yield 'assignments bulkRemove' => [static fn(Client $c) => $c->assignments()->bulkRemove(new BulkRemove('app', 'product', 'sku-1', ['t1'])), BulkRemoveResult::class];
        yield 'resources listTags' => [static fn(Client $c) => $c->resources()->listTags('product', 'sku-1', 'app'), ResultList::class];
        yield 'resources replaceTags' => [static fn(Client $c) => $c->resources()->replaceTags('product', 'sku-1', new ResourceTagsReplace('app', ['t1'])), ResourceTagsReplaceResult::class];
        yield 'resources listAuditLogs' => [static fn(Client $c) => $c->resources()->listAuditLogs('product', 'sku-1'), ResultList::class];
        yield 'auditLogs list' => [static fn(Client $c) => $c->auditLogs()->list(), ResultList::class];
        yield 'health live' => [static fn(Client $c) => $c->health()->live(), HealthStatus::class];
        yield 'health ready' => [static fn(Client $c) => $c->health()->ready(), HealthStatus::class];
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): mixed, class-string|null}>
     */
    public static function sdkCallsOnEachApiVersion(): iterable
    {
        foreach ([ApiVersion::V1, ApiVersion::V2] as $version) {
            foreach (self::sdkCalls() as $name => [$call, $returns]) {
                yield "{$name} on {$version}" => [$version, $call, $returns];
            }
        }
    }

    /**
     * @param \Closure(Client): mixed $call
     * @param class-string|null       $returns
     */
    #[DataProvider('sdkCallsOnEachApiVersion')]
    public function testEverySdkCallIsAnsweredAndHydrates(string $version, \Closure $call, ?string $returns): void
    {
        $client = new Client([...self::CONFIG, 'api_version' => $version, 'http_client' => FixtureClient::withCapturedFixtures()]);

        $result = $call($client);

        if ($returns === null) {
            self::assertNull($result);
        } else {
            self::assertInstanceOf($returns, $result);
        }
    }

    public function testEachRequestGetsAResponseOfItsOwn(): void
    {
        $client = new Client([...self::CONFIG, 'http_client' => FixtureClient::withCapturedFixtures()]);

        // A replayed body is a stream; a second read of a shared one would be empty.
        self::assertSame($client->tags()->get('t1')->id, $client->tags()->get('t1')->id);
    }

    public function testAWalkReadsACapturedPageThatIsTheLast(): void
    {
        $client = new Client([...self::CONFIG, 'http_client' => FixtureClient::withCapturedFixtures()]);

        self::assertCount(count(self::capturedData('v2/tags/list')), iterator_to_array($client->tags()->walk()));
    }

    public function testAWalkStopsWhereTheCapturedPageSaysMoreFollowSinceItDoesNotPage(): void
    {
        $client = new Client([...self::CONFIG, 'http_client' => FixtureClient::withCapturedFixtures()]);
        $page = self::captured('v2/audit-logs/list')->json()['pagination'];
        self::assertIsArray($page);
        self::assertNotNull($page['next'], 'the captured audit log page no longer says more follow; pick another');

        $walked = 0;
        try {
            foreach ($client->auditLogs()->walk() as $ignored) {
                ++$walked;
            }
            self::fail('the walk asked for a second page and got the first again, which it must refuse');
        } catch (WalkInterruptedException $e) {
            self::assertSame(count(self::capturedData('v2/audit-logs/list')), $walked);
            self::assertSame($walked, $e->reachedOffset);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unmatchedRequests(): iterable
    {
        yield 'a method no endpoint takes' => ['PUT', 'https://octonomy.test/api/v2/tags/t1'];
        yield 'a path no endpoint has' => ['GET', 'https://octonomy.test/api/v2/tags/t1/children'];
        yield 'an empty id' => ['GET', 'https://octonomy.test/api/v2/tags/'];
        yield 'a path outside /api' => ['GET', 'https://octonomy.test/tags'];
        yield 'an api_version the server does not serve' => ['GET', 'https://octonomy.test/api/v3/tags'];
        yield 'a probe asked with another method' => ['POST', 'https://octonomy.test/health/live'];
        yield 'a probe the server does not serve' => ['GET', 'https://octonomy.test/health/started'];
    }

    #[DataProvider('unmatchedRequests')]
    public function testAnUnmatchedRequestThrowsNamingItsMethodAndPath(string $method, string $uri): void
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);

        $this->expectException(FixtureClientException::class);
        $this->expectExceptionMessage("octonomy: FixtureClient has no captured response for {$method} {$path}.");

        FixtureClient::withCapturedFixtures()->sendRequest(self::request($method, $uri));
    }

    public function testAnUnmatchedRequestReachesTheCallerThroughTheSdkStillNamed(): void
    {
        $client = new Client([...self::CONFIG, 'http_client' => FixtureClient::withCapturedFixtures()]);

        try {
            $client->tags()->get('');
            self::fail('an empty id is no endpoint FixtureClient answers');
        } catch (TransportException $e) {
            self::assertStringContainsString(FixtureClientException::class, $e->getMessage());
            self::assertStringContainsString('FixtureClient has no captured response for GET /api/v2/tags/.', $e->getMessage());
        }
    }

    public function testAnUnmatchedRequestKeepsNoRequestInItsTrace(): void
    {
        // php.ini-production turns this on, and then no frame records any
        // argument and the assertion below would pass on nothing.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            FixtureClient::withCapturedFixtures()->sendRequest(self::request('PUT', 'https://octonomy.test/api/v2/tags/t1'));
            self::fail('PUT is no method an endpoint takes');
        } catch (FixtureClientException $e) {
            $frames = array_values(array_filter(
                $e->getTrace(),
                static fn(array $frame): bool => ($frame['class'] ?? '') === FixtureClient::class && $frame['function'] === 'sendRequest',
            ));
            self::assertCount(1, $frames);
            // The request, Authorization header and all, is sendRequest()'s argument.
            self::assertArrayNotHasKey('args', $frames[0]);
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function basePaths(): iterable
    {
        yield 'a gateway prefix' => ['/octonomy'];
        yield 'one holding the api prefix' => ['/edge/api/v2/proxy'];
        // Then the prefix base_url ends with and the SDK's own share a slash.
        yield 'one ending in the api prefix' => ['/api/v2'];
        yield 'one ending in the other api prefix' => ['/api/v1'];
    }

    #[DataProvider('basePaths')]
    public function testABaseUrlWithAPathOfItsOwnIsSkippedEvenWhenItLooksLikeTheApi(string $basePath): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        $client = new Client([...self::CONFIG, 'base_url' => "https://octonomy.test{$basePath}", 'http_client' => $fake]);

        self::assertSame(self::capturedData('v2/tags/get')['id'], $client->tags()->get('t1')->id);

        $request = $fake->assertSent('GET', 'tags/t1');
        self::assertSame("{$basePath}/api/v2/tags/t1", $request->getUri()->getPath());
    }

    /**
     * Requests whose path reads as two endpoints, with the base_url that sends
     * each. Both send GET /api/v2/resources/api/v2/tags.
     *
     * @return iterable<string, array{string, \Closure(Client): mixed}>
     */
    public static function pathsWithTwoReadings(): iterable
    {
        yield 'a base_url ending in /api/v2/resources, listing tags' => [
            'https://octonomy.test/api/v2/resources',
            static fn(Client $c) => $c->tags()->list(),
        ];
        yield "the tags of a resource of type 'api' and id 'v2'" => [
            'https://octonomy.test',
            static fn(Client $c) => $c->resources()->listTags('api', 'v2', 'app'),
        ];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('pathsWithTwoReadings')]
    public function testAPathWithTwoReadingsIsRefusedNamingBothRatherThanGuessed(string $baseUrl, \Closure $call): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        $client = new Client([...self::CONFIG, 'base_url' => $baseUrl, 'http_client' => $fake]);

        try {
            $call($client);
            self::fail('the path is the tag list or a resource\'s tags, and the request does not say which');
        } catch (TransportException $e) {
            self::assertStringContainsString(
                'FixtureClient cannot tell which endpoint GET /api/v2/resources/api/v2/tags is: it reads as'
                . ' resources/api/v2/tags below /api/v2 and as tags below /api/v2.',
                $e->getMessage(),
            );
        }

        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('It answered no request at all.');
        $fake->assertSent('GET', 'tags');
    }

    public function testAssertSentIgnoresPercentEncodingOnBothSides(): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        $client = new Client([...self::CONFIG, 'http_client' => $fake]);

        $client->tags()->get('a b');
        $client->tags()->get('.');

        self::assertSame('/api/v2/tags/a%20b', $fake->assertSent('GET', 'tags/a b')->getUri()->getPath());
        self::assertSame('/api/v2/tags/a%20b', $fake->assertSent('GET', 'tags/a%20b')->getUri()->getPath());
        self::assertSame('/api/v2/tags/%2E', $fake->assertSent('GET', '/tags/.')->getUri()->getPath());
    }

    public function testAssertSentReturnsTheLastMatchingRequest(): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        $client = new Client([...self::CONFIG, 'http_client' => $fake]);

        $client->tags()->get('t1');
        $client->tags()->get('t1', applicationId: 'commerce');

        self::assertSame('application_id=commerce', $fake->assertSent('GET', 'tags/t1')->getUri()->getQuery());
    }

    public function testAssertSentFailsNamingWhatWasAnswered(): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        $client = new Client([...self::CONFIG, 'http_client' => $fake]);
        $client->tags()->get('t2');
        $client->tags()->create(new TagCreate('Featured', 'featured', 'label'));

        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('octonomy: FixtureClient answered no GET request for tags/t1. It answered: GET tags/t2, POST tags.');

        $fake->assertSent('GET', 'tags/t1');
    }

    public function testAssertSentFailsOnTheMethodAlone(): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        (new Client([...self::CONFIG, 'http_client' => $fake]))->tags()->delete('t1');

        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('answered no GET request for tags/t1. It answered: DELETE tags/t1.');

        $fake->assertSent('GET', 'tags/t1');
    }

    public function testAssertSentFailsWhenNothingWasAnswered(): void
    {
        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('It answered no request at all.');

        FixtureClient::withCapturedFixtures()->assertSent('GET', 'tags');
    }

    public function testAnUnmatchedRequestIsNotOneItAnswered(): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        try {
            $fake->sendRequest(self::request('PUT', 'https://octonomy.test/api/v2/tags/t1'));
        } catch (FixtureClientException) {
        }

        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('It answered no request at all.');

        $fake->assertSent('PUT', 'tags/t1');
    }

    public function testAHealthClientIsAnsweredWithTheProbesCapturedUp(): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        $health = new HealthClient(['base_url' => 'https://octonomy.test', 'http_client' => $fake]);

        self::assertTrue($health->health()->live()->ok);
        self::assertTrue($health->health()->ready()->ok);
        self::assertSame('https://octonomy.test/health/ready', (string) $fake->assertSent('GET', 'health/ready')->getUri());
    }

    public function testItHasNoSurfaceForStateWritesPagingOrInjection(): void
    {
        $class = new \ReflectionClass(FixtureClient::class);
        $constructor = $class->getConstructor();
        $public = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            $class->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($public);

        self::assertTrue($class->isFinal());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame([], $constructor->getParameters());
        self::assertSame(['assertSent', 'sendRequest', 'withCapturedFixtures'], $public);
        self::assertSame([], $class->getProperties(\ReflectionProperty::IS_PUBLIC));
        self::assertSame([], (new \ReflectionMethod(FixtureClient::class, 'withCapturedFixtures'))->getParameters());
    }
}
