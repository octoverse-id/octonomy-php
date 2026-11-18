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
    /**
     * @readonly
     */
    public int $limit;
    /**
     * @readonly
     */
    public int $offset;
    /**
     * @readonly
     */
    public int $count;
    /**
     * @readonly
     */
    public ?string $next;
    /**
     * @readonly
     */
    public ?string $previous;
    public function __construct(int $limit, int $offset, int $count, ?string $next, ?string $previous)
    {
        $this->limit = $limit;
        $this->offset = $offset;
        $this->count = $count;
        $this->next = $next;
        $this->previous = $previous;
    }

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
            Json::int($data, 'limit'),
            Json::int($data, 'offset'),
            Json::int($data, 'count'),
            Json::nullableString($data, 'next'),
            Json::nullableString($data, 'previous'),
        );
    }
}
