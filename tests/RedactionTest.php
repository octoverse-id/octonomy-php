<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Covers the redaction rules (Internal\Redaction): no exception the SDK throws
 * carries the token, the Authorization header, the request body or X-Actor-ID,
 * in its message, its RequestContext, its trace, or an exception it chains.
 *
 * Each path plants a distinct value for every item on the list, shows that the
 * value really was sent or really passed through the SDK's frames, so that its
 * absence is not vacuous, and then searches the whole exception for it.
 */
final class RedactionTest extends SdkTestCase
{
    private const TOKEN = 'octo_tokenprefix_tokensecret';

    /** The values planted for each item of Redaction::ALL. */
    private const PLANTED = [
        Redaction::TOKEN => [self::TOKEN, 'tokensecret'],
        Redaction::AUTHORIZATION => ['Bearer ' . self::TOKEN],
        Redaction::BODY => ['Name From The Body', 'slug-from-the-body', 'description-from-the-body'],
        Redaction::ACTOR => ['actor-from-config', 'actor-from-the-call'],
    ];

    /** The frame that holds the body and the RequestOptions on every request path. */
    private const CREATE_FRAME = 'Octoverse\\Octonomy\\Resource\\TagService::create';

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        // php.ini-production, which CI's PHP uses, turns this on, and then no
        // frame records any argument and every trace assertion here would pass
        // on nothing. Error trackers run with it off, which is the case to test.
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
    }

    public function testEveryItemOnTheListIsPlanted(): void
    {
        self::assertSame(Redaction::ALL, array_keys(self::PLANTED));
    }

    /**
     * Path 1: the server answered, with an error.
     */
    public function testAnAnsweredRequestCarriesNoneOfThem(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/conflict')], self::config());

        try {
            $client->tags()->create(self::body(), self::options());
            self::fail('expected a ConflictException');
        } catch (ConflictException $e) {
            $this->assertEverythingWasSent();
            self::assertCarriesNone($e, self::CREATE_FRAME);
        }
    }

    /**
     * Path 2: no response at all. Guzzle's exception holds the request it could
     * not send, so it is not chained.
     */
    public function testAnUnansweredRequestCarriesNoneOfThem(): void
    {
        $client = $this->clientThatCannotConnect(self::config());

        try {
            $client->tags()->create(self::body(), self::options());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            $this->assertEverythingWasSent();
            self::assertNull($e->getPrevious());
            self::assertCarriesNone($e, self::CREATE_FRAME);
        }
    }

    /**
     * An HTTP client or middleware can quote the request in its error. The
     * message quotes that error with every value the request carried replaced.
     */
    public function testAClientErrorThatQuotesTheRequestIsRedacted(): void
    {
        $client = $this->clientThatCannotConnect(
            self::config(),
            static fn(RequestInterface $request): string => sprintf(
                'refused, Authorization: %s, X-Actor-ID: %s, body: %s',
                $request->getHeaderLine('Authorization'),
                $request->getHeaderLine('X-Actor-ID'),
                (string) $request->getBody(),
            ),
        );

        try {
            $client->tags()->create(self::body(), self::options());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            $this->assertEverythingWasSent();
            self::assertCarriesNone($e, self::CREATE_FRAME);
            self::assertStringContainsString(
                '"refused, Authorization: Bearer [redacted], X-Actor-ID: [redacted], body: [redacted]"',
                $e->getMessage(),
            );
        }
    }

    /**
     * A body of nothing but a boolean is still the body: sent whole, it is
     * replaced whole, and its value alone where it stands alone.
     */
    public function testABodyWithNoStringInItIsRedactedToo(): void
    {
        $client = $this->clientThatCannotConnect(
            self::config(),
            static fn(RequestInterface $request): string => sprintf(
                'body=%s, is_active: %s',
                (string) $request->getBody(),
                json_decode((string) $request->getBody(), true)['is_active'] === false ? 'false' : 'true',
            ),
        );

        try {
            $client->tags()->update('tag_1', new TagUpdate(isActive: false));
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame('{"is_active":false}', (string) $this->lastRequest()->getBody());
            self::assertStringContainsString('"body=[redacted], is_active: [redacted]"', $e->getMessage());
            self::assertStringNotContainsString('false', $e->getMessage());
        }
    }

    /**
     * A field the model rejects can hold what the request sent, when a server
     * echoes it. The report quotes it redacted, and so does every report it
     * chains.
     */
    public function testAMalformedFieldThatEchoesTheRequestIsRedacted(): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom(
            'v2/tags/update',
            SyntheticNegative::WRONG_TYPE,
            static fn(array $body): array => array_replace_recursive($body, ['data' => ['created_at' => 'Name From The Body']]),
        )], self::config());

        try {
            $client->tags()->update('tag_1', new TagUpdate(name: 'Name From The Body'), 'harness-app', self::options());
            self::fail('expected a MalformedResponseException');
        } catch (MalformedResponseException $e) {
            self::assertStringContainsString('field "data.created_at" must be an RFC 3339 timestamp, got "[redacted]". ', $e->getMessage());
            self::assertInstanceOf(MalformedResponseException::class, $e->getPrevious());
            self::assertCarriesNone($e, 'Octoverse\\Octonomy\\Resource\\TagService::update');
        }
    }

    /**
     * Middleware runs after the body is encoded and before any failure is
     * known, and an object in metadata is shared with the caller. The
     * redaction is a snapshot of what was sent, so changing the object then
     * does not let the sent value through.
     */
    public function testABodyChangedAfterItWasSentIsRedactedAsSent(): void
    {
        $nested = new \stdClass();
        $nested->field = 'alpha-private-value';
        $client = $this->clientThatCannotConnect(
            self::config(),
            static function (RequestInterface $request) use ($nested): string {
                $sent = (string) $request->getBody();
                $nested->field = 'changed-after-send';

                return 'value=' . (string) json_decode($sent)->metadata->nested->field;
            },
        );

        try {
            $client->tags()->create(new TagCreate(name: 'n', slug: 's', type: 'label', metadata: ['nested' => $nested]));
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('"value=[redacted]"', $e->getMessage());
            self::assertStringNotContainsString('alpha-private-value', $e->getMessage());
        }
    }

    /**
     * The same for the actor id on the PHP 7.4 line, which has no readonly:
     * middleware that changes it mid-request does not change what is
     * redacted. PHP 8.2 refuses the write; either way the actor id that was
     * sent stays out of the message.
     */
    public function testAnActorIdChangedAfterItWasSentIsRedactedAsSent(): void
    {
        $options = self::options();
        $client = $this->clientThatCannotConnect(
            self::config(),
            static function (RequestInterface $request) use ($options): string {
                try {
                    (new \ReflectionProperty(RequestOptions::class, 'actorId'))->setValue($options, 'changed-actor');
                } catch (\Error $e) {
                    // PHP 8.2: readonly.
                }

                return 'actor=' . $request->getHeaderLine('X-Actor-ID');
            },
        );

        try {
            $client->tags()->create(self::body(), $options);
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('"actor=[redacted]"', $e->getMessage());
            self::assertStringNotContainsString('actor-from-the-call', $e->getMessage());
        }
    }

    /**
     * A handler or middleware can throw anything, not only a Guzzle exception.
     * It becomes a TransportException like any client failure: quoted
     * redacted, not chained, and with the SDK's frames scrubbed.
     */
    public function testAnyThrowableFromTheClientIsRedacted(): void
    {
        $client = $this->clientWhoseHttpClientThrows(
            self::config(),
            static fn(RequestInterface $request): \Throwable => new \RuntimeException(sprintf(
                'middleware refused %s with %s',
                (string) $request->getBody(),
                $request->getHeaderLine('Authorization'),
            )),
        );

        try {
            $client->tags()->create(self::body(), self::options());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            $this->assertEverythingWasSent();
            self::assertNull($e->getPrevious());
            self::assertStringContainsString('with RuntimeException: "middleware refused [redacted] with Bearer [redacted]"', $e->getMessage());
            self::assertCarriesNone($e, self::CREATE_FRAME);
        }
    }

    /**
     * A body that cannot be encoded is never sent. The refusal names why in
     * fixed words, or names the exception an object's jsonSerialize() threw by
     * its class alone, and chains nothing: a message or a frame can hold the
     * body.
     */
    #[DataProvider('unencodableBodies')]
    public function testABodyThatCannotBeEncodedIsRefusedWithoutQuotingIt(\Closure $metadata, string $why): void
    {
        $client = $this->clientWith([], self::config());

        try {
            $client->tags()->create(
                new TagCreate(name: 'Name From The Body', slug: 'slug-from-the-body', type: 'label', metadata: $metadata()),
                self::options(),
            );
            self::fail('expected an \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith(
                "octonomy: the body of POST /api/v2/tags in namespace merchant:harness-merchant-a cannot be encoded as JSON: {$why}.",
                $e->getMessage(),
            );
            self::assertNull($e->getPrevious());
            $this->assertNothingSent();
            self::assertCarriesNone($e, self::CREATE_FRAME);
        }
    }

    /**
     * @return iterable<string, array{\Closure(): array<string, mixed>, string}>
     */
    public static function unencodableBodies(): iterable
    {
        yield 'a string that is not UTF-8' => [
            static fn(): array => ['note' => "description-from-the-body\xB1"],
            'a string in it is not valid UTF-8',
        ];
        yield 'an object whose jsonSerialize() throws' => [
            static fn(): array => ['note' => new class implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    throw new \RuntimeException('cannot encode description-from-the-body');
                }
            }],
            'an object in it threw RuntimeException from jsonSerialize(), whose message is not shown',
        ];
        // Not json_encode()'s own refusal, so not described as one, even with
        // a code json_encode() uses.
        yield 'an object whose jsonSerialize() throws a JsonException of its own' => [
            static fn(): array => ['note' => new class implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    throw new \JsonException('cannot encode description-from-the-body', JSON_ERROR_UTF8);
                }
            }],
            'an object in it threw JsonException from jsonSerialize(), whose message is not shown',
        ];
    }

    /**
     * On the PHP 7.4 line middleware can change the caller's NamespaceScope
     * mid-request. The context, and the message, keep the namespace that was
     * sent. PHP 8.2 refuses the write; either way they match the header.
     */
    public function testANamespaceChangedAfterItWasSentIsReportedAsSent(): void
    {
        $namespace = new NamespaceScope('merchant', 'sent-id');
        $client = $this->clientThatCannotConnect(
            self::config(),
            static function (RequestInterface $request) use ($namespace): string {
                try {
                    (new \ReflectionProperty(NamespaceScope::class, 'id'))->setValue($namespace, 'changed-after-send');
                } catch (\Error $e) {
                    // PHP 8.2: readonly.
                }

                return 'refused';
            },
        );

        try {
            $client->tags()->get('tag_1', 'harness-app', options: new RequestOptions(namespace: $namespace));
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame('sent-id', $this->lastRequest()->getHeaderLine('X-Namespace-ID'));
            self::assertNotNull($e->context->namespace);
            self::assertSame('sent-id', $e->context->namespace->id);
            self::assertStringContainsString('in namespace merchant:sent-id ', $e->getMessage());
        }
    }

    /**
     * A client can throw with a response on its exception, as middleware that
     * throws on a status does (here the helper makes the response up). The
     * message names its status rather than saying there was none.
     */
    public function testAClientFailureHoldingAResponseNamesIt(): void
    {
        $client = $this->clientThatCannotConnect(self::config(), null, 503);

        try {
            $client->tags()->create(self::body(), self::options());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringStartsWith(
                'octonomy: the HTTP client failed on POST /api/v2/tags in namespace merchant:harness-merchant-a to'
                . ' https://octonomy.test after an HTTP 503 response, with ',
                $e->getMessage(),
            );
            self::assertStringContainsString('cannot tell from this why the request failed, or whether the server received and applied it.', $e->getMessage());
            self::assertCarriesNone($e, self::CREATE_FRAME);
        }
    }

    /**
     * The PHP 7.4 line has no readonly, so a value its constructor checked can
     * be changed afterwards. Transport checks every header value again before
     * Guzzle sees one. Built here without its constructor, which both lines
     * allow, so the check runs on both.
     */
    #[DataProvider('valuesChangedAfterTheirCheck')]
    public function testAHeaderValueThatSkippedItsCheckIsRefusedBeforeSending(\Closure $options, string $header): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')], self::config());

        try {
            $client->tags()->get('tag_1', 'harness-app', options: $options());
            self::fail('expected an \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith("octonomy: the {$header} value holds a line break", $e->getMessage());
            $this->assertNothingSent();
            self::assertCarriesNone($e, 'Octoverse\\Octonomy\\Resource\\TagService::get');
        }
    }

    /**
     * @return iterable<string, array{\Closure(): RequestOptions, string}>
     */
    public static function valuesChangedAfterTheirCheck(): iterable
    {
        yield 'an actor id' => [
            static fn(): RequestOptions => self::unchecked(RequestOptions::class, ['actorId' => "actor-from-the-call\n", 'namespace' => null]),
            'X-Actor-ID',
        ];
        yield 'a namespace id' => [
            static fn(): RequestOptions => new RequestOptions(namespace: self::unchecked(NamespaceScope::class, ['type' => 'merchant', 'id' => "m-42\r\n"])),
            'X-Namespace-ID',
        ];
    }

    /**
     * A 2xx that is not JSON, here one that echoes the request. The decoder's
     * exception is not chained: json_decode()'s frame in its trace holds that
     * raw body.
     */
    public function testAnUndecodableAnswerCarriesNoneOfThem(): void
    {
        $client = $this->clientWith([
            self::syntheticNegative(SyntheticNegative::INVALID_JSON, 201, 'echo: Name From The Body, actor-from-the-call {', ['Content-Type' => 'text/plain']),
        ], self::config());

        try {
            $client->tags()->create(self::body(), self::options());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            $this->assertEverythingWasSent();
            self::assertNull($e->getPrevious());
            self::assertCarriesNone($e, self::CREATE_FRAME);
        }
    }

    /**
     * An injected client's own defaults cannot replace the SDK's body or
     * Authorization header, and a default X-Actor-ID it adds, sent because the
     * request names none, is redacted like the SDK's own.
     */
    public function testAClientsOwnDefaultsDoNotChangeWhatIsSentOrRedacted(): void
    {
        $client = $this->clientWhoseHttpClientThrows(
            ['token' => self::TOKEN],
            static fn(RequestInterface $request): \Throwable => new \RuntimeException(sprintf(
                'refused %s with %s as %s',
                (string) $request->getBody(),
                $request->getHeaderLine('Authorization'),
                $request->getHeaderLine('X-Actor-ID'),
            )),
            [
                'json' => ['note' => 'wire-only-default-body'],
                'auth' => ['user', 'default-password'],
                'headers' => ['X-Actor-ID' => 'actor-from-the-client-defaults'],
            ],
        );

        try {
            $client->tags()->create(self::body());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            $request = $this->lastRequest();
            self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
            self::assertSame('Name From The Body', $this->lastJsonBody()['name']);
            self::assertSame('actor-from-the-client-defaults', $request->getHeaderLine('X-Actor-ID'));
            self::assertStringContainsString('"refused [redacted] with Bearer [redacted] as [redacted]"', $e->getMessage());
            self::assertStringNotContainsString('actor-from-the-client-defaults', $e->getMessage());
        }
    }

    /**
     * Refused before sending: PHP's own \LogicException, thrown from frames that
     * hold the body and the RequestOptions all the same.
     */
    public function testARequestRefusedBeforeSendingCarriesNoneOfThem(): void
    {
        $client = $this->clientWith([], self::config() + ['api_version' => ApiVersion::V1]);

        try {
            $client->tags()->create(self::body(), self::options());
            self::fail('expected a \LogicException');
        } catch (\LogicException $e) {
            $this->assertNothingSent();
            self::assertCarriesNone($e, self::CREATE_FRAME);
        }
    }

    /**
     * Configuration: the token passes through Config::__construct(),
     * Config::fromArray() and Client::__construct() as an argument, and a
     * refused key chains the first refusal.
     */
    public function testARefusedConfigurationCarriesNoneOfThem(): void
    {
        try {
            new Client(['base_url' => 'https://octonomy.test', 'tenant' => 'tenant-1'] + self::config());
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString("The options also hold 'tenant'", $e->getMessage());
            self::assertInstanceOf(ConfigurationException::class, $e->getPrevious());
            self::assertCarriesNone($e, 'Octoverse\\Octonomy\\Config::__construct');
        }
    }

    /**
     * Guzzle quotes a header value it refuses in full, so a token or actor id
     * with a control character in it is refused before Guzzle sees it, by a
     * message that does not quote it either.
     */
    public function testAValueAHeaderCannotCarryIsRefusedWithoutQuotingIt(): void
    {
        $refusals = [
            'token' => static fn(): mixed => new Client(['base_url' => 'https://octonomy.test', 'token' => self::TOKEN . "\n", 'tenant_id' => 't']),
            'actor_id' => static fn(): mixed => new Client(['base_url' => 'https://octonomy.test', 'token' => 't', 'tenant_id' => 't', 'actor_id' => "actor-from-config\r\n"]),
            'actorId' => static fn(): mixed => new RequestOptions(actorId: "actor-from-the-call\n"),
        ];

        foreach ($refusals as $key => $refuse) {
            try {
                $refuse();
                self::fail("expected {$key} to be refused");
            } catch (ConfigurationException|\InvalidArgumentException $e) {
                self::assertStringStartsWith("octonomy: {$key} holds a line break or another control character", $e->getMessage());
                self::assertCarriesNone($e, null);
            }
        }
    }

    /**
     * Only the SDK's frames lose their arguments: the caller's and PHP's keep
     * theirs, here array_map()'s.
     */
    public function testScrubRemovesTheArgumentsOfSdkFramesOnly(): void
    {
        $e = array_map(static fn(string $value): \Exception => new \Exception($value), ['an argument'])[0];
        self::assertSame(['an argument'], $e->getTrace()[0]['args'] ?? null, 'the trace recorded no arguments to remove');

        Redaction::scrub($e);

        self::assertArrayNotHasKey('args', $e->getTrace()[0]);
        self::assertSame('array_map', $e->getTrace()[1]['function']);
        self::assertSame(['an argument'], $e->getTrace()[1]['args'][1] ?? null);
    }

    /**
     * The body is redacted as it goes on the wire: every value at any depth,
     * an object's included, and every key below the top level, where a key is
     * the caller's content rather than a field name.
     */
    public function testOfTakesTheBodyAsItIsSent(): void
    {
        $object = new \stdClass();
        $object->{'object-key'} = 'object-value';
        $redaction = Redaction::of('octo_tokensecret', null, (string) json_encode([
            'name' => 'Featured',
            'metadata' => ['customer-email' => 'someone@example.test', 'nested' => [$object, 42, 'a/b']],
        ]));

        self::assertSame(
            'name: [redacted]; metadata: {"[redacted]":"[redacted]","[redacted]":[{"[redacted]":"[redacted]"},[redacted],"[redacted]"]}',
            $redaction->text('name: Featured; metadata: {"customer-email":"someone@example.test","nested":[{"object-key":"object-value"},42,"a\\/b"]}'),
        );
    }

    /**
     * Every value as it was sent: an object key that is a number, a float JSON
     * writes with an exponent (and PHP prints in capitals), and negative zero,
     * which goes out as -0.
     */
    public function testOfTakesEveryJsonFormOfTheBody(): void
    {
        $redaction = Redaction::of('octo_tokensecret', null, (string) json_encode(['metadata' => ['123' => 'x', 'rate' => 1e20, 'zero' => -0.0]]));

        self::assertSame(
            'invalid metadata key [redacted] at [redacted] and [redacted] or [redacted]',
            $redaction->text('invalid metadata key 123 at 1.0e+20 and -0 or 1.0E+20'),
        );
    }

    /**
     * A value of eight characters or more is replaced wherever it appears; a
     * shorter one only where it stands alone, so a short actor id does not eat
     * the digits of a port number.
     */
    public function testTextReplacesShortValuesOnlyWhereTheyStandAlone(): void
    {
        $redaction = Redaction::of('octo_tokensecret', '1', (string) json_encode(['name' => 'Featured']));

        self::assertSame(
            'connect to localhost port 8010 after [redacted] ms; token=[redacted]; [redacted]Tag; [redacted]',
            $redaction->text('connect to localhost port 8010 after 1 ms; token=octo_tokensecret; FeaturedTag; Featured'),
        );
        self::assertSame('nothing to hide', Redaction::none()->text('nothing to hide'));
    }

    /**
     * An instance of $class whose properties are set to $values without its
     * constructor running, as a write after construction leaves one on the
     * PHP 7.4 line.
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $values
     *
     * @return T
     */
    private static function unchecked(string $class, array $values): object
    {
        $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        // Bound to the class, which may initialize its own readonly properties.
        \Closure::bind(static function (object $object, array $values): void {
            foreach ($values as $name => $value) {
                $object->{$name} = $value;
            }
        }, null, $class)($object, $values);

        return $object;
    }

    /**
     * @return array<string, string>
     */
    private static function config(): array
    {
        return ['token' => self::TOKEN, 'actor_id' => 'actor-from-config'];
    }

    private static function body(): TagCreate
    {
        return new TagCreate(
            name: 'Name From The Body',
            slug: 'slug-from-the-body',
            type: 'label',
            applicationId: 'harness-app',
            description: 'description-from-the-body',
        );
    }

    private static function options(): RequestOptions
    {
        return new RequestOptions(actorId: 'actor-from-the-call', namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }

    /**
     * Each planted value went out on the wire, so its absence from the
     * exception is the SDK's doing.
     */
    private function assertEverythingWasSent(): void
    {
        $request = $this->lastRequest();
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('actor-from-the-call', $request->getHeaderLine('X-Actor-ID'));
        self::assertSame('Name From The Body', $this->lastJsonBody()['name']);
        self::assertSame('description-from-the-body', $this->lastJsonBody()['description']);
    }

    /**
     * Search $e and every exception it chains: the message, the RequestContext,
     * the trace as a string, and every string or array argument a frame kept.
     * Every SDK frame must have lost its arguments, and $frame, the frame that
     * held the planted values, must be in the trace, so the search is not run
     * over a trace that never held them.
     */
    private static function assertCarriesNone(\Throwable $e, ?string $frame): void
    {
        $frames = [];
        for ($link = $e; $link !== null; $link = $link->getPrevious()) {
            $seen = $link->getMessage() . "\n" . $link->getTraceAsString();
            if ($link instanceof ApiException || $link instanceof TransportException) {
                $seen .= var_export($link->context, true);
            }
            if ($link instanceof MalformedResponseException) {
                $seen .= var_export($link->context, true);
            }
            foreach ($link->getTrace() as $f) {
                $name = ($f['class'] ?? '') . '::' . $f['function'];
                $frames[] = $name;
                if (str_starts_with($name, 'Octoverse\\Octonomy\\')) {
                    self::assertArrayNotHasKey('args', $f, "{$name} kept its arguments");
                }
                foreach ($f['args'] ?? [] as $arg) {
                    if (is_string($arg) || is_array($arg)) {
                        $seen .= print_r($arg, true);
                    }
                }
            }
            foreach (self::PLANTED as $item => $values) {
                foreach ($values as $value) {
                    self::assertStringNotContainsString($value, $seen, "the {$item} reached a " . $link::class);
                }
            }
        }
        if ($frame !== null) {
            self::assertContains($frame, $frames);
        }
    }
}
