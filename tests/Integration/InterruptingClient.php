<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * InterruptingClient is a PSR-18 client for the integration suite. It sends
 * every request it is handed to the real harness server, except request
 * number $failOn (counting from 1), which never leaves: it fails as a
 * connection the network dropped does. It keeps every request it was handed.
 *
 * It is how a walk against the real server is interrupted part of the way
 * through without writing anything: the suite only reads, so it cannot make
 * the server itself fail one page. Every response it returns is one the server
 * sent, so it cannot carry a hand-written body (AGENTS.md).
 */
final class InterruptingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    private readonly GuzzleClient $server;

    /**
     * @param int|null $failOn the number of the request to fail, counting from 1; null to fail none
     */
    public function __construct(private readonly ?int $failOn = null)
    {
        $this->server = new GuzzleClient();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        if (count($this->sent) === $this->failOn) {
            throw new ConnectException('Connection reset by peer', $request);
        }

        return $this->server->sendRequest($request);
    }

    /**
     * Every request the SDK handed over, in order, the failed one included.
     *
     * @return list<RequestInterface>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
