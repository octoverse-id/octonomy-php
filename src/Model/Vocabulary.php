<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Internal\Json;

/**
 * Vocabulary is a tenant-scoped grouping for tags. A vocabulary with a null
 * $applicationId is shared across all applications in the tenant; otherwise it is
 * scoped to a single application.
 */
final class Vocabulary
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
        public readonly ?string $description,
        public readonly ?array $metadata,
        public readonly bool $isActive,
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
            description: Json::nullableString($data, 'description'),
            metadata: Json::nullableObject($data, 'metadata'),
            isActive: Json::bool($data, 'is_active'),
            createdAt: Json::dateTime($data, 'created_at'),
            updatedAt: Json::dateTime($data, 'updated_at'),
        );
    }
}
