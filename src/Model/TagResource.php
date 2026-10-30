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
    public function __construct(
        public readonly string $applicationId,
        public readonly ?string $namespaceType,
        public readonly ?string $namespaceId,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly ?string $assignedBy,
        public readonly \DateTimeImmutable $assignedAt,
    ) {}

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
        return Json::readAs($apiVersion, static fn(): self => new self(
            applicationId: Json::string($data, 'application_id'),
            namespaceType: Json::namespaceField($data, 'namespace_type', $apiVersion),
            namespaceId: Json::namespaceField($data, 'namespace_id', $apiVersion),
            resourceType: Json::string($data, 'resource_type'),
            resourceId: Json::string($data, 'resource_id'),
            assignedBy: Json::nullableString($data, 'assigned_by'),
            assignedAt: Json::dateTime($data, 'assigned_at'),
        ));
    }
}
