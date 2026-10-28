<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\Tests\Fixture\ReplayingClient;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the message of every configuration refusal: what is wrong, the value
 * involved where it is not a credential, and the fix. Also which timeout the
 * HTTP client gets (#18).
 */
final class ConfigTest extends SdkTestCase
{
    private const VALID = ['base_url' => 'https://octonomy.test', 'token' => 'svc_token', 'tenant_id' => 'acme'];

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('refusals')]
    public function testARefusalSaysWhatIsWrongAndHowToFixIt(array $options, string $expected): void
    {
        try {
            Config::fromArray($options);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame($expected, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        $valid = self::VALID;

        yield 'no base_url' => [
            ['token' => 'svc_token', 'tenant_id' => 'acme'],
            'octonomy: base_url is missing or blank. It is the address of the Octonomy server, such as'
            . " \"https://octonomy.example.com\". Fix: pass it as the 'base_url' option.",
        ];
        yield 'no token' => [
            ['base_url' => 'https://octonomy.test', 'tenant_id' => 'acme'],
            'octonomy: token is missing or blank. Every request sends it as "Authorization: Bearer <token>".'
            . " Fix: pass a service token as the 'token' option. An Octonomy operator issues one on the server with"
            . ' `python manage.py create_service_token --name <client> --tenant <tenant_id> --scope tags:read`, which'
            . ' prints it once.',
        ];
        yield 'a blank tenant_id' => [
            ['tenant_id' => '  '] + $valid,
            'octonomy: tenant_id is missing or blank. Every request names its tenant in the X-Tenant-ID header, and'
            . " the token must be granted that tenant. Fix: pass the tenant's id as the 'tenant_id' option: the"
            . ' --tenant the token was issued for.',
        ];
        yield 'a base_url without a scheme' => [
            ['base_url' => 'localhost:8000'] + $valid,
            'octonomy: base_url must be an absolute URL, got "localhost:8000", which has no scheme. Fix: write it out'
            . ' in full, with "https://" or "http://", such as "https://octonomy.example.com".',
        ];
        yield 'a base_url without a host' => [
            ['base_url' => 'file:/srv/octonomy'] + $valid,
            'octonomy: base_url must be an absolute URL, got "file:/srv/octonomy", which has no host. Fix: write it'
            . ' out in full, with "https://" or "http://", such as "https://octonomy.example.com".',
        ];
        yield 'a base_url that does not parse' => [
            ['base_url' => 'http:///octonomy'] + $valid,
            'octonomy: base_url must be an absolute URL, got "http:///octonomy", which cannot be parsed as a URL. Fix:'
            . ' write it out in full, with "https://" or "http://", such as "https://octonomy.example.com".',
        ];
        // The type alone: the value is the token.
        yield 'a token that is not a string' => [
            ['token' => 12345] + $valid,
            'octonomy: token must be a string, got integer. Fix: pass token as a string.',
        ];
        yield 'a timeout that is not a number' => [
            ['timeout' => '30'] + $valid,
            'octonomy: timeout must be an int or a float, got string. Fix: pass a number, such as 30 or 2.5.',
        ];
        yield 'a timeout together with an http_client' => [
            ['timeout' => 2.5, 'http_client' => self::anHttpClient()] + $valid,
            'octonomy: timeout and http_client are both set, and the timeout of 2.5 seconds would be ignored: the'
            . ' SDK sets the timeout of only the HTTP client it builds itself. Fix: set the timeout on the client'
            . " passed as http_client, such as Guzzle's 'timeout' option, or leave http_client out to have the SDK"
            . ' build a client with this timeout.',
        ];
        yield 'a request_factory that is not one' => [
            ['request_factory' => new \stdClass()] + $valid,
            'octonomy: request_factory must implement Psr\\Http\\Message\\RequestFactoryInterface (PSR-17), got stdClass.'
            . ' Fix: pass a PSR-17 factory, such as the one your PSR-7 library provides, or leave request_factory out to'
            . ' use the default one.',
        ];
        yield 'a stream_factory that is not one' => [
            ['stream_factory' => 'factory'] + $valid,
            'octonomy: stream_factory must implement Psr\\Http\\Message\\StreamFactoryInterface (PSR-17), got string.'
            . ' Fix: pass a PSR-17 factory, such as the one your PSR-7 library provides, or leave stream_factory out to'
            . ' use the default one.',
        ];
        yield 'an api_version given as null' => [
            ['api_version' => null] + $valid,
            'octonomy: api_version must be "v1" or "v2", got null. Fix: leave api_version out for "v2", the default'
            . ' (Octonomy server 2.0.0 or newer), or pass "v1" for an older server.',
        ];
        yield 'an api_version in capitals' => [
            ['api_version' => 'V2'] + $valid,
            'octonomy: api_version must be "v1" or "v2", got "V2". Fix: use "v2" (ApiVersion::V2, the default, for'
            . ' Octonomy server 2.0.0 or newer) or "v1" (ApiVersion::V1, for an older server); both are lowercase.',
        ];
        yield 'a token with a trailing newline' => [
            ['token' => "svc_token\n"] + $valid,
            'octonomy: token holds a line break or another control character, which the Authorization header cannot'
            . ' carry; the value is not shown. Fix: remove the control characters, such as a trailing newline left by'
            . ' reading the value from a file.',
        ];
        yield 'a tenant_id with a trailing newline' => [
            ['tenant_id' => "acme\n"] + $valid,
            'octonomy: tenant_id holds a line break or another control character, which the X-Tenant-ID header'
            . ' cannot carry; the value is not shown. Fix: remove the control characters, such as a trailing newline'
            . ' left by reading the value from a file.',
        ];
        yield 'a user_agent with a null byte' => [
            ['user_agent' => "my-app/1.0\0"] + $valid,
            'octonomy: user_agent holds a line break or another control character, which the User-Agent header'
            . ' cannot carry; the value is not shown. Fix: remove the control characters, such as a trailing newline'
            . ' left by reading the value from a file.',
        ];
        yield 'an actor_id with a carriage return' => [
            ['actor_id' => "svc-catalog\r"] + $valid,
            'octonomy: actor_id holds a line break or another control character, which the X-Actor-ID header cannot'
            . ' carry; the value is not shown. Fix: remove the control characters, such as a trailing newline left by'
            . ' reading the value from a file.',
        ];
    }

    /**
     * The class is named, never the object. (Guzzle's namespace and the PSR-18
     * interface's full name are left out of the expected text: only the
     * fixture helpers name them.)
     */
    public function testAnHttpClientOfAnotherKindIsNamedByItsClass(): void
    {
        try {
            Config::fromArray(['http_client' => new \stdClass(), 'base_url' => 'https://octonomy.test', 'token' => 't', 'tenant_id' => 'acme']);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringStartsWith('octonomy: http_client must implement Psr\\Http\\', $e->getMessage());
            self::assertStringContainsString(
                '\\ClientInterface (PSR-18), got stdClass. Fix: pass a PSR-18 client, such as a ',
                $e->getMessage(),
            );
            self::assertStringEndsWith(
                '\\Client configured with any middleware you need, or leave http_client out to use a default one.',
                $e->getMessage(),
            );
        }
    }

    /**
     * A misspelt key is read as a missing one, so the refusal names the keys
     * fromArray() did not read, and never their values.
     */
    public function testARefusalNamesTheKeysItDidNotRead(): void
    {
        try {
            Config::fromArray(['baseUrl' => 'https://octonomy.test', 'token' => 'svc_token', 'tenantId' => 'acme']);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringStartsWith('octonomy: base_url is missing or blank.', $e->getMessage());
            self::assertStringEndsWith(
                " The options also hold 'baseUrl', 'tenantId', which this SDK does not read.",
                $e->getMessage(),
            );
            self::assertStringNotContainsString('octonomy.test', $e->getMessage());
            self::assertStringNotContainsString('acme', $e->getMessage());
        }
    }

    /**
     * Built directly, a Config gives the same messages as fromArray(), naming
     * the option rather than the constructor parameter.
     */
    public function testTheConstructorRefusesWithTheSameMessages(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('octonomy: tenant_id is missing or blank.');

        new Config(baseUrl: 'https://octonomy.test', token: 'svc_token', tenantId: '');
    }

    /**
     * A timeout of 30 seconds, the one the SDK's own client gets, is refused
     * too: a timeout that was given is never dropped without a word, whatever
     * its value.
     */
    public function testTheConstructorRefusesATimeoutTogetherWithAnHttpClient(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('octonomy: timeout and http_client are both set, and the timeout of 30 seconds would be ignored');

        new Config(baseUrl: 'https://octonomy.test', token: 'svc_token', tenantId: 'acme', httpClient: self::anHttpClient(), timeout: 30.0);
    }

    /**
     * The client checks the pair it reads from a Config, rather than trusting
     * that the Config's constructor did. The PHP 7.4 line loses readonly, so
     * there a Config built with a timeout can be given a client afterwards;
     * this builds one that never ran its constructor, which reaches the same
     * state on both lines.
     */
    public function testAClientChecksTheTimeoutOfTheConfigItIsGiven(): void
    {
        $valid = new Config(baseUrl: 'https://octonomy.test', token: 't', tenantId: 'acme', timeout: 5.0);
        $class = new \ReflectionClass(Config::class);
        $config = $class->newInstanceWithoutConstructor();
        foreach ($class->getProperties() as $property) {
            $property->setValue($config, $property->getName() === 'httpClient' ? self::anHttpClient() : $property->getValue($valid));
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('octonomy: timeout and http_client are both set, and the timeout of 5 seconds would be ignored');

        new Client($config);
    }

    /**
     * An injected client keeps its own timeout, so it comes without one.
     */
    public function testAnHttpClientWithoutATimeoutIsAccepted(): void
    {
        $http = self::anHttpClient();

        $config = Config::fromArray(['http_client' => $http] + self::VALID);

        self::assertSame($http, $config->httpClient);
        self::assertNull($config->timeout);
    }

    /**
     * Without an http_client the SDK builds a Guzzle client, with the timeout
     * given or 30 seconds.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('timeouts')]
    public function testTheClientTheSdkBuildsHasTheTimeout(array $options, float $expected): void
    {
        self::assertSame($expected, self::timeoutOfTheClientBuiltFor(new Client($options + self::VALID)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, float}>
     */
    public static function timeouts(): iterable
    {
        yield 'none given' => [[], 30.0];
        yield 'a float' => [['timeout' => 2.5], 2.5];
        yield 'an int' => [['timeout' => 5], 5.0];
    }

    /**
     * A PSR-18 client for a Config to hold, with no response to give: nothing
     * here sends a request.
     */
    private static function anHttpClient(): ReplayingClient
    {
        return new ReplayingClient([]);
    }
}
