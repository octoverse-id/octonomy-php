<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Fixture;

use GuzzleHttp\Psr7\Response;

/**
 * SyntheticNegative is a response no conforming Octonomy sends, built by hand
 * because a real server cannot be made to produce it. It is the one carve-out
 * from captured fixtures (AGENTS.md), and each one names which kind of
 * negative it is, so a hand-written success body cannot pass as one without a
 * visible lie at its call site.
 *
 * The kinds are string constants, not an enum: tests are downgraded to PHP 7.4
 * for the 1.x line, and Rector cannot downgrade an enum.
 */
final class SyntheticNegative
{
    /** A 2xx without its {"data": ...} or {"data", "pagination"} envelope. */
    public const MISSING_ENVELOPE = 'missing envelope';

    /** A declared field absent from a resource. */
    public const MISSING_FIELD = 'missing field';

    /** A member of the wrong JSON type, at any depth. */
    public const WRONG_TYPE = 'wrong type';

    /** A body that does not parse as JSON. */
    public const INVALID_JSON = 'invalid JSON';

    /** A proxy's or gateway's body (HTML, plain text) in place of Octonomy's. */
    public const GATEWAY = 'gateway body';

    /** A body on a response whose contract has none (a 204 endpoint). */
    public const STRAY_BODY = 'stray body';

    /** Feature-flag states the server never combines. */
    public const EXCLUSIVE_FLAGS = 'mutually exclusive flag states';

    /**
     * A field the response's api_version does not declare, such as the
     * namespace pair on v1, which the server strips there.
     */
    public const UNDECLARED_FIELD = 'undeclared field';

    private const KINDS = [
        self::MISSING_ENVELOPE,
        self::MISSING_FIELD,
        self::WRONG_TYPE,
        self::INVALID_JSON,
        self::GATEWAY,
        self::STRAY_BODY,
        self::EXCLUSIVE_FLAGS,
        self::UNDECLARED_FIELD,
    ];

    /**
     * @param array<string, string> $headers
     * @param string|null $capturedFrom the api_version of the captured response this one breaks, or
     *                                  null for one written from nothing
     */
    public function __construct(
        public readonly string $kind,
        private readonly int $status,
        public readonly string $body,
        private readonly array $headers,
        private readonly ?string $capturedFrom = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(
                "'{$kind}' is not a synthetic-negative kind; anything a real server can send must be captured (make fixtures)",
            );
        }
    }

    /**
     * The api_version of the captured response this negative was made from, so
     * it replays only on that surface; null when it was written from nothing.
     */
    public function apiVersion(): ?string
    {
        return $this->capturedFrom;
    }

    public function response(): Response
    {
        return new Response($this->status, $this->headers, $this->body);
    }
}
