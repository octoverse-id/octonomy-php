<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * TagCreate is the request body for creating a tag. $name, $slug, and $type are
 * required; the remaining fields are optional and omitted from the payload when
 * null.
 */
final class TagCreate
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $type,
        public readonly ?string $applicationId = null,
        public readonly ?string $description = null,
        public readonly ?string $parentId = null,
        public readonly ?string $vocabularyId = null,
        public readonly ?array $metadata = null,
        public readonly ?bool $isActive = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
        ];
        if ($this->applicationId !== null) {
            $payload['application_id'] = $this->applicationId;
        }
        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }
        if ($this->parentId !== null) {
            $payload['parent_id'] = $this->parentId;
        }
        if ($this->vocabularyId !== null) {
            $payload['vocabulary_id'] = $this->vocabularyId;
        }
        if ($this->metadata !== null) {
            $payload['metadata'] = $this->metadata;
        }
        if ($this->isActive !== null) {
            $payload['is_active'] = $this->isActive;
        }

        return $payload;
    }
}
