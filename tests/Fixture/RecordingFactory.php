<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Fixture;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * RecordingFactory is a PSR-17 request and stream factory that keeps what it
 * was asked to create and marks each request it builds, for tests that the SDK
 * builds its requests with the factories passed as request_factory and
 * stream_factory. It builds nothing itself: $requestFactory and $streamFactory
 * do. It makes no responses, so it is no way round captured fixtures. Get one
 * from SdkTestCase::recordingFactory().
 */
final class RecordingFactory implements RequestFactoryInterface, StreamFactoryInterface
{
    /** The header every request it builds carries, so a test can tell its requests apart. */
    public const MARK = 'X-Test-Built-By';

    /** @var list<array{string, string}> each createRequest() call's method and URI */
    public array $requests = [];

    /** @var list<string> each createStream() call's content */
    public array $streams = [];

    /**
     * Two parameters, not one intersection type, which the PHP 7.4 downgrade
     * cannot express.
     */
    public function __construct(
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function createRequest(string $method, $uri): RequestInterface
    {
        $this->requests[] = [$method, (string) $uri];

        return $this->requestFactory->createRequest($method, $uri)->withHeader(self::MARK, 'recording-factory');
    }

    public function createStream(string $content = ''): StreamInterface
    {
        $this->streams[] = $content;

        return $this->streamFactory->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new \LogicException('the SDK builds no stream from a file');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        throw new \LogicException('the SDK builds no stream from a resource');
    }
}
