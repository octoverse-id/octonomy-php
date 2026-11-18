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
     * @var list<T>
     * @readonly
     */
    public array $data;
    /**
     * @readonly
     */
    public Pagination $pagination;
    /**
     * @param list<T> $data
     */
    public function __construct(array $data, Pagination $pagination)
    {
        $this->data = $data;
        $this->pagination = $pagination;
    }
}
