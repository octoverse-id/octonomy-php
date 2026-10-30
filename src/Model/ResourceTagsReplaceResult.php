<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * ResourceTagsReplaceResult is what resources()->replaceTags() did: $created
 * new assignments, $removed ones it took away, and $tags, every tag the
 * resource has in the application and namespace afterwards.
 *
 * The server's OpenAPI document declares this response as a bare list of
 * resource tags; the server sends this summary object instead, wrapped in
 * {"data": ...}, one of the divergences listed atop the vendored copy.
 */
final class ResourceTagsReplaceResult
{
    /**
     * @param list<Tag> $tags
     */
    public function __construct(
        public readonly int $created,
        public readonly int $removed,
        public readonly array $tags,
    ) {}

    /**
     * Hydrate the result from the resource object of a response read as
     * $apiVersion. Each tag hydrates as Tag does, so a field one lacks is
     * reported at its place, such as "data.tags[0].slug".
     *
     * @param array<string, mixed> $data the object's members as Transport passes them
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws MalformedResponseException when a declared field is absent or has the wrong type, here or
     *                                    in a tag
     */
    public static function fromArray(array $data, string $apiVersion): self
    {
        return Json::readAs($apiVersion, static fn(): self => new self(
            created: Json::int($data, 'created'),
            removed: Json::int($data, 'removed'),
            tags: Json::resources($data, 'tags', Tag::fromArray(...), $apiVersion),
        ));
    }
}
