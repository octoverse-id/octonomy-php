<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Fixture;

use GuzzleHttp\Psr7\Response;

/**
 * CapturedFixture is one request/response exchange recorded from a real Octonomy
 * by scripts/capture-fixtures.php, read from tests/fixtures/captured.
 *
 * Unit tests replay these instead of writing response bodies by hand: a body
 * written from the spec agrees with whoever wrote it, not with the server, and
 * the suite stayed green through #6 on exactly that. The request half is
 * provenance only; tests assert the request side by hand.
 */
final class CapturedFixture
{
    public const ROOT = __DIR__ . '/../fixtures/captured';

    /** @var array<string, self> */
    private static array $loaded = [];

    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /**
     * @param array<string, mixed> $request
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly string $name,
        public readonly array $request,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /**
     * Load a fixture by name, such as 'v1/tags/get'.
     *
     * Only a name the manifest lists loads. Anything else, a relative path out
     * of the corpus included, would be a hand-written body that neither the hash
     * check nor the inventory check ever sees.
     */
    public static function load(string $name): self
    {
        if (isset(self::$loaded[$name])) {
            return self::$loaded[$name];
        }
        if (!in_array($name, self::names(), true)) {
            throw new \InvalidArgumentException(
                "'{$name}' is not a captured fixture: tests/fixtures/captured/manifest.json does not list it (make fixtures)",
            );
        }

        $path = self::ROOT . "/{$name}.json";
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("no captured fixture {$name} at {$path}; capture it with `make fixtures`");
        }
        // Objects stay \stdClass until the body is re-encoded, so {} and [] keep
        // the shapes the server sent.
        $file = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!$file instanceof \stdClass || !$file->request instanceof \stdClass || !$file->response instanceof \stdClass) {
            throw new \RuntimeException("captured fixture {$name} is not a {request, response} object");
        }
        $response = $file->response;

        /** @var array<string, mixed> $request */
        $request = json_decode(json_encode($file->request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $headers = [];
        foreach (get_object_vars($response->headers) as $header => $value) {
            $headers[(string) $header] = (string) $value;
        }
        $body = $response->body === null ? '' : json_encode(
            $response->body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return self::$loaded[$name] = new self($name, $request, (int) $response->status, $headers, $body);
    }

    /**
     * The manifest scripts/capture-fixtures.php writes beside the corpus.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            $raw = file_get_contents(self::ROOT . '/manifest.json');
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($raw === false ? '' : $raw, true, 512, JSON_THROW_ON_ERROR);
            self::$manifest = $manifest;
        }

        return self::$manifest;
    }

    /**
     * Every fixture the manifest lists, by name ('v1/tags/get'), optionally for
     * one api_version.
     *
     * @return list<string>
     */
    public static function names(?string $apiVersion = null): array
    {
        /** @var array<string, string> $fixtures */
        $fixtures = self::manifest()['fixtures'];
        $names = [];
        foreach (array_keys($fixtures) as $file) {
            if ($apiVersion === null || str_starts_with($file, "{$apiVersion}/")) {
                $names[] = substr($file, 0, -strlen('.json'));
            }
        }

        return $names;
    }

    /**
     * The captured response, ready to queue on a MockHandler.
     */
    public function response(): Response
    {
        return new Response($this->status, $this->headers, $this->body);
    }

    /**
     * The response body decoded to arrays, or [] for an empty body.
     *
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The body's "data" member: the resource of a single-resource response, the
     * rows of a list.
     *
     * @return array<array-key, mixed>
     */
    public function data(): array
    {
        $data = $this->json()['data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException("captured fixture {$this->name} has no \"data\" member");
        }

        return $data;
    }
}
