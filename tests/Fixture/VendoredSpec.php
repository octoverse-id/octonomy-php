<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Fixture;

/**
 * VendoredSpec reads one of the OpenAPI specs vendored under docs/ (#21): the
 * server's own file at a release tag, byte for byte below a comment header that
 * names the tag, the commit and the file's sha256, and lists where the spec and
 * the server disagree.
 */
final class VendoredSpec
{
    /** Each spec, by the api_version it describes. */
    public const FILES = ['v1' => 'docs/openapi.yaml', 'v2' => 'docs/openapi-v2.yaml'];

    private const ROOT = __DIR__ . '/../..';

    private function __construct(
        public readonly string $file,
        public readonly string $header,
        public readonly string $body,
    ) {}

    /**
     * Read the spec for $apiVersion, split at the end of its leading comment
     * block.
     */
    public static function load(string $apiVersion): self
    {
        if (!isset(self::FILES[$apiVersion])) {
            throw new \InvalidArgumentException("no vendored spec for {$apiVersion}");
        }
        $file = self::FILES[$apiVersion];
        $raw = file_get_contents(self::ROOT . "/{$file}");
        if ($raw === false || preg_match('/\A((?:#[^\n]*\n)+)(.*)\z/s', $raw, $m) !== 1) {
            throw new \RuntimeException("{$file} is missing or has no comment header");
        }

        return new self($file, $m[1], $m[2]);
    }

    /**
     * The server release tag the header names, such as "v3.2.1", or null.
     */
    public function tag(): ?string
    {
        return preg_match('/at tag (v\d+\.\d+\.\d+)/', $this->header, $m) === 1 ? $m[1] : null;
    }

    /**
     * The server commit the header names, or null.
     */
    public function commit(): ?string
    {
        return preg_match('/\(commit ([0-9a-f]{40})\)/', $this->header, $m) === 1 ? $m[1] : null;
    }

    /**
     * The sha256 of the server's file that the header records, or null.
     */
    public function recordedSha256(): ?string
    {
        return preg_match('/\b([0-9a-f]{64})\b/', $this->header, $m) === 1 ? $m[1] : null;
    }

    /**
     * The info.version the server's file declares, or null.
     */
    public function infoVersion(): ?string
    {
        return preg_match('/^info:\n(?: [^\n]*\n)*?  version: (\S+)$/m', $this->body, $m) === 1 ? $m[1] : null;
    }
}
