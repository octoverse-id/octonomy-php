<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * BulkRemove is the request body for removing several tags from one resource
 * in a single call. Every field is required, and the tags are named by id only:
 * the server takes no alias here.
 *
 * $tagIds is sent as a JSON list, in order, whatever its keys: an array left
 * with gaps by array_filter() or unset() would otherwise encode as an object,
 * which the server refuses.
 */
final class BulkRemove
{
    /**
     * @param array<array-key, string> $tagIds
     */
    public function __construct(
        public readonly string $applicationId,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly array $tagIds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'application_id' => $this->applicationId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'tag_ids' => array_values($this->tagIds),
        ];
    }
}
