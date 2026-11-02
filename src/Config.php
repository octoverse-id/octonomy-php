<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Options;
use Octoverse\Octonomy\Internal\Redaction;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Config is the validated configuration for a Client. Build it directly or via
 * Config::fromArray(). baseUrl, token, and tenantId are required.
 *
 * $apiVersion is the REST surface the Client calls: ApiVersion::V2 unless set
 * to ApiVersion::V1, which an Octonomy server older than 2.0.0 needs. The
 * Client reads it once, when it is constructed, and never changes it; there is
 * no per-request override.
 *
 * $httpClient is any PSR-18 client; without one the SDK builds a Guzzle client
 * with a $timeout of 30 seconds unless set. A timeout given together with
 * $httpClient is refused: the SDK sets the timeout of only the client it builds,
 * so it would be ignored. $requestFactory and $streamFactory are the PSR-17
 * factories the requests are built with, Guzzle's by default.
 *
 * No ConfigurationException quotes the token or the actor id. A token, tenant
 * id, actor id or user agent that an HTTP header cannot carry is refused here,
 * and again by Transport before sending, since the PHP 7.4 line, which has no
 * readonly, lets a checked value be changed afterwards. Guzzle would otherwise
 * refuse it, with an error that quotes it, thrown from frames that hold the
 * token and the body.
 *
 * The health probes need none of the credentials, so a HealthClient takes a
 * HealthConfig instead, which holds the connection settings alone and checks
 * them as this class does.
 */
final class Config
{
    /**
     * @param float|null $timeout    seconds, for the client the SDK builds when $httpClient is null
     * @param string     $apiVersion an ApiVersion constant
     *
     * @throws ConfigurationException when a required value is blank, the base URL is not absolute, a
     *                                value sent as a header holds a control character, the
     *                                api_version is not one of the ApiVersion constants, or a
     *                                timeout is given together with an HTTP client
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $token,
        public readonly string $tenantId,
        public readonly ?string $actorId = null,
        public readonly ?ClientInterface $httpClient = null,
        public readonly ?float $timeout = null,
        public readonly ?string $userAgent = null,
        public readonly string $apiVersion = ApiVersion::V2,
        public readonly ?RequestFactoryInterface $requestFactory = null,
        public readonly ?StreamFactoryInterface $streamFactory = null,
    ) {
        Options::checkBaseUrl($baseUrl);
        if (trim($token) === '') {
            throw new ConfigurationException(
                'octonomy: token is missing or blank. Every request sends it as "Authorization: Bearer <token>".'
                . ' Fix: pass a service token as the \'token\' option. An Octonomy operator issues one on the server'
                . ' with `python manage.py create_service_token --name <client> --tenant <tenant_id> --scope tags:read`,'
                . ' which prints it once.',
            );
        }
        if (!Redaction::isHeaderSafe($token)) {
            throw new ConfigurationException(ErrorMessage::unsendable('token', 'Authorization'));
        }
        if (trim($tenantId) === '') {
            throw new ConfigurationException(
                'octonomy: tenant_id is missing or blank. Every request names its tenant in the X-Tenant-ID header,'
                . ' and the token must be granted that tenant. Fix: pass the tenant\'s id as the \'tenant_id\' option:'
                . ' the --tenant the token was issued for.',
            );
        }
        if (!Redaction::isHeaderSafe($tenantId)) {
            throw new ConfigurationException(ErrorMessage::unsendable('tenant_id', 'X-Tenant-ID'));
        }
        if ($actorId !== null && !Redaction::isHeaderSafe($actorId)) {
            throw new ConfigurationException(ErrorMessage::unsendable('actor_id', 'X-Actor-ID'));
        }
        Options::checkUserAgent($userAgent);
        ApiVersion::check($apiVersion);
        Options::checkTimeout($httpClient, $timeout);
    }

    /**
     * Build a Config from an options array, the form accepted by Client.
     *
     * Recognized keys: base_url, token, tenant_id (required); actor_id,
     * http_client (a PSR-18 Psr\Http\Client\ClientInterface), timeout (not with
     * http_client), user_agent, api_version ("v1" or "v2", default "v2"),
     * request_factory and stream_factory (PSR-17) (optional). Unlike the other
     * optional keys, an api_version given as null is refused rather than read as
     * absent: it picks the contract every response is read against, so a value
     * that was meant to arrive and did not must not quietly become v2.
     *
     * When an option is refused and $options holds keys this method does not
     * read, the message names them, since a misspelt key ('baseUrl', 'tenant')
     * is read as a missing one. It names the keys only, never their values.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        try {
            return self::build($options);
        } catch (ConfigurationException $e) {
            throw Options::namingUnread($e, $options);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function build(array $options): self
    {
        $httpClient = Options::httpClient($options);
        $requestFactory = Options::requestFactory($options);
        $streamFactory = Options::streamFactory($options);
        if (array_key_exists('api_version', $options) && $options['api_version'] === null) {
            throw new ConfigurationException(
                'octonomy: api_version must be "v1" or "v2", got null. Fix: leave api_version out for "v2", the'
                . ' default (Octonomy server 2.0.0 or newer), or pass "v1" for an older server.',
            );
        }

        return new self(
            baseUrl: Options::string($options, 'base_url') ?? '',
            token: Options::string($options, 'token') ?? '',
            tenantId: Options::string($options, 'tenant_id') ?? '',
            actorId: Options::string($options, 'actor_id'),
            httpClient: $httpClient,
            timeout: Options::float($options, 'timeout'),
            userAgent: Options::string($options, 'user_agent'),
            apiVersion: Options::string($options, 'api_version') ?? ApiVersion::V2,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
        );
    }
}
