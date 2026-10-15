<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use Octoverse\Octonomy\Pagination;

/**
 * Envelope is a validated list response, {"data": [...], "pagination": {...}},
 * as returned by Transport::many(). Each row is still a raw JSON object; the
 * calling service hydrates it into a model.
 *
 * @internal
 */
final class Envelope
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly Pagination $pagination,
    ) {}
}
