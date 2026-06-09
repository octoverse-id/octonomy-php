<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * VocabularyListParams filters and pages the vocabulary list. All fields are
 * optional; a null field is omitted from the query string.
 */
final class VocabularyListParams
{
    public function __construct(
        public readonly ?string $applicationId = null,
        public readonly ?bool $includeShared = null,
        public readonly ?bool $isActive = null,
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
        if ($this->limit !== null) {
            $query['limit'] = (string) $this->limit;
        }
        if ($this->offset !== null) {
            $query['offset'] = (string) $this->offset;
        }

        return $query;
    }
}
