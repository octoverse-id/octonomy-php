<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * Pagination is the metadata block returned alongside every list response.
 * $next and $previous are absolute URLs for the adjacent pages, or null at an edge.
 */
final class Pagination
{
    public function __construct(
        public readonly int $limit,
        public readonly int $offset,
        public readonly int $count,
        public readonly ?string $next,
        public readonly ?string $previous,
    ) {}

    /**
     * Hydrate the "pagination" object of a list response. Every field must be
     * present: $next and $previous are null at an edge, never absent.
     *
     * @param array<string, mixed> $data
     *
     * @throws MalformedResponseException when a field is absent or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        return new self(
            limit: Json::int($data, 'limit'),
            offset: Json::int($data, 'offset'),
            count: Json::int($data, 'count'),
            next: Json::nullableString($data, 'next'),
            previous: Json::nullableString($data, 'previous'),
        );
    }
}
