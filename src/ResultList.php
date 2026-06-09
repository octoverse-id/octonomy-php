<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

/**
 * ResultList is the generic envelope every Octonomy list endpoint returns:
 * {"data": [...], "pagination": {...}}.
 *
 * @template T
 */
final class ResultList
{
    /**
     * @param list<T> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly Pagination $pagination,
    ) {}
}
