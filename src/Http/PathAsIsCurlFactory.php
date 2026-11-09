<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;

/**
 * PathAsIsCurlFactory makes the cURL handles of the Guzzle client the SDK
 * builds (Sender::client()). It has Guzzle's own factory make each one, then
 * sets CURLOPT_PATH_AS_IS on it, so libcurl sends the request's path as
 * written (#64).
 *
 * libcurl removes dot segments from a URL's path (RFC 3986, section 5.2.4),
 * and from 8.13.0 it does the same to percent-encoded ones. Internal\Path sends
 * a "." or ".." resource type or id as %2E or %2E%2E, which would then reach
 * another path: resources/product/%2E%2E/tags goes to resources/tags.
 * CURLOPT_PATH_AS_IS turns that removal off. Guzzle 8 refuses the option in the
 * "curl" request option because it is not on the allow-list of Guzzle's cURL
 * handlers, so a handle factory is the one place left to set it.
 *
 * Guzzle marks EasyHandle @internal. This class reads only the public $handle
 * property, which Guzzle 7.8 through 8.x declare in the same way.
 * SdkBuiltClientTest fails if a Guzzle release stops this option from
 * reaching libcurl.
 *
 * @internal
 */
final class PathAsIsCurlFactory implements CurlFactoryInterface
{
    public function __construct(private readonly CurlFactoryInterface $factory) {}

    /**
     * The handle Guzzle's factory makes for $request, set to send its path as written.
     *
     * @param array<string, mixed> $options the request options, as Guzzle's handler passes them
     */
    public function create(RequestInterface $request, array $options): EasyHandle
    {
        $easy = $this->factory->create($request, $options);
        // Guzzle declares the handle resource|\CurlHandle. On PHP 8 it is a
        // \CurlHandle. On the PHP 7.4 line, cURL handles are resources, and
        // Rector's downgrade (DowngradePhp80ResourceReturnToObjectRector) widens
        // this check to accept one. Anything else is left as Guzzle made it.
        $handle = $easy->handle;
        if ($handle instanceof \CurlHandle) {
            // Set again on every handle: the factory curl_reset()s a handle it
            // keeps for reuse, which clears the option.
            curl_setopt($handle, \CURLOPT_PATH_AS_IS, true);
        }

        return $easy;
    }

    /**
     * Return $easy to Guzzle's factory, which resets or closes it.
     */
    public function release(EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }
}
