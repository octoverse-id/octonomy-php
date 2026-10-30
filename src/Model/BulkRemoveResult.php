<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * BulkRemoveResult is what assignments()->bulkRemove() did: $removed is the
 * number of assignments it removed. A tag the resource did not have counts for
 * nothing, so $removed can be lower than the number of tags sent, or 0.
 *
 * The server's OpenAPI document declares no body for this response; the
 * server sends {"data": {"removed": N}}, one of the divergences listed atop
 * the vendored copy.
 */
final class BulkRemoveResult
{
    public function __construct(
        public readonly int $removed,
    ) {}

    /**
     * Hydrate the result from the resource object of a response read as
     * $apiVersion.
     *
     * @param array<string, mixed> $data the object's members as Transport passes them
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws MalformedResponseException when $removed is absent or not an integer
     */
    public static function fromArray(array $data, string $apiVersion): self
    {
        return Json::readAs($apiVersion, static fn(): self => new self(
            removed: Json::int($data, 'removed'),
        ));
    }
}
