<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds the vendored OpenAPI specs to what they claim to be (#21): each one is
 * the server's own file at a release tag, byte for byte below a comment header,
 * both come from the same tag, docs/versioning.md names it, and each header
 * records where the spec and the server disagree.
 *
 * The specs are a reference, never the source of truth: nothing in the SDK
 * reads them, and the last test keeps it that way.
 */
final class VendoredSpecTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function specs(): iterable
    {
        yield 'v1' => ['docs/openapi.yaml', 'v1'];
        yield 'v2' => ['docs/openapi-v2.yaml', 'v2'];
    }

    #[DataProvider('specs')]
    public function testTheSpecIsTheServerFileAtTheTagItNames(string $file, string $apiVersion): void
    {
        [$header, $body] = self::split($file);

        self::assertStringContainsString("for api_version {$apiVersion} (/api/{$apiVersion})", $header);
        if (preg_match('/\b([0-9a-f]{64})\b/', $header, $sha) !== 1) {
            self::fail("{$file} records no sha256");
        }
        self::assertSame($sha[1], hash('sha256', $body), "{$file} was edited below its header: re-vendor it from the server tag instead");
        self::assertSame(ltrim(self::tag($file), 'v'), self::infoVersion($body), "{$file}'s info.version is not the tag its header names");

        preg_match_all('/^  (\/\S*):$/m', $body, $paths);
        self::assertNotSame([], $paths[1]);
        foreach ($paths[1] as $path) {
            self::assertMatchesRegularExpression("#^/(api/{$apiVersion}|health)/#", $path, "{$file} declares a path of another surface");
        }
    }

    public function testBothSpecsComeFromOneServerTagThatVersioningNames(): void
    {
        $tag = self::tag('docs/openapi.yaml');

        self::assertSame($tag, self::tag('docs/openapi-v2.yaml'), 'the two specs must be vendored from the same server release tag');
        self::assertStringContainsString("`{$tag}`", self::read('docs/versioning.md'), 'docs/versioning.md must name the tag the specs track');
    }

    #[DataProvider('specs')]
    public function testTheSpecCarriesTheKnownDivergenceNote(string $file): void
    {
        [$header] = self::split($file);

        foreach ([
            'KNOWN DIVERGENCES',
            'Neither response envelope is modelled',
            "Assignment's required list omits namespace_type and namespace_id",
            'POST /tag-assignments/bulk-assign is declared as an array',
            'POST /resources/{resource_type}/{resource_id}/tags',
        ] as $item) {
            self::assertStringContainsString($item, $header, "{$file}'s divergence note lost an item");
        }
    }

    /**
     * Required-ness comes from the models' constructors and the captured
     * fixtures (#14), never from a spec, which gets the envelopes and several
     * fields wrong. So no PHP the package ships may even name one. (Tooling
     * may: the PHP 7.4 downgrade copies them, which reads nothing.)
     */
    public function testNoSdkCodeReadsTheSpecs(): void
    {
        $offenders = [];
        foreach (['src', 'examples'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . "/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $path) {
                \assert($path instanceof \SplFileInfo);
                $source = file_get_contents($path->getPathname());
                if ($source !== false && preg_match('/openapi(-v2)?\.ya?ml/i', $source) === 1) {
                    $offenders[] = "{$dir}/" . $path->getFilename();
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * The leading comment block and the server's file after it.
     *
     * @return array{string, string}
     */
    private static function split(string $file): array
    {
        $raw = self::read($file);
        if (preg_match('/\A((?:#[^\n]*\n)+)(.*)\z/s', $raw, $m) !== 1) {
            self::fail("{$file} has no comment header");
        }

        return [$m[1], $m[2]];
    }

    private static function tag(string $file): string
    {
        [$header] = self::split($file);
        if (preg_match('/at tag (v\d+\.\d+\.\d+)/', $header, $m) !== 1) {
            self::fail("{$file}'s header names no server tag");
        }

        return $m[1];
    }

    private static function infoVersion(string $body): string
    {
        if (preg_match('/^info:\n(?: [^\n]*\n)*?  version: (\S+)$/m', $body, $m) !== 1) {
            self::fail('no info.version');
        }

        return $m[1];
    }

    private static function read(string $file): string
    {
        $raw = file_get_contents(self::ROOT . "/{$file}");
        self::assertIsString($raw);

        return $raw;
    }
}
