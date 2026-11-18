<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * BulkAssign is the request body for assigning several tags to one resource
 * in a single call: by id in $tagIds, by alias slug in $aliasSlugs, or both.
 * The server needs at least one of the two, and assigns each alias's canonical
 * tag. $applicationId, $resourceType and $resourceId are required; the other
 * fields are omitted from the payload when null.
 *
 * Each list is sent as a JSON list, in order, whatever its keys: an array
 * left with gaps by array_filter() or unset() would otherwise encode as an
 * object, which the server refuses.
 */
final class BulkAssign
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
     * @var array<array-key, string>|null
     * @readonly
     */
    public ?array $tagIds = null;
    /**
     * @var array<array-key, string>|null
     * @readonly
     */
    public ?array $aliasSlugs = null;
    /**
     * @readonly
     */
    public ?string $assignedBy = null;
    /**
     * @param array<array-key, string>|null $tagIds
     * @param array<array-key, string>|null $aliasSlugs
     */
    public function __construct(string $applicationId, string $resourceType, string $resourceId, ?array $tagIds = null, ?array $aliasSlugs = null, ?string $assignedBy = null)
    {
        $this->applicationId = $applicationId;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->tagIds = $tagIds;
        $this->aliasSlugs = $aliasSlugs;
        $this->assignedBy = $assignedBy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'application_id' => $this->applicationId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
        if ($this->tagIds !== null) {
            $payload['tag_ids'] = array_values($this->tagIds);
        }
        if ($this->aliasSlugs !== null) {
            $payload['alias_slugs'] = array_values($this->aliasSlugs);
        }
        if ($this->assignedBy !== null) {
            $payload['assigned_by'] = $this->assignedBy;
        }

        return $payload;
    }
}
