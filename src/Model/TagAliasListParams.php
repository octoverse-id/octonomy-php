<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * TagAliasListParams filters and pages a list of tag aliases, from
 * aliases()->list() or a tag's own from tags()->listAliases(). All fields are
 * optional; a null field is omitted from the query string. $query maps to the
 * server's free-text `q` parameter, matched against name and slug. Without
 * $isActive the server lists active aliases only.
 *
 * $includeGlobal (api_version v2 only) asks a namespaced read to return the
 * global (tenant-shared) rows the caller may see as well as the namespace's own.
 * The server fails closed: a grant without global authority still sees none. A
 * v1 client refuses the request before sending it, because /api/v1 would drop
 * the parameter without a word.
 */
final class TagAliasListParams
{
    public function __construct(
        public readonly ?string $applicationId = null,
        public readonly ?bool $includeShared = null,
        public readonly ?bool $isActive = null,
        public readonly ?string $query = null,
        public readonly ?string $slug = null,
        public readonly ?string $tagId = null,
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
        if ($this->applicationId !== null) {
            $query['application_id'] = $this->applicationId;
        }
        if ($this->includeShared !== null) {
            $query['include_shared'] = $this->includeShared ? 'true' : 'false';
        }
        if ($this->isActive !== null) {
            $query['is_active'] = $this->isActive ? 'true' : 'false';
        }
        if ($this->query !== null) {
            $query['q'] = $this->query;
        }
        if ($this->slug !== null) {
            $query['slug'] = $this->slug;
        }
        if ($this->tagId !== null) {
            $query['tag_id'] = $this->tagId;
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
