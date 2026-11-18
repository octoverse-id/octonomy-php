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
     * @readonly
     */
    public string $applicationId;
    /**
     * @readonly
     */
    public string $resourceType;
    /**
     * @readonly
     */
    public string $resourceId;
    /**
     * @var array<array-key, string>
     * @readonly
     */
    public array $tagIds;
    /**
     * @param array<array-key, string> $tagIds
     */
    public function __construct(string $applicationId, string $resourceType, string $resourceId, array $tagIds)
    {
        $this->applicationId = $applicationId;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->tagIds = $tagIds;
    }

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
