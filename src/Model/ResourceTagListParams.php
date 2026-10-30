<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * ResourceTagListParams filters and pages the tags on a resource, for
 * resources()->listTags(), which takes the application itself. All fields are
 * optional; a null field is omitted from the query string. Without
 * $includeInactive the server lists the resource's active tags only; $type
 * keeps tags of one type.
 *
 * $includeGlobal (api_version v2 only) asks a namespaced read to return the
 * global (tenant-shared) rows the caller may see as well as the namespace's own.
 * The server fails closed: a grant without global authority still sees none. A
 * v1 client refuses the request before sending it, because /api/v1 would drop
 * the parameter without a word.
 */
final class ResourceTagListParams
{
    /**
     * Every argument is optional and sets the filter of the same name; null
     * leaves it out of the query string.
     */
    public function __construct(
        public readonly ?bool $includeInactive = null,
        public readonly ?string $type = null,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
        public readonly ?bool $includeGlobal = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        if ($this->includeInactive !== null) {
            $query['include_inactive'] = $this->includeInactive ? 'true' : 'false';
        }
        if ($this->type !== null) {
            $query['type'] = $this->type;
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
