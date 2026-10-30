<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * AssignmentCreate is the request body for assigning a tag to a resource. The
 * server takes exactly one way to name the tag, so each is its own named
 * constructor rather than three nullable parameters:
 *
 *   AssignmentCreate::forTag('cms', 'product', 'sku-1', $tagId)
 *   AssignmentCreate::forAliasId('cms', 'product', 'sku-1', $aliasId)
 *   AssignmentCreate::forAliasSlug('cms', 'product', 'sku-1', 'promo')
 *
 * An alias assigns the canonical tag it points to. $applicationId is required:
 * every assignment belongs to an application. $assignedBy is recorded on the
 * assignment, and is omitted from the payload when null.
 */
final class AssignmentCreate
{
    private function __construct(
        public readonly string $applicationId,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly ?string $tagId,
        public readonly ?string $aliasId,
        public readonly ?string $aliasSlug,
        public readonly ?string $assignedBy,
    ) {}

    /**
     * Assign the tag with id $tagId.
     */
    public static function forTag(
        string $applicationId,
        string $resourceType,
        string $resourceId,
        string $tagId,
        ?string $assignedBy = null,
    ): self {
        return new self($applicationId, $resourceType, $resourceId, $tagId, null, null, $assignedBy);
    }

    /**
     * Assign the tag the alias with id $aliasId points to.
     */
    public static function forAliasId(
        string $applicationId,
        string $resourceType,
        string $resourceId,
        string $aliasId,
        ?string $assignedBy = null,
    ): self {
        return new self($applicationId, $resourceType, $resourceId, null, $aliasId, null, $assignedBy);
    }

    /**
     * Assign the tag the alias with slug $aliasSlug points to, as the server
     * resolves the slug within $applicationId and the request's namespace.
     */
    public static function forAliasSlug(
        string $applicationId,
        string $resourceType,
        string $resourceId,
        string $aliasSlug,
        ?string $assignedBy = null,
    ): self {
        return new self($applicationId, $resourceType, $resourceId, null, null, $aliasSlug, $assignedBy);
    }

    /**
     * The JSON body: the application, the tag as the named constructor named
     * it, the resource, and assigned_by when set.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['application_id' => $this->applicationId];
        if ($this->tagId !== null) {
            $payload['tag_id'] = $this->tagId;
        }
        if ($this->aliasId !== null) {
            $payload['alias_id'] = $this->aliasId;
        }
        if ($this->aliasSlug !== null) {
            $payload['alias_slug'] = $this->aliasSlug;
        }
        $payload['resource_type'] = $this->resourceType;
        $payload['resource_id'] = $this->resourceId;
        if ($this->assignedBy !== null) {
            $payload['assigned_by'] = $this->assignedBy;
        }

        return $payload;
    }
}
