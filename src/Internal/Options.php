<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

use Octoverse\Octonomy\Exception\ConfigurationException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Options holds the checks and option readers Config and HealthConfig share:
 * the settings that say where the server is and how to reach it, which a
 * HealthClient takes too (#24). One place, so the two cannot refuse the same
 * value with different words.
 *
 * @internal
 */
final class Options
{
    /**
     * Every option key the SDK reads: all of them for a Client, and the
     * connection settings among them for a HealthClient, which ignores the
     * rest, so one options array can build either.
     */
    public const KEYS = [
        'base_url', 'token', 'tenant_id', 'actor_id', 'http_client', 'timeout', 'user_agent', 'api_version',
        'request_factory', 'stream_factory',
    ];

    private function __construct() {}

    /**
     * @throws ConfigurationException when the base URL is blank or not an absolute URL
     */
    public static function checkBaseUrl(string $baseUrl): void
    {
        if (trim($baseUrl) === '') {
            throw new ConfigurationException(
                'octonomy: base_url is missing or blank. It is the address of the Octonomy server, such as'
                . ' "https://octonomy.example.com". Fix: pass it as the \'base_url\' option.',
            );
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
    }

    /**
     * @throws ConfigurationException when the user agent holds a character a header cannot carry
     */
    public static function checkUserAgent(?string $userAgent): void
    {
        if ($userAgent !== null && !Redaction::isHeaderSafe($userAgent)) {
            throw new ConfigurationException(ErrorMessage::unsendable('user_agent', 'User-Agent'));
        }
    }

    /**
     * The SDK sets a timeout only on the client it builds. It does not change
     * one it was given, which PSR-18 offers no way to do per request either.
     *
     * @throws ConfigurationException when both an HTTP client and a timeout are given
     */
    public static function checkTimeout(?ClientInterface $httpClient, ?float $timeout): void
    {
        if ($httpClient !== null && $timeout !== null) {
            throw new ConfigurationException(ErrorMessage::timeoutWithHttpClient($timeout));
        }
    }

    /**
     * $e, with the keys of $options the SDK does not read named after its
     * message, since a misspelt key ('baseUrl', 'tenant') is read as a missing
     * one. It names the keys only, never their values.
     *
     * @param array<array-key, mixed> $options
     */
    public static function namingUnread(ConfigurationException $e, array $options): ConfigurationException
    {
        $unread = array_diff(array_map('strval', array_keys($options)), self::KEYS);
        if ($unread === []) {
            return $e;
        }

        return new ConfigurationException(sprintf(
            '%s The options also hold %s, which this SDK does not read.',
            $e->getMessage(),
            implode(', ', array_map(static fn(string $key): string => "'{$key}'", $unread)),
        ), 0, $e);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException when http_client is set but is no PSR-18 client
     */
    public static function httpClient(array $options): ?ClientInterface
    {
        $httpClient = $options['http_client'] ?? null;
        if ($httpClient !== null && !$httpClient instanceof ClientInterface) {
            throw new ConfigurationException(sprintf(
                'octonomy: http_client must implement Psr\\Http\\Client\\ClientInterface (PSR-18), got %s. Fix:'
                . ' pass a PSR-18 client, such as a GuzzleHttp\\Client configured with any middleware you need, or'
                . ' leave http_client out to use a default one.',
                self::typeOf($httpClient),
            ));
        }

        return $httpClient;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException when request_factory is set but is no PSR-17 request factory
     */
    public static function requestFactory(array $options): ?RequestFactoryInterface
    {
        $factory = $options['request_factory'] ?? null;
        if ($factory !== null && !$factory instanceof RequestFactoryInterface) {
            throw self::notAFactory('request_factory', RequestFactoryInterface::class, $factory);
        }

        return $factory;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException when stream_factory is set but is no PSR-17 stream factory
     */
    public static function streamFactory(array $options): ?StreamFactoryInterface
    {
        $factory = $options['stream_factory'] ?? null;
        if ($factory !== null && !$factory instanceof StreamFactoryInterface) {
            throw self::notAFactory('stream_factory', StreamFactoryInterface::class, $factory);
        }

        return $factory;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException when the option is set but is not a string
     */
    public static function string(array $options, string $key): ?string
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
     *
     * @throws ConfigurationException when the option is set but is neither an int nor a float
     */
    public static function float(array $options, string $key): ?float
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

    private static function notAFactory(string $key, string $interface, mixed $value): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            'octonomy: %s must implement %s (PSR-17), got %s. Fix: pass a PSR-17 factory, such as the one your'
            . ' PSR-7 library provides, or leave %s out to use the default one.',
            $key,
            $interface,
            self::typeOf($value),
            $key,
        ));
    }

    /**
     * The type of $value as a message names it: its class, or its PHP type.
     */
    private static function typeOf(mixed $value): string
    {
        return is_object($value) ? $value::class : gettype($value);
    }
}
