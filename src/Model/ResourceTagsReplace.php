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
     * @param array<array-key, string>      $tagIds
     * @param array<array-key, string>|null $aliasSlugs
     */
    public function __construct(
        public readonly string $applicationId,
        public readonly array $tagIds,
        public readonly ?array $aliasSlugs = null,
        public readonly ?string $assignedBy = null,
    ) {}

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
