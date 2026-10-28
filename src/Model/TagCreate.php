<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * TagCreate is the request body for creating a tag. $name, $slug, and $type are
 * required; the remaining fields are optional and omitted from the payload when
 * null.
 *
 * $metadata is always sent as a JSON object, the only kind the server accepts:
 * the array's keys are its members, so [] is sent as {}, and a tag's metadata
 * goes back out with every object and list it was read with (#48). Inside it a
 * \stdClass is sent as an object and an array as json_encode() writes it, so an
 * empty array is a JSON list: use new \stdClass() for an empty nested object.
 */
final class TagCreate
{
    /**
     * @param array<array-key, mixed>|null $metadata
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
            // Always a JSON object, the only kind the server accepts. An array
            // encodes as a list when it is empty (#46) or keyed 0, 1, ..., and
            // a model holds stored {"0": ...} metadata keyed that way (#48).
            $payload['metadata'] = (object) $this->metadata;
        }
        if ($this->isActive !== null) {
            $payload['is_active'] = $this->isActive;
        }

        return $payload;
    }
}
