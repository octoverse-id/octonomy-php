<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Undefined;

/**
 * VocabularyUpdate is the PATCH body for updating a vocabulary. The server
 * leaves alone every field the body does not name, so this sends only the fields
 * you set.
 *
 * Each string field has three states. Its default, Undefined::VALUE, omits the
 * key; null sends an explicit null; a string sends that string:
 *
 *   new VocabularyUpdate(name: 'Labels')      // {"name": "Labels"}, nothing else changes
 *   new VocabularyUpdate(description: null)   // {"description": null}, clears it
 *
 * The server decides what a null does, and the SDK sends it either way.
 * description accepts it and is cleared. The server never clears
 * applicationId, since a vocabulary's application is fixed at creation: a null
 * is a 400 (ValidationException) on a namespaced vocabulary, a 409
 * scope_immutable (ConflictException) on a global one with an application, and
 * changes nothing on a tenant-shared one. name and slug cannot be null, so it is
 * a 400 (ValidationException).
 *
 * metadata and isActive keep two states, because the server has no null for
 * either and Undefined::VALUE, a string, cannot default an array or a bool: null
 * omits the key. metadata replaces the stored object rather than merging into
 * it, and [] empties it. It is always sent as a JSON object, so a vocabulary's
 * metadata goes back out with every object and list it was read with (#48).
 * Inside metadata an empty array is sent as a JSON list; use new \stdClass()
 * for an empty nested object.
 */
final class VocabularyUpdate
{
    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function __construct(
        public readonly ?string $name = Undefined::VALUE,
        public readonly ?string $slug = Undefined::VALUE,
        public readonly ?string $applicationId = Undefined::VALUE,
        public readonly ?string $description = Undefined::VALUE,
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
        if ($this->applicationId !== Undefined::VALUE) {
            $payload['application_id'] = $this->applicationId;
        }
        if ($this->description !== Undefined::VALUE) {
            $payload['description'] = $this->description;
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
