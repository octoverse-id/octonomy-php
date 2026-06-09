<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * VocabularyUpdate is the PATCH body for updating a vocabulary. Only non-null
 * fields are sent, so the server updates exactly what you set.
 */
final class VocabularyUpdate
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $slug = null,
        public readonly ?string $applicationId = null,
        public readonly ?string $description = null,
        public readonly ?array $metadata = null,
        public readonly ?bool $isActive = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];
        if ($this->name !== null) {
            $payload['name'] = $this->name;
        }
        if ($this->slug !== null) {
            $payload['slug'] = $this->slug;
        }
        if ($this->applicationId !== null) {
            $payload['application_id'] = $this->applicationId;
        }
        if ($this->description !== null) {
            $payload['description'] = $this->description;
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
