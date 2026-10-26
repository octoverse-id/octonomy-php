<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Undefined;

/**
 * TagUpdate is the PATCH body for updating a tag. The server leaves alone every
 * field the body does not name, so this sends only the fields you set.
 *
 * Each string field has three states. Its default, Undefined::VALUE, omits the
 * key; null sends an explicit null; a string sends that string:
 *
 *   new TagUpdate(name: 'Autumn')     // {"name": "Autumn"}, nothing else changes
 *   new TagUpdate(parentId: null)     // {"parent_id": null}, un-nests the tag
 *
 * The server decides what a null does, and the SDK sends it either way.
 * description, parentId and vocabularyId accept it and clear the field. On
 * applicationId it is a change of application unless the tag is already
 * tenant-shared, and the server refuses such a change with 409
 * scope_immutable (ConflictException). name, slug and type cannot be null, so it
 * is a 400 (ValidationException).
 *
 * metadata and isActive keep two states, because the server has no null for
 * either and Undefined::VALUE, a string, cannot default an array or a bool: null
 * omits the key. metadata replaces the stored object rather than merging into
 * it.
 */
final class TagUpdate
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly ?string $name = Undefined::VALUE,
        public readonly ?string $slug = Undefined::VALUE,
        public readonly ?string $type = Undefined::VALUE,
        public readonly ?string $applicationId = Undefined::VALUE,
        public readonly ?string $description = Undefined::VALUE,
        public readonly ?string $parentId = Undefined::VALUE,
        public readonly ?string $vocabularyId = Undefined::VALUE,
        public readonly ?array $metadata = null,
        public readonly ?bool $isActive = null,
    ) {}

    /**
     * The JSON body: every string field that is not Undefined::VALUE, null
     * included, and metadata and isActive when not null.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // !== against the constant, never instanceof: see Undefined.
        $payload = [];
        if ($this->name !== Undefined::VALUE) {
            $payload['name'] = $this->name;
        }
        if ($this->slug !== Undefined::VALUE) {
            $payload['slug'] = $this->slug;
        }
        if ($this->type !== Undefined::VALUE) {
            $payload['type'] = $this->type;
        }
        if ($this->applicationId !== Undefined::VALUE) {
            $payload['application_id'] = $this->applicationId;
        }
        if ($this->description !== Undefined::VALUE) {
            $payload['description'] = $this->description;
        }
        if ($this->parentId !== Undefined::VALUE) {
            $payload['parent_id'] = $this->parentId;
        }
        if ($this->vocabularyId !== Undefined::VALUE) {
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
