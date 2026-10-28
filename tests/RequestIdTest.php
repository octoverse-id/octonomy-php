<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The caller's request id (#19): RequestOptions::$requestId is sent as
 * X-Request-ID on the one call that carries it, and nothing is sent without
 * it, so the server mints its own. The SDK never mints one.
 *
 * Absence is asserted with hasHeader(), not by comparing getHeaderLine() with
 * '': an empty header reads as '' too, and the server would read it as absent
 * and mint over it, so it must not go out either.
 */
final class RequestIdTest extends SdkTestCase
{
    private const REQUEST_ID = 'req-checkout-42';

    /**
     * @param \Closure(Client, ?RequestOptions): mixed $call
     */
    #[DataProvider('calls')]
    public function testNoRequestIdIsSentUnlessTheCallerSuppliesOne(string $operation, \Closure $call): void
    {
        foreach ([null, new RequestOptions(), RequestOptions::withActor('svc-call')] as $options) {
            $client = $this->clientWith([self::captured("v2/{$operation}")], ['actor_id' => 'svc-config']);

            $call($client, $options);

            self::assertFalse($this->lastRequest()->hasHeader('X-Request-ID'), 'the SDK minted a request id');
        }
    }

    /**
     * @param \Closure(Client, ?RequestOptions): mixed $call
     */
    #[DataProvider('calls')]
    public function testASuppliedRequestIdIsSentAsXRequestId(string $operation, \Closure $call): void
    {
        $client = $this->clientWith([self::captured("v2/{$operation}")]);

        $call($client, RequestOptions::withRequestId(self::REQUEST_ID));

        $request = $this->lastRequest();
        self::assertSame([self::REQUEST_ID], $request->getHeader('X-Request-ID'));
        // Beside the credentials, not in place of them.
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        self::assertSame('tenant-1', $request->getHeaderLine('X-Tenant-ID'));
    }

    /**
     * The other PSR-18 path: a client that is not Guzzle's gets the same
     * header through sendRequest().
     */
    public function testASuppliedRequestIdReachesAnyPsr18Client(): void
    {
        $client = $this->psr18ClientWith([self::captured('v2/tags/get')]);

        $client->tags()->get('tag_1', options: RequestOptions::withRequestId(self::REQUEST_ID));

        self::assertSame([self::REQUEST_ID], $this->lastRequest()->getHeader('X-Request-ID'));
    }

    /**
     * An actor is who made the call, a request id which call it was: a
     * mutation usually wants both, and neither replaces the other.
     */
    public function testARequestIdTravelsWithAnActorAndANamespace(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')]);

        $client->tags()->get('tag_1', 'harness-app', options: new RequestOptions(
            actorId: 'svc-call',
            namespace: new NamespaceScope('merchant', 'harness-merchant-a'),
            requestId: self::REQUEST_ID,
        ));

        $request = $this->lastRequest();
        self::assertSame(self::REQUEST_ID, $request->getHeaderLine('X-Request-ID'));
        self::assertSame('svc-call', $request->getHeaderLine('X-Actor-ID'));
        self::assertSame('harness-merchant-a', $request->getHeaderLine('X-Namespace-ID'));
    }

    /**
     * A request id names one request, so the next call on the same client
     * sends none.
     */
    public function testARequestIdDoesNotOutliveItsRequest(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get'), self::captured('v2/tags/get')]);

        $client->tags()->get('tag_1', options: RequestOptions::withRequestId(self::REQUEST_ID));
        $client->tags()->get('tag_1');

        self::assertFalse($this->lastRequest()->hasHeader('X-Request-ID'));
    }

