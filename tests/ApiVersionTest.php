<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The api_version option (#15): v2 unless set, chosen at construction only,
 * and checked explicitly since ApiVersion is string constants, not an enum.
 */
final class ApiVersionTest extends SdkTestCase
{
    public function testDefaultConstructionTargetsV2(): void
    {
        $client = $this->clientWith([self::captured('v2/tags/get')]);

        $client->tags()->get('tag_1');

        self::assertSame(ApiVersion::V2, $client->apiVersion());
        self::assertSame('/api/v2/tags/tag_1', $this->lastRequest()->getUri()->getPath());
        self::assertSame(ApiVersion::V2, (new Config(baseUrl: 'https://octonomy.test', token: 't', tenantId: 'acme'))->apiVersion);
    }

    public function testV1IsChosenAtConstruction(): void
    {
        $client = $this->clientWith([self::captured('v1/tags/get')], ['api_version' => ApiVersion::V1]);

        $tag = $client->tags()->get('tag_1');

        self::assertSame(ApiVersion::V1, $client->apiVersion());
        self::assertSame('/api/v1/tags/tag_1', $this->lastRequest()->getUri()->getPath());
        // Hydrated as v1, which has no namespace pair.
        self::assertNull($tag->namespaceType);
    }

    public function testAConfigObjectCarriesTheApiVersionToTheClient(): void
    {
        $client = new Client(new Config(baseUrl: 'https://octonomy.test', token: 't', tenantId: 'acme', apiVersion: ApiVersion::V1));

        self::assertSame(ApiVersion::V1, $client->apiVersion());
    }

    #[DataProvider('unknownApiVersions')]
    public function testAnUnknownApiVersionThrowsAtConstruction(string $apiVersion): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf('octonomy: api_version must be "v1" or "v2", got "%s"', $apiVersion));

        new Client(['base_url' => 'https://octonomy.test', 'token' => 't', 'tenant_id' => 'acme', 'api_version' => $apiVersion]);
    }

    #[DataProvider('unknownApiVersions')]
    public function testAConfigRefusesAnUnknownApiVersion(string $apiVersion): void
    {
        $this->expectException(ConfigurationException::class);

        new Config(baseUrl: 'https://octonomy.test', token: 't', tenantId: 'acme', apiVersion: $apiVersion);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownApiVersions(): iterable
    {
        yield 'a version the server does not serve' => ['v3'];
        yield 'upper case' => ['V2'];
        yield 'empty' => [''];
        yield 'padded' => [' v2'];
        yield 'a path' => ['/api/v2'];
        yield 'a bare number' => ['2'];
    }

    public function testANonStringApiVersionThrowsAtConstruction(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('octonomy: api_version must be a string');

        new Client(['base_url' => 'https://octonomy.test', 'token' => 't', 'tenant_id' => 'acme', 'api_version' => 2]);
    }

    public function testTheApiVersionCannotChangeAfterConstruction(): void
    {
        // Nothing on a Client can set it: every public method but the
        // constructor takes no argument.
        foreach ((new \ReflectionClass(Client::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            self::assertSame(0, $method->getNumberOfParameters(), "Client::{$method->getName()}() takes an argument");
        }

        // Nor can a single request: RequestOptions has no api_version.
        $constructor = (new \ReflectionClass(RequestOptions::class))->getConstructor();
        self::assertNotNull($constructor);
        $options = array_map(static fn(\ReflectionParameter $p): string => strtolower($p->getName()), $constructor->getParameters());
        self::assertNotContains('apiversion', $options);

        // Nor can the Config it was built from. PHP 8.2 refuses this write
        // (readonly); the PHP 7.4 line, whose downgrade drops readonly, lets it
        // through. Either way the client keeps the version it read at
        // construction, which is the behavior under test, not readonly.
        $config = new Config(baseUrl: 'https://octonomy.test', token: 't', tenantId: 'acme', apiVersion: ApiVersion::V1);
        $client = new Client($config);
        try {
            (new \ReflectionProperty(Config::class, 'apiVersion'))->setValue($config, ApiVersion::V2);
        } catch (\Error $e) {
            self::assertStringContainsString('readonly', $e->getMessage());
        }

        self::assertSame(ApiVersion::V1, $client->apiVersion());
    }
}
