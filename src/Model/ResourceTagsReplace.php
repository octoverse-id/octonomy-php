<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * ResourceTagsReplace is the request body for replacing every tag a resource
 * has within $applicationId and the request's namespace: afterwards it has
 * exactly the tags in $tagIds plus those $aliasSlugs point to, and any other
 * there is removed. The resource itself is named in the path, by
 * resources()->replaceTags().
 *
 * $tagIds is required, so that clearing is never a default: with $tagIds []
 * and no $aliasSlugs, the replacement removes every tag the resource has
 * there. $aliasSlugs and $assignedBy are omitted from the payload when null.
 *
 * Each list is sent as a JSON list, in order, whatever its keys: an array
 * left with gaps by array_filter() or unset() would otherwise encode as an
 * object, which the server refuses.
 */
final class ResourceTagsReplace
{
    /**
     * @readonly
     */
    public string $applicationId;
    /**
     * @var array<array-key, string>
     * @readonly
     */
    public array $tagIds;
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
     * @param array<array-key, string>      $tagIds
     * @param array<array-key, string>|null $aliasSlugs
     */
    public function __construct(string $applicationId, array $tagIds, ?array $aliasSlugs = null, ?string $assignedBy = null)
    {
        $this->applicationId = $applicationId;
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
            'tag_ids' => array_values($this->tagIds),
        ];
        if ($this->aliasSlugs !== null) {
            $payload['alias_slugs'] = array_values($this->aliasSlugs);
        }
        if ($this->assignedBy !== null) {
            $payload['assigned_by'] = $this->assignedBy;
        }

        return $payload;
    }
}
