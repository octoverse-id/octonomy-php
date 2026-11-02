<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\HealthClient;
use Octoverse\Octonomy\HealthConfig;
use Octoverse\Octonomy\Tests\Fixture\ReplayingClient;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers building a HealthClient (#24): only base_url is required, a Client's
 * options build one too, and every connection setting is refused as Config
 * refuses it, in the same words.
 */
final class HealthClientTest extends SdkTestCase
{
    public function testOnlyABaseUrlIsRequired(): void
    {
        $client = new HealthClient(['base_url' => 'https://octonomy.example.com']);

        self::assertSame($client->health(), $client->health());
    }

    /**
     * The keys only a Client reads are ignored, so one options array builds
     * either client.
     */
    public function testAClientsOptionsBuildAHealthClient(): void
    {
        $options = [
            'base_url' => 'https://octonomy.example.com',
            'token' => 'svc_live_x',
            'tenant_id' => 'acme',
            'actor_id' => 'ops-bot',
            'api_version' => 'v1',
            'timeout' => 2,
            'user_agent' => 'deploy-check/1.0',
            'stream_factory' => self::recordingFactory(),
        ];

        new Client($options);
        $config = HealthConfig::fromArray($options);

        self::assertSame('https://octonomy.example.com', $config->baseUrl);
        self::assertSame(2.0, $config->timeout);
        self::assertSame('deploy-check/1.0', $config->userAgent);
    }

    public function testAHealthConfigBuildsAHealthClient(): void
    {
        $factory = self::recordingFactory();
        $config = new HealthConfig(baseUrl: 'https://octonomy.example.com', timeout: 1.5, requestFactory: $factory);

        $client = new HealthClient($config);

        self::assertSame(1.5, self::timeoutOfTheClientBuiltFor($client->health()));
    }

    /**
     * @param array<string, mixed> $options
     * @param non-empty-string     $expected
     */
    #[DataProvider('refusedOptions')]
    public function testAnOptionIsRefusedInConfigsWords(array $options, string $expected): void
    {
        $configMessage = null;
        try {
            Config::fromArray($options + ['token' => 't', 'tenant_id' => 'acme']);
        } catch (ConfigurationException $e) {
            $configMessage = $e->getMessage();
        }

        try {
            new HealthClient($options);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringStartsWith($expected, $e->getMessage());
            self::assertSame($configMessage, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}>
     */
    public static function refusedOptions(): iterable
    {
        yield 'no base_url' => [[], 'octonomy: base_url is missing or blank.'];
        yield 'a base_url without a scheme' => [
            ['base_url' => 'octonomy.example.com'],
            'octonomy: base_url must be an absolute URL, got "octonomy.example.com", which has no scheme.',
        ];
        yield 'a timeout together with an http_client' => [
            ['base_url' => 'https://octonomy.test', 'http_client' => new ReplayingClient([]), 'timeout' => 2],
            'octonomy: timeout and http_client are both set, and the timeout of 2 seconds would be ignored',
        ];
        yield 'a timeout that is not a number' => [
            ['base_url' => 'https://octonomy.test', 'timeout' => '2'],
            'octonomy: timeout must be an int or a float, got string.',
        ];
        yield 'an http_client that is not one' => [
            ['base_url' => 'https://octonomy.test', 'http_client' => new \stdClass()],
            // The interface's name is left out: only ReplayingClient names it.
            'octonomy: http_client must implement Psr\\Http\\',
        ];
        yield 'a request_factory that is not one' => [
            ['base_url' => 'https://octonomy.test', 'request_factory' => 'factory'],
            'octonomy: request_factory must implement Psr\Http\Message\RequestFactoryInterface (PSR-17), got string.',
        ];
        yield 'a user_agent with a null byte' => [
            ['base_url' => 'https://octonomy.test', 'user_agent' => "probe\0"],
            'octonomy: user_agent holds a line break or another control character, which the User-Agent header',
        ];
    }

    /**
     * A misspelt key is named, and a key only a Client reads is not: it is
     * read, just not here.
     */
    public function testARefusalNamesTheKeysNoClientReads(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The options also hold \'baseUrl\', which this SDK does not read.');

        new HealthClient(['baseUrl' => 'https://octonomy.test', 'token' => 't', 'tenant_id' => 'acme']);
    }

    public function testTheConstructorRefusesWithTheSameMessages(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('octonomy: base_url is missing or blank.');

        new HealthConfig(' ');
    }
}
