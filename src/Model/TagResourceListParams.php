<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * TagResourceListParams filters and pages the resources a tag is assigned to,
 * for tags()->listResources(). All fields are optional; a null field is
 * omitted from the query string. $applicationId keeps the assignments of one
 * application, and scopes the request to it, which a namespaced read needs;
 * $resourceType keeps resources of one type.
 *
 * $includeGlobal (api_version v2 only) asks a namespaced read to return the
 * global (tenant-shared) rows the caller may see as well as the namespace's own.
 * The server fails closed: a grant without global authority still sees none. A
 * v1 client refuses the request before sending it, because /api/v1 would drop
 * the parameter without a word.
 */
final class TagResourceListParams
{
    /**
     * @readonly
     */
    public ?string $applicationId = null;
    /**
     * @readonly
     */
    public ?string $resourceType = null;
    /**
     * @readonly
     */
    public ?int $limit = null;
    /**
     * @readonly
     */
    public ?int $offset = null;
    /**
     * @readonly
     */
    public ?bool $includeGlobal = null;
    /**
     * Every argument is optional and sets the filter of the same name; null
     * leaves it out of the query string.
     */
    public function __construct(?string $applicationId = null, ?string $resourceType = null, ?int $limit = null, ?int $offset = null, ?bool $includeGlobal = null)
    {
        $this->applicationId = $applicationId;
        $this->resourceType = $resourceType;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->includeGlobal = $includeGlobal;
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        if ($this->applicationId !== null) {
            $query['application_id'] = $this->applicationId;
        }
        if ($this->resourceType !== null) {
            $query['resource_type'] = $this->resourceType;
        }
        if ($this->limit !== null) {
            $query['limit'] = (string) $this->limit;
        }
        if ($this->offset !== null) {
            $query['offset'] = (string) $this->offset;
        }
        if ($this->includeGlobal !== null) {
            $query['include_global'] = $this->includeGlobal ? 'true' : 'false';
        }

        return $query;
    }
}
