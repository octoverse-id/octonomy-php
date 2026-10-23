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
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;

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
     * A 2xx that is not JSON. The decoder's exception is chained, and its own
     * trace runs through the same frames, so it is scrubbed too.
     */
    public function testAnUndecodableAnswerCarriesNoneOfThem(): void
    {
        $client = $this->clientWith([
            self::syntheticNegative(SyntheticNegative::GATEWAY, 201, '<html>proxy</html>', ['Content-Type' => 'text/html']),
        ], self::config());

        try {
            $client->tags()->create(self::body(), self::options());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            $this->assertEverythingWasSent();
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
            self::assertCarriesNone($e, self::CREATE_FRAME);
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
