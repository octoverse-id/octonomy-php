<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Fixture;

use PHPUnit\Framework\TestCase;

/**
 * Holds the AGENTS.md rule that response bodies in tests come from captured
 * fixtures. A test builds a response only through SdkTestCase::captured() or,
 * for a shape no conforming server sends, SdkTestCase::syntheticNegative(); a
 * Response constructed anywhere else is a hand-written body.
 */
final class NoHandWrittenResponseBodiesTest extends TestCase
{
    /** The two places allowed to construct a Response. */
    private const BUILDERS = ['SdkTestCase.php', 'Fixture/CapturedFixture.php'];

    public function testOnlyTheFixtureHelpersConstructResponses(): void
    {
        $root = dirname(__DIR__);
        $offenders = [];
        $scanned = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || in_array($relative, self::BUILDERS, true)) {
                continue;
            }
            ++$scanned;
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (preg_match_all('/\bnew\s+\\\\?(?:GuzzleHttp\\\\Psr7\\\\)?Response\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [, $offset]) {
                    $offenders[] = $relative . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }

        self::assertGreaterThan(5, $scanned);
        self::assertSame(
            [],
            $offenders,
            'replay a captured fixture with captured(), or mark a shape no server sends with syntheticNegative()',
        );
    }
}
