<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * ResourceTag is one tag on a resource, as resources()->listTags() lists them:
 * the $tag itself, and the assignment that put it there ($assignmentId, who
 * made it and when). $namespaceType and $namespaceId are the assignment's,
 * and the tag carries its own pair; both are null for a global row, and always
 * null from api_version v1, which sees only the global partition.
 */
final class ResourceTag
{
    /**
     * @readonly
     */
    public string $assignmentId;
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
    public ?string $assignedBy;
    /**
     * @readonly
     */
    public \DateTimeImmutable $assignedAt;
    /**
     * @readonly
     */
    public Tag $tag;
    /**
     * A tag on a resource as fromArray() reads it from a response: each
     * argument is the field of the same name, in snake_case on the wire, and
     * $tag the nested tag.
     */
    public function __construct(string $assignmentId, ?string $namespaceType, ?string $namespaceId, ?string $assignedBy, \DateTimeImmutable $assignedAt, Tag $tag)
    {
        $this->assignmentId = $assignmentId;
        $this->namespaceType = $namespaceType;
        $this->namespaceId = $namespaceId;
        $this->assignedBy = $assignedBy;
        $this->assignedAt = $assignedAt;
        $this->tag = $tag;
    }

    /**
     * Hydrate a resource tag from one row of a response read as $apiVersion.
     * The tag hydrates as Tag does, so a field it lacks is reported at its
     * path, such as "data[0].tag.slug".
     *
     * @param array<string, mixed> $data the row's members as Transport passes them
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws MalformedResponseException when a declared field is absent or has the wrong type, here or
     *                                    in the tag, or an undeclared namespace field is present
     */
    public static function fromArray(array $data, string $apiVersion): self
    {
        return Json::readAs($apiVersion, static fn (): self => new self(
            Json::string($data, 'assignment_id'),
            Json::namespaceField($data, 'namespace_type', $apiVersion),
            Json::namespaceField($data, 'namespace_id', $apiVersion),
            Json::nullableString($data, 'assigned_by'),
            Json::dateTime($data, 'assigned_at'),
            Json::resource($data, 'tag', \Closure::fromCallable([Tag::class, 'fromArray']), $apiVersion),
        ));
    }
}
