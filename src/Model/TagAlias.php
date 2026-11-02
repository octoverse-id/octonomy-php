<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * TagAlias is an alternate slug for a canonical tag, $tagId: an assignment or
 * a resolution that names the alias reaches the tag it points to. An alias with
 * a null $applicationId is shared across the tenant; otherwise it belongs to a
 * single application, and the server holds it to the tag's tenant and
 * application. $namespaceType and $namespaceId place it in a merchant or
 * sub-tenant namespace; both are null for a global alias, and always null from
 * api_version v1, which sees only the global partition.
 *
 * $metadata holds the members of the alias's metadata object, under PHP's array
 * keys ("0" becomes 0). Below the top level a JSON object is a \stdClass and a
 * JSON list is an array, so a nested {} or {"0": "a"} keeps its shape, and the
 * value goes back into a TagAliasCreate or TagAliasUpdate with every object and
 * list it was read with (#48). A \stdClass is a handle: changing one changes
 * this alias's copy too, so clone it first to keep the alias as it was read.
 */
final class TagAlias
{
    /**
     * @param array<array-key, mixed> $metadata never null: the server stores {} for none
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly ?string $applicationId,
        public readonly ?string $namespaceType,
        public readonly ?string $namespaceId,
        public readonly string $tagId,
        public readonly string $name,
        public readonly string $slug,
        public readonly array $metadata,
        public readonly bool $isActive,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Hydrate an alias from one resource object of a response read as
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
        return Json::readAs($apiVersion, static fn(): self => new self(
            id: Json::string($data, 'id'),
            tenantId: Json::string($data, 'tenant_id'),
            applicationId: Json::nullableString($data, 'application_id'),
            namespaceType: Json::namespaceField($data, 'namespace_type', $apiVersion),
            namespaceId: Json::namespaceField($data, 'namespace_id', $apiVersion),
            tagId: Json::string($data, 'tag_id'),
            name: Json::string($data, 'name'),
            slug: Json::string($data, 'slug'),
            metadata: Json::object($data, 'metadata'),
            isActive: Json::bool($data, 'is_active'),
            createdAt: Json::dateTime($data, 'created_at'),
            updatedAt: Json::dateTime($data, 'updated_at'),
        ));
    }
}
