<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Http;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The client the SDK builds when it is given no http_client sends a request's
 * path as written (#64). The request goes out for real, through the SDK's own
 * handler stack, to a php -S server on this machine. The server records the
 * request line and answers 204 with no body, so this test writes no response
 * body.
 *
 * The literal dot segments prove that CURLOPT_PATH_AS_IS reaches libcurl:
 * without it, every libcurl removes them. The SDK never sends one, since
 * Internal\Path encodes them. libcurl 8.13.0 and later also removes the
 * encoded %2E and %2E%2E that the SDK does send, and those cases fail only
 * there.
 */
final class SdkBuiltClientTest extends SdkTestCase
{
    /** @var resource|null the php -S process */
    private $server;

    private string $work = '';
    private string $baseUrl = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir() . '/octonomy-sdk-built-client-' . bin2hex(random_bytes(6));
        mkdir($this->work);
        file_put_contents(
            $this->work . '/router.php',
            "<?php\nfile_put_contents(__DIR__ . '/request', \$_SERVER['REQUEST_METHOD'] . ' ' . \$_SERVER['REQUEST_URI']);\nhttp_response_code(204);\n",
        );
        $port = self::freePort();
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->work . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->work . '/server.log', 'w'], 2 => ['file', $this->work . '/server.log', 'a']],
            $pipes,
        );
        if ($server === false) {
            self::fail('could not start php -S');
        }
        $this->server = $server;
        $this->baseUrl = 'http://127.0.0.1:' . $port;
        self::waitForConnections($port);
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        foreach (['request', 'router.php', 'server.log'] as $file) {
            if (is_file($this->work . '/' . $file)) {
                unlink($this->work . '/' . $file);
            }
        }
        if (is_dir($this->work)) {
            rmdir($this->work);
        }
        parent::tearDown();
    }

    #[DataProvider('dotSegmentPaths')]
    public function testTheClientTheSdkBuildsSendsADotSegmentAsWritten(string $path): void
    {
        $transport = new Transport(new Config($this->baseUrl, 'svc_test', 'acme'), Client::VERSION);

        $transport->none('GET', $path);

        self::assertSame('GET /api/v2/' . $path, $this->received());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dotSegmentPaths(): iterable
    {
        yield 'a literal dot' => ['resources/product/./tags'];
        yield 'a literal dot-dot' => ['resources/product/../tags'];
        yield 'an encoded dot, as Path sends it' => ['resources/product/%2E/tags'];
        yield 'an encoded dot-dot, as Path sends it' => ['resources/product/%2E%2E/tags'];
    }

    /**
     * The request line the server recorded: method and request target.
     */
    private function received(): string
    {
        $request = $this->work . '/request';
        self::assertFileExists($request, 'the server recorded no request: ' . (string) @file_get_contents($this->work . '/server.log'));

        return (string) file_get_contents($request);
    }

    /**
     * A TCP port on 127.0.0.1 that nothing listens on: one the system just
     * handed out and took back.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::fail('could not find a free port: ' . $error);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Wait up to five seconds for php -S to accept connections on $port.
     */
    private static function waitForConnections(int $port): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }
        self::fail('php -S did not start listening on port ' . $port);
    }
}
