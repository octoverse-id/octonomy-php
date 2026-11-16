<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Holds docs/guide.md to what it is for (#27): the one path through the SDK,
 * which links each runnable example and leads to the reference for the rest.
 *
 * The guide is mostly links, so a link is how it breaks: an example added
 * without a mention, a file moved, or a README heading reworded under an
 * anchor that points at it. Its code is not checked here; the examples it links
 * are the code, and CI runs them.
 */
final class GuideTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testItLinksEveryExample(): void
    {
        $guide = self::read('docs/guide.md');
        $missing = [];
        foreach (glob(self::ROOT . '/examples/*.php') ?: [] as $example) {
            $name = basename($example);
            if (!str_contains($guide, "](../examples/{$name})")) {
                $missing[] = $name;
            }
        }

        self::assertNotSame([], glob(self::ROOT . '/examples/*.php') ?: [], 'no examples found');
        self::assertSame([], $missing, 'examples the guide does not link');
    }

    public function testEveryRelativeLinkReachesAFileAndHeading(): void
    {
        $broken = [];
        preg_match_all('/\]\(([^)\s]+)\)/', self::read('docs/guide.md'), $matches);
        foreach ($matches[1] as $target) {
            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
                continue; // an absolute URL
            }
            $hash = strpos($target, '#');
            $path = $hash === false ? $target : substr($target, 0, $hash);
            $anchor = $hash === false ? null : substr($target, $hash + 1);
            $file = $path === '' ? 'docs/guide.md' : self::normalize('docs/' . $path);
            if (!is_file(self::ROOT . '/' . $file)) {
                $broken[] = "{$target}: no file {$file}";
                continue;
            }
            if ($anchor !== null && !in_array($anchor, self::anchors($file), true)) {
                $broken[] = "{$target}: no heading in {$file} has the anchor #{$anchor}";
            }
        }

        self::assertNotSame([], $matches[1], 'the guide has no links');
        self::assertSame([], $broken);
    }

    public function testTheReadmeLeadsToIt(): void
    {
        self::assertStringContainsString('](docs/guide.md)', self::read('README.md'));
    }

    /**
     * The anchors GitHub gives the headings of a Markdown file: lowercased,
     * with everything but letters, digits, spaces, hyphens and underscores
     * dropped, and each space a hyphen. Headings inside code blocks are not
     * headings.
     *
     * @return list<string>
     */
    private static function anchors(string $file): array
    {
        $text = preg_replace('/^```.*?^```/ms', '', self::read($file)) ?? '';
        preg_match_all('/^#{1,6}\s+(.+?)\s*$/m', $text, $headings);

        return array_map(
            static fn(string $heading): string => str_replace(' ', '-', preg_replace('/[^\p{L}\p{N} _-]/u', '', strtolower($heading)) ?? ''),
            $headings[1],
        );
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private static function read(string $file): string
    {
        $text = file_get_contents(self::ROOT . '/' . $file);
        self::assertIsString($text, "cannot read {$file}");

        return $text;
    }
}
