<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * TagListParams filters and pages the tag list. All fields are optional; a null
 * field is omitted from the query string. $query maps to the server's free-text
 * `q` parameter.
 *
 * $includeGlobal (api_version v2 only) asks a namespaced read to return the
 * global (tenant-shared) rows the caller may see as well as the namespace's own.
 * The server fails closed: a grant without global authority still sees none. A
 * v1 client refuses the request before sending it, because /api/v1 would drop
 * the parameter without a word. It is the last parameter so that adding it
 * moved no existing one.
 */
final class TagListParams
{
    /**
     * @readonly
     */
    public ?string $applicationId = null;
    /**
     * @readonly
     */
    public ?bool $includeShared = null;
    /**
     * @readonly
     */
    public ?bool $isActive = null;
    /**
     * @readonly
     */
    public ?string $parentId = null;
    /**
     * @readonly
     */
    public ?string $query = null;
    /**
     * @readonly
     */
    public ?string $slug = null;
    /**
     * @readonly
     */
    public ?string $type = null;
    /**
     * @readonly
     */
    public ?string $vocabularyId = null;
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
    public function __construct(?string $applicationId = null, ?bool $includeShared = null, ?bool $isActive = null, ?string $parentId = null, ?string $query = null, ?string $slug = null, ?string $type = null, ?string $vocabularyId = null, ?int $limit = null, ?int $offset = null, ?bool $includeGlobal = null)
    {
        $this->applicationId = $applicationId;
        $this->includeShared = $includeShared;
        $this->isActive = $isActive;
        $this->parentId = $parentId;
        $this->query = $query;
        $this->slug = $slug;
        $this->type = $type;
        $this->vocabularyId = $vocabularyId;
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
        if ($this->includeShared !== null) {
            $query['include_shared'] = $this->includeShared ? 'true' : 'false';
        }
        if ($this->isActive !== null) {
            $query['is_active'] = $this->isActive ? 'true' : 'false';
        }
        if ($this->parentId !== null) {
            $query['parent_id'] = $this->parentId;
        }
        if ($this->query !== null) {
            $query['q'] = $this->query;
        }
        if ($this->slug !== null) {
            $query['slug'] = $this->slug;
        }
        if ($this->type !== null) {
            $query['type'] = $this->type;
        }
        if ($this->vocabularyId !== null) {
            $query['vocabulary_id'] = $this->vocabularyId;
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
