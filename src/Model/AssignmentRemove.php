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
     * Name the assignment to remove: the tag $tagId on the resource
     * $resourceType/$resourceId within $applicationId.
     */
    public function __construct(
        public readonly string $applicationId,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly string $tagId,
    ) {}

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
