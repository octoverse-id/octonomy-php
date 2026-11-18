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
     * @readonly
     */
    public string $id;
    /**
     * @readonly
     */
    public string $tenantId;
    /**
     * @readonly
     */
    public ?string $applicationId;
    /**
     * @readonly
     */
    public ?string $namespaceType;
    /**
     * @readonly
     */
    public ?string $namespaceId;
    /**
     * @readonly
     */
    public string $action;
    /**
     * @readonly
     */
    public string $entityType;
    /**
     * @readonly
     */
    public string $entityId;
    /**
     * @readonly
     */
    public ?string $tagId;
    /**
     * @readonly
     */
    public ?string $resourceType;
    /**
     * @readonly
     */
    public ?string $resourceId;
    /**
     * @readonly
     */
    public ?string $actorId;
    /**
     * @readonly
     */
    public ?string $requestId;
    /**
     * @readonly
     */
    public string $operationId;
    /**
     * @var array<array-key, mixed>
     * @readonly
     */
    public array $changes;
    /**
     * @var array<array-key, mixed>
     * @readonly
     */
    public array $metadata;
    /**
     * @readonly
     */
    public \DateTimeImmutable $createdAt;
    /**
     * @param array<array-key, mixed> $changes  never null: the server stores {} for none
     * @param array<array-key, mixed> $metadata never null: the server stores {} for none
     */
    public function __construct(string $id, string $tenantId, ?string $applicationId, ?string $namespaceType, ?string $namespaceId, string $action, string $entityType, string $entityId, ?string $tagId, ?string $resourceType, ?string $resourceId, ?string $actorId, ?string $requestId, string $operationId, array $changes, array $metadata, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->applicationId = $applicationId;
        $this->namespaceType = $namespaceType;
        $this->namespaceId = $namespaceId;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->tagId = $tagId;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->actorId = $actorId;
        $this->requestId = $requestId;
        $this->operationId = $operationId;
        $this->changes = $changes;
        $this->metadata = $metadata;
        $this->createdAt = $createdAt;
    }

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
        return Json::readAs($apiVersion, static fn (): self => new self(
            Json::string($data, 'id'),
            Json::string($data, 'tenant_id'),
            Json::nullableString($data, 'application_id'),
            Json::namespaceField($data, 'namespace_type', $apiVersion),
            Json::namespaceField($data, 'namespace_id', $apiVersion),
            Json::string($data, 'action'),
            Json::string($data, 'entity_type'),
            Json::string($data, 'entity_id'),
            Json::nullableString($data, 'tag_id'),
            Json::nullableString($data, 'resource_type'),
            Json::nullableString($data, 'resource_id'),
            Json::nullableString($data, 'actor_id'),
            Json::nullableString($data, 'request_id'),
            Json::string($data, 'operation_id'),
            Json::object($data, 'changes'),
            Json::object($data, 'metadata'),
            Json::dateTime($data, 'created_at'),
        ));
    }
}
