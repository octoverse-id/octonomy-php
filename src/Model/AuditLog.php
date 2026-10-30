<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * AuditLog is one entry of the append-only mutation history: $action (such as
 * "tag.updated" or "assignment.created") on the entity $entityType/$entityId,
 * with the tag and resource it concerns where there is one. $actorId is the
 * X-Actor-ID the mutation was sent with and $requestId its X-Request-ID, or
 * the id the server minted; entries one request wrote share an $operationId.
 * $namespaceType and $namespaceId are null for a global entry, and always null
 * from api_version v1, which sees only the global partition.
 *
 * $changes holds what changed, usually under "before" and "after", and
 * $metadata the entry's metadata. Both read as Tag::$metadata does: the top
 * level is an array, and below it a JSON object is a \stdClass and a JSON list
 * an array, so a snapshot of {} or {"0": "a"} keeps its shape. Read a member
 * of a snapshot as a property: $log->changes['after']->is_active.
 */
final class AuditLog
{
    /**
     * @param array<array-key, mixed>|null $changes
     * @param array<array-key, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly ?string $applicationId,
        public readonly ?string $namespaceType,
        public readonly ?string $namespaceId,
        public readonly string $action,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly ?string $tagId,
        public readonly ?string $resourceType,
        public readonly ?string $resourceId,
        public readonly ?string $actorId,
        public readonly ?string $requestId,
        public readonly string $operationId,
        public readonly ?array $changes,
        public readonly ?array $metadata,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    /**
     * Hydrate an entry from one row of a response read as $apiVersion. Every
     * field declared above must be present with its declared type, and null is
     * accepted only where the property is nullable. The namespace pair is
     * declared for v2 alone, so v1 must not carry it.
     *
     * @param array<string, mixed> $data the row's members as Transport passes them: nested JSON
     *                                  objects stay \stdClass, so an object can be told from a list
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws MalformedResponseException when a declared field is absent or has the wrong type,
     *                                    or an undeclared namespace field is present
     */
    public static function fromArray(array $data, string $apiVersion): self
    {
        return Json::readAs($apiVersion, static fn(): self => new self(
            id: Json::string($data, 'id'),
            tenantId: Json::string($data, 'tenant_id'),
            applicationId: Json::nullableString($data, 'application_id'),
            namespaceType: Json::namespaceField($data, 'namespace_type', $apiVersion),
            namespaceId: Json::namespaceField($data, 'namespace_id', $apiVersion),
            action: Json::string($data, 'action'),
            entityType: Json::string($data, 'entity_type'),
            entityId: Json::string($data, 'entity_id'),
            tagId: Json::nullableString($data, 'tag_id'),
            resourceType: Json::nullableString($data, 'resource_type'),
            resourceId: Json::nullableString($data, 'resource_id'),
            actorId: Json::nullableString($data, 'actor_id'),
            requestId: Json::nullableString($data, 'request_id'),
            operationId: Json::string($data, 'operation_id'),
            changes: Json::nullableObject($data, 'changes'),
            metadata: Json::nullableObject($data, 'metadata'),
            createdAt: Json::dateTime($data, 'created_at'),
        ));
    }
}
