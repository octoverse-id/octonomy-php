<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * TagResolution is what tags()->resolve() found for a slug: the canonical $tag,
 * and how the slug reached it. $matchedType is MATCHED_TAG when the slug is the
 * tag's own, and MATCHED_ALIAS when it is an alias's, which $matchedAlias then
 * holds; it is null for a tag match. A tag's own slug wins over an alias with
 * the same slug.
 *
 * The constants are strings, not an enum, so the 1.x line can be downgraded to
 * PHP 7.4 (#5). Compare $matchedType with ===.
 */
final class TagResolution
{
    /** The slug is the tag's own. */
    public const MATCHED_TAG = 'tag';

    /** The slug is an alias's, and $matchedAlias is that alias. */
    public const MATCHED_ALIAS = 'alias';

    /**
     * The $scope of tags()->resolve() that resolves in the global partition
     * alone, even from inside a namespace. Unless the token's grant covers the
     * global partition, the server answers as it does when nothing matches
     * (400, ValidationException).
     */
    public const SCOPE_GLOBAL = 'global';

    /**
     * The $scope of tags()->resolve() that resolves in the request's own
     * namespace alone, without falling back to global rows. A request outside
     * a namespace cannot use it (400).
     */
    public const SCOPE_MERCHANT = 'merchant';

    public function __construct(
        public readonly string $matchedType,
        public readonly ?TagAlias $matchedAlias,
        public readonly Tag $tag,
    ) {}

    /**
     * Hydrate a resolution from the resource object of a response read as
     * $apiVersion. The matched alias and the tag hydrate as their own models
     * do, so a field either lacks is reported at its path, such as
     * "data.tag.slug".
     *
     * @param array<string, mixed> $data the object's members as Transport passes them
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws MalformedResponseException when a declared field is absent or has the wrong type, in the
     *                                    resolution or in the alias or tag it holds
     */
    public static function fromArray(array $data, string $apiVersion): self
    {
        return Json::readAs($apiVersion, static fn(): self => new self(
            matchedType: Json::string($data, 'matched_type'),
            matchedAlias: Json::nullableResource($data, 'matched_alias', TagAlias::fromArray(...), $apiVersion),
            tag: Json::resource($data, 'tag', Tag::fromArray(...), $apiVersion),
        ));
    }
}