    /**
     * Nor is there a client-wide one: Config has no field for it, since a
     * default would stamp every call with the same id.
     */
    public function testConfigHasNoRequestId(): void
    {
        $constructor = (new \ReflectionClass(Config::class))->getConstructor();
        self::assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            self::assertStringNotContainsStringIgnoringCase('requestId', $parameter->getName());
        }
    }

    /**
     * The server's 100-character column is its rule, not the SDK's: a longer id
     * is sent as given, and the server answers for it.
     */
    public function testALongRequestIdIsSentAsGiven(): void
    {
        $long = str_repeat('r', 150);
        $client = $this->clientWith([self::captured('v2/tags/get')]);

        $client->tags()->get('tag_1', options: RequestOptions::withRequestId($long));

        self::assertSame($long, $this->lastRequest()->getHeaderLine('X-Request-ID'));
    }

    #[DataProvider('unusableIds')]
    public function testAnUnusableRequestIdIsRefusedWhenOptionsAreBuilt(string $id, string $message): void
    {
        foreach ([
            'withRequestId()' => static fn(): RequestOptions => RequestOptions::withRequestId($id),
            'the constructor' => static fn(): RequestOptions => new RequestOptions(requestId: $id),
        ] as $how => $build) {
            try {
                $build();
                self::fail("{$how} accepted the request id");
            } catch (\InvalidArgumentException $e) {
                self::assertSame($message, $e->getMessage(), $how);
                self::assertNull($e->getPrevious());
            }
        }
    }

    /**
     * On the PHP 7.4 line, which has no readonly, a request id can be changed
     * after RequestOptions checked it. Transport checks it again, refuses it
     * before anything is sent, and removes the arguments of the SDK's frames,
     * which hold the request body.
     */
    #[DataProvider('unusableIds')]
    public function testARequestIdChangedAfterItsCheckIsRefusedBeforeSending(string $id, string $message): void
    {
        // Built without its constructor, as RedactionTest builds one: bound to
        // the class, which may initialize its own readonly properties.
        $options = (new \ReflectionClass(RequestOptions::class))->newInstanceWithoutConstructor();
        \Closure::bind(static function (RequestOptions $options, array $values): void {
            foreach ($values as $name => $value) {
                $options->{$name} = $value;
            }
        }, null, RequestOptions::class)($options, ['actorId' => null, 'namespace' => null, 'requestId' => $id]);
        $client = $this->clientWith([]);

        try {
            $client->tags()->create(new TagCreate(name: 'Featured', slug: 'featured', type: 'label'), $options);
            self::fail('expected an \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
            $this->assertNothingSent();
            $frames = [];
            foreach ($e->getTrace() as $frame) {
                $name = ($frame['class'] ?? '') . '::' . $frame['function'];
                $frames[] = $name;
                if (str_starts_with($name, 'Octoverse\\Octonomy\\')) {
                    self::assertArrayNotHasKey('args', $frame, "{$name} kept its arguments");
                }
            }
            self::assertContains('Octoverse\\Octonomy\\Resource\\TagService::create', $frames);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableIds(): iterable
    {
        $blank = 'octonomy: requestId is blank, and the server reads an empty X-Request-ID as absent. Fix: pass'
            . ' the id you log for this call, such as a UUID or your trace id, or leave requestId null to have'
            . ' the server mint one.';
        $byte = static fn(string $hex, int $offset): string => "octonomy: requestId holds byte 0x{$hex} at offset {$offset},"
            . ' outside printable ASCII (0x20 to 0x7E), which is all a request id may hold. A header cannot carry a'
            . ' control character other than a tab, and the server reads a byte above 0x7E as Latin-1, so it would'
            . ' record an id other than the one you logged. Fix: pass a printable ASCII id, such as a UUID, and'
            . ' remove any trailing newline left by reading it from a file.';
        $space = static fn(string $id, string $trimmed): string => "octonomy: requestId \"{$id}\" begins or ends with a"
            . ' space, which is trimmed from a header value on the way, so the server would record'
            . " \"{$trimmed}\" and not the id you logged. Fix: pass the trimmed id.";

        yield 'an empty id' => ['', $blank];
        yield 'an id of spaces only' => ['   ', $blank];
        yield 'an id of a newline only' => ["\n", $blank];
        yield 'a trailing newline' => ["req-abc\n", $byte('0A', 7)];
        yield 'a carriage return' => ["req\rabc", $byte('0D', 3)];
        // The server would record a tab as sent, but it is a control character,
        // and the Go SDK refuses it too: one id, one answer, in both SDKs.
        yield 'a tab' => ["req\tabc", $byte('09', 3)];
        yield 'a NUL byte' => ["req\x00abc", $byte('00', 3)];
        yield 'a DEL byte' => ["req\x7Fabc", $byte('7F', 3)];
        // Probed against 3.2.1: recorded as "req-cafÃ©".
        yield 'a non-ASCII id' => ['req-café', $byte('C3', 7)];
        yield 'a leading space' => [' req-abc', $space(' req-abc', 'req-abc')];
        yield 'a trailing space' => ['req-abc ', $space('req-abc ', 'req-abc')];
    }

    /**
     * One call for each way Transport reads a response, plus the two writes
     * whose audit row and event the id is mostly sent for, keyed by the
     * captured operation that answers it.
     *
     * @return iterable<string, array{string, \Closure(Client, ?RequestOptions): mixed}>
     */
    public static function calls(): iterable
    {
        yield 'create(), a write' => ['tags/create', static fn(Client $c, ?RequestOptions $o): mixed => $c->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label'),
            $o,
        )];
        yield 'get(), through one()' => ['tags/get', static fn(Client $c, ?RequestOptions $o): mixed => $c->tags()->get('tag_1', options: $o)];
        yield 'list(), through many()' => ['tags/list', static fn(Client $c, ?RequestOptions $o): mixed => $c->tags()->list(new TagListParams(), $o)];
        yield 'update(), a write' => ['tags/update', static fn(Client $c, ?RequestOptions $o): mixed => $c->tags()->update(
            'tag_1',
            new TagUpdate(description: 'Hand-picked by the merchandising team'),
            options: $o,
        )];
        yield 'delete(), through none()' => ['tags/delete', static function (Client $c, ?RequestOptions $o): mixed {
            $c->tags()->delete('tag_1', options: $o);

            return null;
        }];
    }
}
