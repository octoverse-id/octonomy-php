<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * TagResource is one resource a tag is assigned to, as tags()->listResources()
 * lists them: the resource ($resourceType, $resourceId), the application the
 * assignment belongs to, and who made it and when. $namespaceType and
 * $namespaceId are the assignment's; both are null for a global assignment,
 * and always null from api_version v1, which sees only the global partition.
 */
final class TagResource
{
    /**
     * @readonly
     */
    public string $applicationId;
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
    public string $resourceType;
    /**
     * @readonly
     */
    public string $resourceId;
    /**
     * @readonly
     */
    public ?string $assignedBy;
    /**
     * @readonly
     */
    public \DateTimeImmutable $assignedAt;
    /**
     * A resource of a tag as fromArray() reads it from a response: each
     * argument is the field of the same name, in snake_case on the wire.
     */
    public function __construct(string $applicationId, ?string $namespaceType, ?string $namespaceId, string $resourceType, string $resourceId, ?string $assignedBy, \DateTimeImmutable $assignedAt)
    {
        $this->applicationId = $applicationId;
        $this->namespaceType = $namespaceType;
        $this->namespaceId = $namespaceId;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->assignedBy = $assignedBy;
        $this->assignedAt = $assignedAt;
    }

    /**
     * Hydrate a tag resource from one row of a response read as $apiVersion.
     * Every field declared above must be present with its declared type, and
     * null is accepted only where the property is nullable. The namespace pair
     * is declared for v2 alone, so v1 must not carry it.
     *
     * @param array<string, mixed> $data the row's members as Transport passes them
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws MalformedResponseException when a declared field is absent or has the wrong type,
     *                                    or an undeclared namespace field is present
     */
    public static function fromArray(array $data, string $apiVersion): self
    {
        return Json::readAs($apiVersion, static fn (): self => new self(
            Json::string($data, 'application_id'),
            Json::namespaceField($data, 'namespace_type', $apiVersion),
            Json::namespaceField($data, 'namespace_id', $apiVersion),
            Json::string($data, 'resource_type'),
            Json::string($data, 'resource_id'),
            Json::nullableString($data, 'assigned_by'),
            Json::dateTime($data, 'assigned_at'),
        ));
    }
}
