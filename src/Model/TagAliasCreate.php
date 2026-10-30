<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * TagAliasCreate is the request body for creating a tag alias. $tagId, the
 * canonical tag the alias resolves to, $name and $slug are required; the
 * remaining fields are optional and omitted from the payload when null.
 *
 * $metadata is always sent as a JSON object, the only kind the server accepts:
 * the array's keys are its members, so [] is sent as {}, and an alias's
 * metadata goes back out with every object and list it was read with (#48).
 * Inside it a \stdClass is sent as an object and an array as json_encode()
 * writes it, so an empty array is a JSON list: use new \stdClass() for an empty
 * nested object.
 */
final class TagAliasCreate
{
    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $tagId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $applicationId = null,
        public readonly ?array $metadata = null,
        public readonly ?bool $isActive = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'tag_id' => $this->tagId,
            'name' => $this->name,
            'slug' => $this->slug,
        ];
        if ($this->applicationId !== null) {
            $payload['application_id'] = $this->applicationId;
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
