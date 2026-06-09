<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * TagListParams filters and pages the tag list. All fields are optional; a null
 * field is omitted from the query string. $query maps to the server's free-text
 * `q` parameter.
 */
final class TagListParams
{
    public function __construct(
        public readonly ?string $applicationId = null,
        public readonly ?bool $includeShared = null,
        public readonly ?bool $isActive = null,
        public readonly ?string $parentId = null,
        public readonly ?string $query = null,
        public readonly ?string $slug = null,
        public readonly ?string $type = null,
        public readonly ?string $vocabularyId = null,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
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

        return $query;
    }
}
