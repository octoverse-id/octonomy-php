<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * Assignment links a tag to an external resource, named by $resourceType and
 * $resourceId, within one application: an assignment always has an
 * $applicationId. $tagId is the canonical tag even when the assignment was
 * made through an alias. $namespaceType and $namespaceId place it in a
 * merchant or sub-tenant namespace; both are null for a global assignment, and
 * always null from api_version v1, which sees only the global partition.
 */
final class Assignment
{
    /**
     * An assignment as fromArray() reads it from a response: each argument is
     * the field of the same name, in snake_case on the wire.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $applicationId,
        public readonly ?string $namespaceType,
        public readonly ?string $namespaceId,
        public readonly string $tagId,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly ?string $assignedBy,
        public readonly \DateTimeImmutable $assignedAt,
    ) {}

    /**
     * Hydrate an assignment from one resource object of a response read as
     * $apiVersion. Every field declared above must be present with its declared
     * type, and null is accepted only where the property is nullable. The
     * namespace pair is declared for v2 alone, so v1 must not carry it.
     *
     * @param array<string, mixed> $data the object's members as Transport passes them
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
            applicationId: Json::string($data, 'application_id'),
            namespaceType: Json::namespaceField($data, 'namespace_type', $apiVersion),
            namespaceId: Json::namespaceField($data, 'namespace_id', $apiVersion),
            tagId: Json::string($data, 'tag_id'),
            resourceType: Json::string($data, 'resource_type'),
            resourceId: Json::string($data, 'resource_id'),
            assignedBy: Json::nullableString($data, 'assigned_by'),
            assignedAt: Json::dateTime($data, 'assigned_at'),
        ));
    }
}
