<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Internal\Json;
use Octoverse\Octonomy\RequestOptions;

/**
 * Transport is the single HTTP entry point for the SDK. It builds requests under
 * the /api/v1 prefix, attaches the auth/tenant headers, sends them through a
 * Guzzle client, decodes JSON, and converts non-2xx responses into typed
 * exceptions.
 *
 * @internal
 */
final class Transport
{
    private readonly ClientInterface $http;
    private readonly string $baseUri;
    private readonly ?string $actorId;

    /** @var array<string, string> */
    private readonly array $defaultHeaders;

    public function __construct(Config $config, string $sdkVersion)
    {
        $this->actorId = $config->actorId;
        $this->baseUri = rtrim($config->baseUrl, '/') . '/api/v1/';
        $this->http = $config->httpClient ?? new GuzzleClient(['timeout' => $config->timeout]);
        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $config->token,
            'X-Tenant-ID' => $config->tenantId,
            'Accept' => 'application/json',
            'User-Agent' => $config->userAgent ?? 'octonomy-php/' . $sdkVersion,
        ];
    }

    /**
     * Perform a request and return the decoded JSON body (an empty array for
     * empty responses such as 204).
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?RequestOptions $options = null,
    ): array {
        $headers = $this->defaultHeaders;
        $actor = $this->actorId;
        if ($options !== null && $options->actorId !== null) {
            $actor = $options->actorId;
        }
        if ($actor !== null && $actor !== '') {
            $headers['X-Actor-ID'] = $actor;
        }

        $requestOptions = [
            'headers' => $headers,
            'http_errors' => false,
        ];
        if ($query !== []) {
            $requestOptions['query'] = $query;
        }
        if ($body !== null) {
            $requestOptions['json'] = $body;
        }

        $url = $this->baseUri . ltrim($path, '/');

        try {
            $response = $this->http->request($method, $url, $requestOptions);
        } catch (GuzzleException $e) {
            throw new TransportException('octonomy: request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($status, Json::tryDecode($raw));
        }

        return Json::decode($raw);
    }
}
