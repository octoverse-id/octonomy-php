<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use GuzzleHttp\ClientInterface;
use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\Internal\Redaction;

/**
 * Config is the validated configuration for a Client. Build it directly or via
 * Config::fromArray(). baseUrl, token, and tenantId are required.
 *
 * $apiVersion is the REST surface the Client calls: ApiVersion::V2 unless set
 * to ApiVersion::V1, which an Octonomy server older than 2.0.0 needs. The
 * Client reads it once, when it is constructed, and never changes it; there is
 * no per-request override.
 *
 * No ConfigurationException quotes the token or the actor id, and a token or
 * actor id that an HTTP header cannot carry is refused here: Guzzle would
 * otherwise refuse it on the first request with an error that quotes it.
 */
final class Config
{
    /** The option keys fromArray() reads. */
    private const OPTIONS = ['base_url', 'token', 'tenant_id', 'actor_id', 'http_client', 'timeout', 'user_agent', 'api_version'];

    /**
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws ConfigurationException when a required value is blank, the base URL is not absolute, the
     *                                token or actor id holds a control character, or the api_version is
     *                                not one of the ApiVersion constants
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
            throw new ConfigurationException(
                'octonomy: base_url is missing or blank. It is the address of the Octonomy server, such as'
                . ' "https://octonomy.example.com". Fix: pass it as the \'base_url\' option.',
            );
        }
        if (trim($token) === '') {
            throw new ConfigurationException(
                'octonomy: token is missing or blank. Every request sends it as "Authorization: Bearer <token>".'
                . ' Fix: pass a service token as the \'token\' option. An Octonomy operator issues one on the server'
                . ' with `python manage.py create_service_token --name <client> --tenant <tenant_id> --scope tags:read`,'
                . ' which prints it once.',
            );
        }
        if (!Redaction::isHeaderSafe($token)) {
            throw new ConfigurationException(self::unsendable('token', 'Authorization'));
        }
        if (trim($tenantId) === '') {
            throw new ConfigurationException(
                'octonomy: tenant_id is missing or blank. Every request names its tenant in the X-Tenant-ID header,'
                . ' and the token must be granted that tenant. Fix: pass the tenant\'s id as the \'tenant_id\' option:'
                . ' the --tenant the token was issued for.',
            );
        }
        if ($actorId !== null && !Redaction::isHeaderSafe($actorId)) {
            throw new ConfigurationException(self::unsendable('actor_id', 'X-Actor-ID'));
        }

        $parsed = parse_url($baseUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new ConfigurationException(sprintf(
                'octonomy: base_url must be an absolute URL, got "%s", which %s. Fix: write it out in full, with'
                . ' "https://" or "http://", such as "https://octonomy.example.com".',
                $baseUrl,
                match (true) {
                    $parsed === false => 'cannot be parsed as a URL',
                    !isset($parsed['scheme']) => 'has no scheme',
                    default => 'has no host',
                },
            ));
        }

        ApiVersion::check($apiVersion);
    }

    /**
     * Build a Config from an options array, the form accepted by Client.
     *
     * Recognized keys: base_url, token, tenant_id (required); actor_id,
     * http_client (GuzzleHttp\ClientInterface), timeout, user_agent, api_version
     * ("v1" or "v2", default "v2") (optional). Unlike the other optional keys, an
     * api_version given as null is refused rather than read as absent: it picks
     * the contract every response is read against, so a value that was meant to
     * arrive and did not must not quietly become v2.
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
            $unread = array_diff(array_map('strval', array_keys($options)), self::OPTIONS);
            if ($unread === []) {
                throw $e;
            }
            throw new ConfigurationException(sprintf(
                '%s The options also hold %s, which this SDK does not read.',
                $e->getMessage(),
                implode(', ', array_map(static fn(string $key): string => "'{$key}'", $unread)),
            ), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function build(array $options): self
    {
        $httpClient = $options['http_client'] ?? null;
        if ($httpClient !== null && !$httpClient instanceof ClientInterface) {
            throw new ConfigurationException(sprintf(
                'octonomy: http_client must implement GuzzleHttp\\ClientInterface, got %s. Fix: pass a'
                . ' GuzzleHttp\\Client, configured with any middleware you need, or leave http_client out to use a'
                . ' default one.',
                self::typeOf($httpClient),
            ));
        }
        if (array_key_exists('api_version', $options) && $options['api_version'] === null) {
            throw new ConfigurationException(
                'octonomy: api_version must be "v1" or "v2", got null. Fix: leave api_version out for "v2", the'
                . ' default (Octonomy server 2.0.0 or newer), or pass "v1" for an older server.',
            );
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
            // The type alone: the value may be the token.
            throw new ConfigurationException(sprintf(
                'octonomy: %s must be a string, got %s. Fix: pass %s as a string.',
                $key,
                self::typeOf($value),
                $key,
            ));
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
            throw new ConfigurationException(sprintf(
                'octonomy: %s must be an int or a float, got %s. Fix: pass a number, such as 30 or 2.5.',
                $key,
                self::typeOf($value),
            ));
        }

        return (float) $value;
    }

    /**
     * The message for a token or actor id an HTTP header cannot carry. It
     * describes the value and never quotes it.
     */
    private static function unsendable(string $key, string $header): string
    {
        return sprintf(
            'octonomy: %s holds a line break or another control character, which the %s header cannot carry;'
            . ' the value is not shown. Fix: remove the control characters, such as a trailing newline left by'
            . ' reading the value from a file.',
            $key,
            $header,
        );
    }

    /**
     * The type of $value as a message names it: its class, or its PHP type.
     */
    private static function typeOf(mixed $value): string
    {
        return is_object($value) ? $value::class : gettype($value);
    }
}
