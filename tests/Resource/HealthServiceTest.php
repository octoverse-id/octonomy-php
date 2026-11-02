<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\HealthClient;
use Octoverse\Octonomy\Model\HealthStatus;
use Octoverse\Octonomy\Resource\HealthService;
use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the health probes (#24): GET /health/live and /health/ready at the
 * server's root, sent with no credentials from a HealthClient and from a
 * Client alike, read from a bare {"status": ...} body, with the 503 of a
 * server that cannot reach its database read as a HealthStatus that did not
 * pass rather than thrown.
 */
final class HealthServiceTest extends SdkTestCase
{
    /** The headers a probe must never carry: it is unauthenticated and has no tenant. */
    private const NEVER_SENT = ['Authorization', 'X-Tenant-ID', 'X-Actor-ID', 'X-Namespace-Type', 'X-Namespace-ID', 'X-Request-ID'];

    /**
     * @param \Closure(HealthService): HealthStatus $probe
     */
    #[DataProvider('passingProbes')]
    public function testAProbeSendsNoCredentialsAndReadsTheBareBody(string $fixture, \Closure $probe, string $path): void
    {
        $client = $this->healthClientWith([self::captured($fixture)]);

        $status = $probe($client->health());

        self::assertTrue($status->ok);
        self::assertSame(HealthStatus::STATUS_OK, $status->status);
        self::assertSame(self::captured($fixture)->json()['status'], $status->status);

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame("https://octonomy.test{$path}", (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('octonomy-php/' . Client::VERSION, $request->getHeaderLine('User-Agent'));
        foreach (self::NEVER_SENT as $header) {
            self::assertFalse($request->hasHeader($header), "a probe sent {$header}");
        }
        self::assertSame('', $this->lastRawBody());
    }

    /**
     * @return iterable<string, array{string, \Closure(HealthService): HealthStatus, string}>
     */
    public static function passingProbes(): iterable
    {
        yield 'live()' => ['health/live', static fn(HealthService $h): HealthStatus => $h->live(), '/health/live'];
        yield 'ready()' => ['health/ready', static fn(HealthService $h): HealthStatus => $h->ready(), '/health/ready'];
    }

    /**
     * The acceptance check of #24: a server that cannot reach its database
     * answers ready() with a HealthStatus that did not pass, and nothing is
     * thrown. Captured with the harness database stopped.
     */
    public function testANotReadyServerIsAnAnswerNotAnException(): void
    {
        $captured = self::captured('health/ready-unavailable');
        self::assertSame(503, $captured->status);

        $status = $this->healthClientWith([$captured])->health()->ready();

        self::assertFalse($status->ok);
        self::assertSame(HealthStatus::STATUS_UNAVAILABLE, $status->status);
        self::assertSame($captured->json()['status'], $status->status);
    }

    /**
     * A Client's probe is the HealthClient's request, byte for byte: neither
     * its token, tenant and actor id nor its api_version prefix go out, on v1
     * as on v2.
     */
    #[DataProvider('versions')]
    public function testAClientsProbeSendsTheSameRequest(string $apiVersion): void
    {
        $this->healthClientWith([self::captured('health/live')])->health()->live();
        $fromHealthClient = $this->lastRequest();

        $client = $this->clientWith([self::captured('health/live')], ['api_version' => $apiVersion, 'actor_id' => 'ops-bot']);
        $status = $client->health()->live();

        self::assertTrue($status->ok);
        $request = $this->lastRequest();
        self::assertSame((string) $fromHealthClient->getUri(), (string) $request->getUri());
        self::assertSame($fromHealthClient->getHeaders(), $request->getHeaders());
        self::assertSame($client->health(), $client->health());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versions(): iterable
    {
        yield 'v1' => [ApiVersion::V1];
        yield 'v2' => [ApiVersion::V2];
    }

    /**
     * A base_url with a path, such as a gateway mount, keeps it: the probe
     * goes under it, not to the host's root.
     */
    public function testAPathOnTheBaseUrlIsKept(): void
    {
        $client = $this->healthClientWith([self::captured('health/ready')], ['base_url' => 'https://gw.test/octonomy/']);

        $client->health()->ready();

        self::assertSame('https://gw.test/octonomy/health/ready', (string) $this->lastRequest()->getUri());
    }

    public function testTheUserAgentCanBeSet(): void
    {
        $this->healthClientWith([self::captured('health/live')], ['user_agent' => 'deploy-check/1.0'])->health()->live();

        self::assertSame('deploy-check/1.0', $this->lastRequest()->getHeaderLine('User-Agent'));
    }

    /**
     * A client that is not Guzzle's is handed the request through
     * sendRequest(), as on Transport.
     */
    public function testAPsr18ClientSendsTheProbe(): void
    {
        $status = $this->healthClientWith([self::captured('health/ready-unavailable')], psr18: true)->health()->ready();

        self::assertFalse($status->ok);
        self::assertSame('https://octonomy.test/health/ready', (string) $this->lastRequest()->getUri());
        self::assertFalse($this->lastRequest()->hasHeader('Authorization'));
    }

    /**
     * An unreachable server is not a server that is not ready: no response
     * raises TransportException, naming the probe and the server. Nothing
     * listens on port 1, so the connection is refused at once.
     */
    public function testAnUnreachableHostRaisesTransportException(): void
    {
        $probe = new HealthClient(['base_url' => 'http://127.0.0.1:1', 'timeout' => 2]);

        try {
            $probe->health()->ready();
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringStartsWith(
                'octonomy: the HTTP client failed on GET /health/ready to http://127.0.0.1:1, with ',
                $e->getMessage(),
            );
            self::assertNull($e->context->apiVersion);
            self::assertSame('/health/ready', $e->context->path);
        }
    }

    public function testAClientThatCannotConnectRaisesTransportException(): void
    {
        $client = $this->clientThatCannotConnect();

        try {
            $client->health()->live();
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('failed on GET /health/live to https://octonomy.test', $e->getMessage());
            self::assertStringNotContainsString('test-token', $e->getMessage());
        }
    }

    /**
     * A Client's probe sends neither its token nor its actor id, but an HTTP
     * client given them can still quote them in its error, and no message
     * may carry either (Redaction).
     */
    public function testAClientsProbeRedactsItsCredentialsFromAClientError(): void
    {
        $token = 'svc_secret_1234567890';
        $actor = 'actor-secret-42';
        $client = $this->clientWhoseHttpClientThrows(
            ['token' => $token, 'actor_id' => $actor],
            static fn(): \RuntimeException => new \RuntimeException("client configured with {$token} for {$actor}"),
        );

        try {
            $client->health()->live();
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertStringNotContainsString($actor, $e->getMessage());
            self::assertStringContainsString('"client configured with [redacted] for [redacted]"', $e->getMessage());
            self::assertFalse($this->lastRequest()->hasHeader('Authorization'));
        }
    }

    /**
     * Only the probe's own 503 is an answer. One without its {"status": ...}
     * body came from something in front of the server, and stays an
     * ApiException, as any other non-2xx does.
     */
    #[DataProvider('notTheProbesAnswer')]
    public function testANon2xxThatIsNotTheProbesAnswerRaises(int $status, string $body, string $class): void
    {
        $client = $this->healthClientWith([
            self::syntheticNegative(SyntheticNegative::GATEWAY, $status, $body, ['Content-Type' => 'text/html']),
        ]);

        try {
            $client->health()->ready();
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($status, $e->statusCode);
            self::assertStringStartsWith(
                "octonomy: GET /health/ready failed with HTTP {$status}, and the body is not an Octonomy error envelope",
                $e->getMessage(),
            );
            self::assertNull($e->context->apiVersion);
        }
    }

    /**
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function notTheProbesAnswer(): iterable
    {
        yield 'a proxy\'s 503 page' => [503, '<html><body>Service Unavailable</body></html>', ApiException::class];
        yield 'a gateway\'s 502 page' => [502, '<html><body>Bad Gateway</body></html>', ApiException::class];
        // A base_url that is not the server's address, or a gateway that
        // routes /api but not /health.
        yield 'a 404 page' => [404, '<html><body>Not Found</body></html>', NotFoundException::class];
    }

    /**
     * A 503 whose body is JSON but not the probe's word is not the probe's
     * answer either.
     */
    public function testA503WithoutTheStatusWordRaises(): void
    {
        $client = $this->healthClientWith([
            self::syntheticNegativeFrom(
                'health/ready-unavailable',
                SyntheticNegative::WRONG_TYPE,
                static fn(array $body): array => ['status' => false] + $body,
            ),
        ]);

        $this->expectException(ApiException::class);
        $client->health()->ready();
    }

    /**
     * A 2xx is read strictly, as every resource is: a pass with no word in it
     * is no evidence of health.
     *
     * @param non-empty-string $message
     */
    #[DataProvider('malformedPasses')]
    public function testAMalformedPassRaises(SyntheticNegative $response, string $message): void
    {
        try {
            $this->healthClientWith([$response])->health()->live();
            self::fail('expected a MalformedResponseException');
        } catch (MalformedResponseException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
            self::assertSame('/health/live', $e->context?->path);
        }
    }

    /**
     * @return iterable<string, array{SyntheticNegative, non-empty-string}>
     */
    public static function malformedPasses(): iterable
    {
        yield 'no status' => [
            self::syntheticNegativeFrom(
                'health/live',
                SyntheticNegative::MISSING_FIELD,
                static fn(array $body): array => ['state' => $body['status']],
            ),
            'octonomy: malformed response from GET /health/live: field "status" is missing.',
        ];
        yield 'a status that is not a string' => [
            self::syntheticNegativeFrom(
                'health/live',
                SyntheticNegative::WRONG_TYPE,
                static fn(array $body): array => ['status' => true] + $body,
            ),
            'octonomy: malformed response from GET /health/live: field "status" must be a string, got a boolean.',
        ];
        yield 'a list for a body' => [
            self::syntheticNegative(SyntheticNegative::WRONG_TYPE, 200, '["ok"]'),
            'octonomy: malformed response from GET /health/live: expected a JSON object with a "status" member.',
        ];
    }

    public function testAPassThatIsNotJsonRaisesTransportException(): void
    {
        $client = $this->healthClientWith([
            self::syntheticNegative(SyntheticNegative::INVALID_JSON, 200, '<html>OK</html>', ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('octonomy: GET /health/live answered HTTP 200 with a body that is not JSON');
        $client->health()->live();
    }

    /**
     * Every captured probe answer reads through HealthTransport, so none sits
     * unread.
     */
    public function testEveryCapturedProbeAnswerReads(): void
    {
        foreach (CapturedFixture::names('health') as $name) {
            $captured = self::captured($name);
            $status = $this->healthClientWith([$captured])->health()->ready();

            self::assertSame($captured->status < 300, $status->ok, $name);
            self::assertSame($captured->json()['status'], $status->status, $name);
        }
    }

    /**
     * Without an http_client, the client the SDK builds has the timeout given,
     * or the 30-second default.
     */
    public function testTheClientTheSdkBuildsHasTheTimeout(): void
    {
        $probe = static fn(array $options): HealthService => (new HealthClient($options))->health();
        self::assertSame(2.5, self::timeoutOfTheClientBuiltFor($probe(['base_url' => 'https://octonomy.test', 'timeout' => 2.5])));
        self::assertSame(30.0, self::timeoutOfTheClientBuiltFor($probe(['base_url' => 'https://octonomy.test'])));
        // A Client's probes are sent with its timeout too.
        $client = new Client(['base_url' => 'https://octonomy.test', 'token' => 't', 'tenant_id' => 'acme', 'timeout' => 5]);
        self::assertSame(5.0, self::timeoutOfTheClientBuiltFor($client->health()));
    }
}
