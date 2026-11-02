<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\Internal\Options;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * HealthConfig is the validated configuration for a HealthClient: where the
 * server is and how to reach it, and nothing else. Build it directly or via
 * HealthConfig::fromArray(). Only baseUrl is required.
 *
 * It holds no token and no tenant id because the health probes take neither:
 * the server serves them unauthenticated, outside /api (#24). Config requires
 * both, so it cannot build a client for a caller who has no credentials, and
 * relaxing it would cost every other resource the guarantee that a Client is
 * tenant-scoped. Each setting here means what it means on Config, and is
 * checked the same way.
 */
final class HealthConfig
{
    /**
     * @param string     $baseUrl the Octonomy server's address, as Config::$baseUrl: the probes are
     *                            sent to {baseUrl}/health/live and {baseUrl}/health/ready
     * @param float|null $timeout seconds, for the client the SDK builds when $httpClient is null
     *
     * @throws ConfigurationException when the base URL is blank or not absolute, the user agent holds a
     *                                control character, or a timeout is given together with an HTTP
     *                                client
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly ?ClientInterface $httpClient = null,
        public readonly ?float $timeout = null,
        public readonly ?string $userAgent = null,
        public readonly ?RequestFactoryInterface $requestFactory = null,
    ) {
        Options::checkBaseUrl($baseUrl);
        Options::checkUserAgent($userAgent);
        Options::checkTimeout($httpClient, $timeout);
    }

    /**
     * Build a HealthConfig from an options array, the form accepted by
     * HealthClient.
     *
     * Recognized keys: base_url (required); http_client, timeout (not with
     * http_client), user_agent and request_factory (optional), read as
     * Config::fromArray() reads them. The other keys a Client takes (token,
     * tenant_id, actor_id, api_version, and stream_factory, since a probe sends
     * no body) are ignored, so a Client's options build a HealthClient too.
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException when an option is refused
     */
    public static function fromArray(array $options): self
    {
        try {
            return new self(
                baseUrl: Options::string($options, 'base_url') ?? '',
                httpClient: Options::httpClient($options),
                timeout: Options::float($options, 'timeout'),
                userAgent: Options::string($options, 'user_agent'),
                requestFactory: Options::requestFactory($options),
            );
        } catch (ConfigurationException $e) {
            throw Options::namingUnread($e, $options);
        }
    }
}
