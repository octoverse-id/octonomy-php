<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use GuzzleHttp\ClientInterface;
use Octoverse\Octonomy\Exception\ConfigurationException;

/**
 * Config is the validated configuration for a Client. Build it directly or via
 * Config::fromArray(). baseUrl, token, and tenantId are required.
 *
 * $apiVersion is the REST surface the Client calls: ApiVersion::V2 unless set
 * to ApiVersion::V1, which an Octonomy server older than 2.0.0 needs. The
 * Client reads it once, when it is constructed, and never changes it; there is
 * no per-request override.
 */
final class Config
{
    /**
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws ConfigurationException when a required value is blank, the base URL is not absolute,
     *                                or the api_version is not one of the ApiVersion constants
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $token,
        public readonly string $tenantId,
        public readonly ?string $actorId = null,
        public readonly ?ClientInterface $httpClient = null,
        public readonly float $timeout = 30.0,
        public readonly ?string $userAgent = null,
        public readonly string $apiVersion = ApiVersion::V2,
    ) {
        if (trim($baseUrl) === '') {
            throw new ConfigurationException('octonomy: base_url is required');
        }
        if (trim($token) === '') {
            throw new ConfigurationException('octonomy: token is required');
        }
        if (trim($tenantId) === '') {
            throw new ConfigurationException('octonomy: tenant_id is required');
        }

        $parsed = parse_url($baseUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new ConfigurationException(
                sprintf('octonomy: base_url must be an absolute URL, got "%s"', $baseUrl),
            );
        }

        // Checked explicitly rather than by type: ApiVersion is a set of string
        // constants, not an enum (the PHP 7.4 line cannot carry one, #5), so
        // nothing else stops "v3" or "V2" from reaching the URL.
        if ($apiVersion !== ApiVersion::V1 && $apiVersion !== ApiVersion::V2) {
            throw new ConfigurationException(
                sprintf('octonomy: api_version must be "%s" or "%s", got "%s"', ApiVersion::V1, ApiVersion::V2, $apiVersion),
            );
        }
    }

    /**
     * Build a Config from an options array, the form accepted by Client.
     *
     * Recognized keys: base_url, token, tenant_id (required); actor_id,
     * http_client (GuzzleHttp\ClientInterface), timeout, user_agent, api_version
     * ("v1" or "v2", default "v2") (optional).
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $httpClient = $options['http_client'] ?? null;
        if ($httpClient !== null && !$httpClient instanceof ClientInterface) {
            throw new ConfigurationException('octonomy: http_client must implement GuzzleHttp\\ClientInterface');
        }

        return new self(
            baseUrl: self::stringOption($options, 'base_url') ?? '',
            token: self::stringOption($options, 'token') ?? '',
            tenantId: self::stringOption($options, 'tenant_id') ?? '',
            actorId: self::stringOption($options, 'actor_id'),
            httpClient: $httpClient,
            timeout: self::floatOption($options, 'timeout') ?? 30.0,
            userAgent: self::stringOption($options, 'user_agent'),
            apiVersion: self::stringOption($options, 'api_version') ?? ApiVersion::V2,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ConfigurationException(sprintf('octonomy: %s must be a string', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function floatOption(array $options, string $key): ?float
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new ConfigurationException(sprintf('octonomy: %s must be a number', $key));
        }

        return (float) $value;
    }
}
