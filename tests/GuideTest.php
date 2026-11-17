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

    /**
     * Code belongs only where no example can show it: in a caller's tests and
     * in framework configuration. Anywhere else a snippet duplicates an example
     * that CI runs, and drifts from it unseen. Shell commands may go anywhere.
     *
     * The guide is read line by line, and errs toward finding code or a
     * heading, so that a misreading fails the test rather than passing it. Each
     * line is read past any list markers, blockquote markers (with or without a
     * space after) and indentation that contain it. A fence opens with three or
     * more backticks or tildes and closes with a run of the same character at
     * least as long, whatever language it names, in any case; an untagged one
     * counts as code. Outside a fence, a line indented four spaces or a tab
     * past its blockquote markers may be an indented code block, so it counts
     * as code too; any `##` line is a level-two heading, as is a line
     * underlined with dashes.
     */
    public function testItShowsCodeOnlyWhereNoExampleCan(): void
    {
        $allowed = ['12. Testing your code', '13. In a framework'];
        $containers = '/^(?:\s*(?:>|(?:[-*+]|\d{1,9}[.)])(?=\s|$)))*\s*/';
        $section = '';
        $fence = null;
        $previous = '';
        $offending = [];
        $withCode = [];
        foreach (explode("\n", self::read('docs/guide.md')) as $raw) {
            $line = preg_replace($containers, '', $raw) ?? $raw;
            if ($fence !== null) {
                if (preg_match('/^' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $line) === 1) {
                    $fence = null;
                }
                continue;
            }
            // Fences and headings first: an indented `##` line is read as a
            // heading, so code after it is judged by that heading, never by
            // the allowed section above it.
            if (preg_match('/^(`{3,}|~{3,})\s*([^\s`{]*)/', $line, $open) === 1) {
                $fence = $open[1];
                $language = strtolower($open[2]);
                if (!in_array($language, ['bash', 'sh', 'shell'], true)) {
                    $withCode[] = $section;
                    if (!in_array($section, $allowed, true)) {
                        $offending[] = "a '{$language}' block under '{$section}'";
                    }
                }
            } elseif (preg_match('/^##(?!#)\s+(.*?)(?:\s+#+)?\s*$/', $line, $heading) === 1) {
                $section = $heading[1];
            } elseif (preg_match('/^-+\s*$/', $line) === 1 && trim($previous) !== '') {
                $section = trim($previous); // a setext heading: the line above, underlined
            } elseif (preg_match('/^(?:\s*>)*(?: {4}|\t)\s*\S/', $raw) === 1) {
                $withCode[] = $section;
                if (!in_array($section, $allowed, true)) {
                    $offending[] = "an indented line under '{$section}': " . trim($raw);
                }
            }
            $previous = $line;
        }

        self::assertSame([], $offending);
        // Read nothing, and the check above would pass on any guide.
        self::assertSame($allowed, array_values(array_unique($withCode)), 'the sections code belongs in hold none');
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
