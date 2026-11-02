<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use GuzzleHttp\Psr7\HttpFactory;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\HealthConfig;
use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Json;
use Octoverse\Octonomy\Internal\Options;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\Model\HealthStatus;
use Octoverse\Octonomy\RequestContext;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HealthTransport sends the health probes, which break Transport's contract
 * three ways (#24), so they get a transport of their own rather than a flag on
 * that one:
 *
 * - They sit at the server's root, outside /api/{version}, and are
 *   unauthenticated (config/urls.py upstream), so a probe carries no
 *   api_version prefix and no Authorization, X-Tenant-ID, X-Actor-ID or
 *   namespace header. Only Accept and User-Agent are sent.
 * - Their body is bare, {"status": "ok"}, with no "data" envelope
 *   (octonomy/core/views.py upstream). Transport::one() would refuse it, and
 *   loosening one() would let every other endpoint hydrate a model from an
 *   unwrapped body again (#6).
 * - /health/ready answers 503 {"status": "unavailable"} when the server cannot
 *   reach its database. That is a readiness report, not a failed call, so it
 *   is read as HealthStatus with $ok false instead of becoming an
 *   ApiException.
 *
 * Only the readiness probe's 503 is read that way, and only when its body is
 * the probe's own {"status": "..."}. /health/live answers 200 whenever the
 * server answers at all, so a 503 there, like a 503 without that body, came
 * from something in front of the server, such as a proxy or a load balancer,
 * and stays an ApiException, as any other non-2xx does. A request that gets no response raises
 * TransportException, as on Transport, through the same Sender.
 *
 * @internal
 */
final class HealthTransport
{
    /** The status /health/ready answers with when the server cannot serve traffic. */
    private const NOT_READY = 503;

    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requests;
    private readonly string $baseUrl;

    /** The scheme, host and port of the base URL, as a message names the server. */
    private readonly string $origin;

    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param Redaction $redaction the values no message about a probe may quote: none for a HealthClient,
     *                             and for Client::health() the client's token and actor id, which a
     *                             probe never sends but an HTTP client or factory given them can echo
     *
     * @throws ConfigurationException when $config carries both an HTTP client and a timeout
     */
    public function __construct(HealthConfig $config, string $sdkVersion, private readonly Redaction $redaction)
    {
        // Checked again, not trusted from HealthConfig: the PHP 7.4 line loses
        // readonly in the downgrade, so a HealthConfig can be given a client
        // after its constructor accepted its timeout.
        Options::checkTimeout($config->httpClient, $config->timeout);
        $this->baseUrl = rtrim($config->baseUrl, '/');
        $this->origin = Sender::origin($config->baseUrl);
        $this->http = Sender::client($config->httpClient, $config->timeout);
        $this->requests = $config->requestFactory ?? new HttpFactory();
        $this->headers = [
            'Accept' => 'application/json',
            'User-Agent' => $config->userAgent ?? 'octonomy-php/' . $sdkVersion,
        ];
    }

    /**
     * Send GET {base_url}/$path and read the probe's answer.
     *
     * @param string $path      the probe's path below the server's root, such as 'health/ready'
     * @param bool   $readiness whether this is the readiness probe, whose 503 {"status": ...} is an
     *                          answer rather than a failure
     *
     * @throws ApiException               when the server answers with a non-2xx status other than the
     *                                    readiness probe's own 503
     * @throws MalformedResponseException when a 2xx body is not a JSON object with a string "status"
     * @throws TransportException         when no response arrives or a 2xx body is not JSON
     */
    public function probe(string $path, bool $readiness): HealthStatus
    {
        $context = new RequestContext(null, 'GET', '/' . ltrim($path, '/'));
        // Checked again here, not trusted from HealthConfig, for the PHP 7.4
        // line's sake, as Transport checks its headers.
        foreach ($this->headers as $name => $value) {
            if (!Redaction::isHeaderSafe($value)) {
                throw Redaction::scrub(new \InvalidArgumentException(ErrorMessage::unsendable("the {$name} value", $name)));
            }
        }
        // A probe sends no token, no actor id and no body, but the client that
        // built this transport may hold them; Sender::failure() also adds what
        // a client's middleware put on the request.
        $redaction = $this->redaction;

        try {
            $request = $this->requests->createRequest($context->method, $this->baseUrl . $context->path);
            foreach ($this->headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            $response = Sender::send($this->http, $request, []);
        } catch (\Throwable $e) {
            throw Sender::failure($context, $this->origin, $e, $redaction);
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return $this->read($context, $response, $redaction);
        }
        $raw = (string) $response->getBody();
        if ($readiness && $status === self::NOT_READY) {
            $answer = self::answer($raw);
            if ($answer !== null) {
                return HealthStatus::fromArray($answer, false);
            }
        }

        throw ApiException::fromResponse(
            $status,
            Json::tryDecode($raw),
            $context,
            $response->getHeaderLine('Content-Type'),
            $redaction,
        );
    }

    /**
     * Read a 2xx probe body strictly, as Transport reads a resource: it must be
     * a JSON object whose "status" is a string. A 2xx with no word in it is no
     * evidence of health, so it raises rather than reads as a pass.
     *
     * @throws MalformedResponseException when the body is not a JSON object with a string "status"
     * @throws TransportException         when the body is not JSON
     */
    private function read(RequestContext $context, ResponseInterface $response, Redaction $redaction): HealthStatus
    {
        $raw = (string) $response->getBody();
        try {
            $decoded = Json::decode($raw);
        } catch (\JsonException $e) {
            // Quoted, not chained, as Transport does: json_decode()'s frame
            // holds the raw body.
            throw new TransportException(
                ErrorMessage::forUndecodable(
                    $context,
                    $response->getStatusCode(),
                    $response->getHeaderLine('Content-Type'),
                    strlen($raw),
                    $e->getMessage(),
                    $redaction,
                ),
                $context,
            );
        }
        if (!$decoded instanceof \stdClass) {
            throw new MalformedResponseException('expected a JSON object with a "status" member', null, $context);
        }

        try {
            return HealthStatus::fromArray(Json::members($decoded), true);
        } catch (MalformedResponseException $e) {
            throw $e->locate($context, null, $redaction);
        }
    }

    /**
     * The members of a non-2xx body that is a probe's own answer, a JSON
     * object whose "status" is a string, or null for any other body.
     *
     * @return array<string, mixed>|null
     */
    private static function answer(string $raw): ?array
    {
        try {
            $decoded = Json::decode($raw);
        } catch (\JsonException) {
            return null;
        }
        if (!$decoded instanceof \stdClass || !is_string($decoded->status ?? null)) {
            return null;
        }

        return Json::members($decoded);
    }
}
