<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Fixture;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * ReplayingClient is a PSR-18 client that is not Guzzle's, for tests of a client
 * injected through http_client that the SDK can only call sendRequest() on. It
 * answers each request, in order, with a captured fixture or a labelled
 * synthetic negative, or throws what $throw returns for it, and keeps every
 * request it was handed. Build it through SdkTestCase::psr18ClientWith().
 */
final class ReplayingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    /**
     * @param list<CapturedFixture|SyntheticNegative>   $responses
     * @param (\Closure(RequestInterface): \Throwable)|null $throw
     */
    public function __construct(private array $responses, private ?\Closure $throw = null) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        if ($this->throw !== null) {
            throw ($this->throw)($request);
        }
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('ReplayingClient has no response left for ' . $request->getMethod() . ' ' . $request->getUri());
        }

        return $response->response();
    }

    /**
     * The request the SDK handed over last, or null when it handed over none.
     */
    public function lastRequest(): ?RequestInterface
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }
}
