<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * Tag is the core tagging unit. A tag with a null $applicationId is shared across
 * the tenant; otherwise it is scoped to a single application. $namespaceType and
 * $namespaceId place the tag in a merchant or sub-tenant namespace; both are null
 * for a global tag, and always null from api_version v1, which sees only the
 * global partition. $parentId and $vocabularyId are set when the tag is nested or
 * grouped.
 *
 * $usageCount is server-computed and read-only, and means something different
 * per api_version. On v1 it counts the tag's assignments across the whole
 * tenant. On v2 it counts only the assignments the request can see: those in
 * its namespace (plus global ones when the caller may see global rows) and
 * within its application scope (octonomy/tags/selectors.py upstream). So the
 * same tag can report different counts from the two surfaces.
 */
final class Tag
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly ?string $applicationId,
        public readonly ?string $namespaceType,
        public readonly ?string $namespaceId,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $type,
        public readonly ?string $description,
        public readonly ?string $parentId,
        public readonly ?string $vocabularyId,
        public readonly ?array $metadata,
        public readonly bool $isActive,
        public readonly int $usageCount,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Hydrate a tag from one resource object of a response read as $apiVersion.
     * Every field declared above must be present with its declared type, and null
     * is accepted only where the property is nullable. The namespace pair is
     * declared for v2 alone, so v1 must not carry it.
     *
     * @param array<string, mixed> $data the object's members as Transport passes them: nested JSON
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
            name: Json::string($data, 'name'),
            slug: Json::string($data, 'slug'),
            type: Json::string($data, 'type'),
            description: Json::nullableString($data, 'description'),
            // The two exceptions to key presence. The server omits parent_id and
            // vocabulary_id when they are null instead of sending null: each is a
            // dotted source through a nullable relation ("parent.id"), which DRF
            // skips when it reaches None (octonomy/tags/serializers.py, unchanged
            // in every release since 1.0.0). So an absent key means null here.
            parentId: Json::nullableStringOrAbsent($data, 'parent_id'),
            vocabularyId: Json::nullableStringOrAbsent($data, 'vocabulary_id'),
            metadata: Json::nullableObject($data, 'metadata'),
            isActive: Json::bool($data, 'is_active'),
            usageCount: Json::int($data, 'usage_count'),
            createdAt: Json::dateTime($data, 'created_at'),
            updatedAt: Json::dateTime($data, 'updated_at'),
        ));
    }
}
