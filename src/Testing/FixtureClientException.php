<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Testing;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Thrown by FixtureClient::sendRequest() when it has no captured response for a
 * request, naming the request's method and path; when the request's path reads
 * as two endpoints, naming both; or when the corpus it ships with cannot be
 * read.
 *
 * It is a PSR-18 ClientExceptionInterface, as a client that cannot answer a
 * request at all must throw. Through the SDK it arrives as a
 * TransportException whose message quotes this one's.
 */
final class FixtureClientException extends \RuntimeException implements ClientExceptionInterface {}
