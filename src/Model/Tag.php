<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Internal\Json;

/**
 * Tag is the core tagging unit. A tag with a null $applicationId is shared across
 * the tenant; otherwise it is scoped to a single application. $parentId and
 * $vocabularyId are set when the tag is nested or grouped. $usageCount is
 * server-computed and read-only.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Json::string($data, 'id'),
            tenantId: Json::string($data, 'tenant_id'),
            applicationId: Json::nullableString($data, 'application_id'),
            name: Json::string($data, 'name'),
            slug: Json::string($data, 'slug'),
            type: Json::string($data, 'type'),
            description: Json::nullableString($data, 'description'),
            parentId: Json::nullableString($data, 'parent_id'),
            vocabularyId: Json::nullableString($data, 'vocabulary_id'),
            metadata: Json::nullableObject($data, 'metadata'),
            isActive: Json::bool($data, 'is_active'),
            usageCount: Json::int($data, 'usage_count'),
            createdAt: Json::dateTime($data, 'created_at'),
            updatedAt: Json::dateTime($data, 'updated_at'),
        );
    }
}
