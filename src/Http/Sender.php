<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\RequestContext;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sender is what Transport and HealthTransport share about putting a request
 * on the wire: the client the SDK builds when it is given none, how a request
 * is handed to a client, and how a client's failure is reported. The health
 * probes need a transport of their own (#24), and this keeps the two from
 * sending a request, or reporting a failed one, in two different ways.
 *
 * @internal
 */
final class Sender
{
    /** Seconds, for the client the SDK builds when none is injected. */
    private const DEFAULT_TIMEOUT = 30.0;

    private function __construct() {}

    /**
     * The client to send through: $httpClient when one was given, or a Guzzle
     * client with $timeout, 30 seconds unless set.
     */
    public static function client(?ClientInterface $httpClient, ?float $timeout): ClientInterface
    {
        return $httpClient ?? new GuzzleClient(['timeout' => $timeout ?? self::DEFAULT_TIMEOUT]);
    }

    /**
     * Send $request through $http, as the SDK built it.
     *
     * A Guzzle client goes through send(), not its PSR-18 sendRequest(), since
     * only send() takes the options that keep the client's own defaults off the
     * request. Guzzle's sendRequest() puts its default auth, query and body in
     * place of the SDK's (a Basic Authorization header for a Bearer one), adds
     * its default headers, and does not follow redirects (#18). Through send(),
     * Guzzle merges the client's defaults under these options and then drops a
     * null one, so a null headers, body or json cancels that default, the query,
     * the request's own, replaces a default one, and auth false disables a
     * default one, leaving the SDK's Authorization header. Guzzle's declared
     * option types allow no such cancelling of form_params or multipart, so a
     * client passed to the SDK must not set them (README). Its transport options
     * and its middleware still apply, redirects as it configures them included.
     *
     * Any other client gets sendRequest(), which takes no options: what it does
     * to the request on the way out, defaults and middleware included, is that
     * client's (README). PSR-18 has it return a 4xx or 5xx rather than throw.
     *
     * @param array<string, string> $query the query $request's URI already carries, which
     *                                     Guzzle's query option sets in place of a default one
     */
    public static function send(ClientInterface $http, RequestInterface $request, array $query): ResponseInterface
    {
        if (!$http instanceof GuzzleClientInterface) {
            return $http->sendRequest($request);
        }

        return $http->send($request, [
            'http_errors' => false,
            'headers' => null,
            'query' => $query,
            'body' => null,
            'json' => null,
            'auth' => false,
        ]);
    }

    /**
     * The TransportException for a request the client threw on.
     *
     * Anything the client, a handler or middleware throws, not only a
     * GuzzleException: none may leave with the request's frames unscrubbed.
     * Quoted in the message, not chained as getPrevious(): Guzzle's exception
     * holds the request it failed to send, Authorization header and body
     * included (Redaction). That request, as middleware left it, is redacted
     * too.
     *
     * @param string    $origin    the scheme, host and port of base_url (origin())
     * @param Redaction $redaction the values the request carried, as the SDK handed it to the client
     */
    public static function failure(
        RequestContext $context,
        string $origin,
        \Throwable $e,
        Redaction $redaction,
    ): TransportException {
        $sent = $e instanceof RequestExceptionInterface || $e instanceof NetworkExceptionInterface ? $e->getRequest() : null;

        return new TransportException(
            ErrorMessage::forClientFailure(
                $context,
                $origin,
                $e,
                self::statusOf($e),
                $sent === null ? $redaction : $redaction->andSent($sent),
            ),
            $context,
        );
    }

    /**
     * The scheme, host and port of $baseUrl, such as "https://octonomy.example.com:8443".
     * Any user name or password in it is left out, so a message can name the
     * server it could not reach.
     */
    public static function origin(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            // Config and HealthConfig refuse such a URL. Only the PHP 7.4 line,
            // where both are mutable, can get here; name the setting rather
            // than echo it.
            return 'base_url';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The status of the response a client's exception holds, or null when it
     * holds none. Guzzle 7 puts getResponse() on RequestException, where it
     * may return null, and Guzzle 8 only on ResponseException; any client may
     * offer one. Best effort, since it runs while another failure is being
     * reported: a getResponse() that cannot be called, or that throws, counts
     * as no response rather than replacing that failure with its own.
     */
    private static function statusOf(object $e): ?int
    {
        $getResponse = [$e, 'getResponse'];
        if (!is_callable($getResponse)) {
            return null;
        }
        try {
            $response = $getResponse();
        } catch (\Throwable) {
            return null;
        }

        return $response instanceof ResponseInterface ? $response->getStatusCode() : null;
    }
}
