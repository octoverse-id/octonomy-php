<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * Vocabulary is a tenant-scoped grouping for tags. A vocabulary with a null
 * $applicationId is shared across all applications in the tenant; otherwise it is
 * scoped to a single application. $namespaceType and $namespaceId place it in a
 * merchant or sub-tenant namespace; both are null for a global vocabulary, and
 * always null from api_version v1, which sees only the global partition.
 *
 * $metadata holds the members of the vocabulary's metadata object, under PHP's
 * array keys ("0" becomes 0). Below the top level a JSON object is a
 * \stdClass and a JSON list is an array, so a nested {} or {"0": "a"} keeps its
 * shape, and the value goes back into a VocabularyCreate or VocabularyUpdate
 * with every object and list it was read with (#48). A \stdClass is a handle:
 * changing one changes this vocabulary's copy too, so clone it first to keep
 * the vocabulary as it was read.
 */
final class Vocabulary
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
    public ?string $description;
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
    public \DateTimeImmutable $createdAt;
    /**
     * @readonly
     */
    public \DateTimeImmutable $updatedAt;
    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function __construct(string $id, string $tenantId, ?string $applicationId, ?string $namespaceType, ?string $namespaceId, string $name, string $slug, ?string $description, ?array $metadata, bool $isActive, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->applicationId = $applicationId;
        $this->namespaceType = $namespaceType;
        $this->namespaceId = $namespaceId;
        $this->name = $name;
        $this->slug = $slug;
        $this->description = $description;
        $this->metadata = $metadata;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Hydrate a vocabulary from one resource object of a response read as
     * $apiVersion. Every field declared above must be present with its declared
     * type, and null is accepted only where the property is nullable. The
     * namespace pair is declared for v2 alone, so v1 must not carry it.
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
            Json::nullableString($data, 'description'),
            Json::nullableObject($data, 'metadata'),
            Json::bool($data, 'is_active'),
            Json::dateTime($data, 'created_at'),
            Json::dateTime($data, 'updated_at'),
        ));
    }
}
