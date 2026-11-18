<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * AssignmentRemove is the request body for removing one tag from a resource:
 * the assignment of $tagId to the resource $resourceType/$resourceId within
 * $applicationId. Every field is required. Unlike a create, it names the tag
 * by id only: the server takes no alias here.
 */
final class AssignmentRemove
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
     * @readonly
     */
    public string $tagId;
    /**
     * Name the assignment to remove: the tag $tagId on the resource
     * $resourceType/$resourceId within $applicationId.
     */
    public function __construct(string $applicationId, string $resourceType, string $resourceId, string $tagId)
    {
        $this->applicationId = $applicationId;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->tagId = $tagId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'application_id' => $this->applicationId,
            'tag_id' => $this->tagId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }
}
