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
 *
 * $metadata holds the members of the tag's metadata object, under PHP's array
 * keys ("0" becomes 0). Below the top level a JSON object is a \stdClass
 * and a JSON list is an array, so a nested {} or {"0": "a"} keeps its shape,
 * and the value goes back into a TagCreate or TagUpdate with every object and
 * list it was read with (#48). A \stdClass is a handle: changing one changes
 * this tag's copy too, so clone it first to keep the tag as it was read.
 */
final class Tag
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
    public string $name;
    /**
     * @readonly
     */
    public string $slug;
    /**
     * @readonly
     */
    public string $type;
    /**
     * @readonly
     */
    public ?string $description;
    /**
     * @readonly
     */
    public ?string $parentId;
    /**
     * @readonly
     */
    public ?string $vocabularyId;
    /**
     * @var array<array-key, mixed>|null
     * @readonly
     */
    public ?array $metadata;
    /**
     * @readonly
     */
    public bool $isActive;
    /**
     * @readonly
     */
    public int $usageCount;
    /**
     * @readonly
     */
    public \DateTimeImmutable $createdAt;
    /**
     * @readonly
     */
    public \DateTimeImmutable $updatedAt;
    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function __construct(string $id, string $tenantId, ?string $applicationId, ?string $namespaceType, ?string $namespaceId, string $name, string $slug, string $type, ?string $description, ?string $parentId, ?string $vocabularyId, ?array $metadata, bool $isActive, int $usageCount, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->applicationId = $applicationId;
        $this->namespaceType = $namespaceType;
        $this->namespaceId = $namespaceId;
        $this->name = $name;
        $this->slug = $slug;
        $this->type = $type;
        $this->description = $description;
        $this->parentId = $parentId;
        $this->vocabularyId = $vocabularyId;
        $this->metadata = $metadata;
        $this->isActive = $isActive;
        $this->usageCount = $usageCount;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

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
        return Json::readAs($apiVersion, static fn (): self => new self(
            Json::string($data, 'id'),
            Json::string($data, 'tenant_id'),
            Json::nullableString($data, 'application_id'),
            Json::namespaceField($data, 'namespace_type', $apiVersion),
            Json::namespaceField($data, 'namespace_id', $apiVersion),
            Json::string($data, 'name'),
            Json::string($data, 'slug'),
            Json::string($data, 'type'),
            Json::nullableString($data, 'description'),
            Json::nullableStringOrAbsent($data, 'parent_id'),
            Json::nullableStringOrAbsent($data, 'vocabulary_id'),
            Json::nullableObject($data, 'metadata'),
            Json::bool($data, 'is_active'),
            Json::int($data, 'usage_count'),
            Json::dateTime($data, 'created_at'),
            Json::dateTime($data, 'updated_at'),
        ));
    }
}
