<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Fixture;

use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Holds the AGENTS.md rule that response bodies in tests come from captured
 * fixtures.
 *
 * SdkTestCase::clientWith() and transportWith() accept only a CapturedFixture or
 * a SyntheticNegative, so a hand-built response cannot reach the SDK through
 * them. The remaining way round is to build an HTTP stack of your own: a Response
 * under any alias or factory, a MockHandler, a handler callable, a Guzzle client.
 * Every one of those names something in the GuzzleHttp namespace, so outside the
 * three helpers that own the stack, no test may name it at all.
 */
final class NoHandWrittenResponseBodiesTest extends SdkTestCase
{
    /** The files that own the mocked HTTP stack. */
    private const OWNERS = ['SdkTestCase.php', 'Fixture/CapturedFixture.php', 'Fixture/SyntheticNegative.php'];

    public function testOnlyTheFixtureHelpersTouchTheHttpStack(): void
    {
        $root = dirname(__DIR__);
        $offenders = [];
        $scanned = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || in_array($relative, self::OWNERS, true)) {
                continue;
            }
            ++$scanned;
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            // Matches `use GuzzleHttp\...`, `\GuzzleHttp\...` and the string form
            // 'GuzzleHttp\...' a class_exists() or new $name could use.
            if (preg_match_all('/\bGuzzleHttp\\\\{1,2}\w/', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [, $offset]) {
                    $offenders[] = $relative . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }

        self::assertGreaterThan(5, $scanned);
        self::assertSame(
            [],
            $offenders,
            'only the fixture helpers may build responses: replay one with captured(), or mark a shape no server sends with syntheticNegative()',
        );
    }

    public function testClientWithRefusesAnHttpClientOfItsOwn(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('clientWith() owns the HTTP client');

        $this->clientWith([], ['http_client' => null]);
    }

    public function testClientWithRefusesAResponseThatIsNotAFixture(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type (passing the wrong type is the point) */
        $this->clientWith([new \stdClass()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesOutsideTheManifest(): iterable
    {
        yield 'a path out of the corpus' => ['../../handwritten'];
        yield 'an uncaptured operation' => ['v1/tags/no-such-operation'];
        yield 'the manifest itself' => ['manifest'];
    }

    #[DataProvider('namesOutsideTheManifest')]
    public function testOnlyAManifestListedFixtureLoads(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'{$name}' is not a captured fixture");

        self::captured($name);
    }

    public function testASyntheticNegativeMustNameACarveOut(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'success' is not a synthetic-negative kind");

        new SyntheticNegative('success', 200, '{"data":{"id":"tag_1"}}', []);
    }
}
