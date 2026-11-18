<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * BulkAssignResult is what assignments()->bulkAssign() did: $created new
 * assignments, $existing ones the resource already had, which are left as they
 * were, and $assignments, every assignment the request named, new and existing
 * alike. The server sends $skipped as 0 (as of server 3.2.1); it is kept
 * because the server keeps it in the shape.
 *
 * The server's OpenAPI document declares this response as a bare list of
 * assignments; the server sends this summary object instead, wrapped in
 * {"data": ...}, one of the divergences listed atop the vendored copy.
 */
final class BulkAssignResult
{
    /**
     * @readonly
     */
    public int $created;
    /**
     * @readonly
     */
    public int $existing;
    /**
     * @readonly
     */
    public int $skipped;
    /**
     * @var list<Assignment>
     * @readonly
     */
    public array $assignments;
    /**
     * @param list<Assignment> $assignments
     */
    public function __construct(int $created, int $existing, int $skipped, array $assignments)
    {
        $this->created = $created;
        $this->existing = $existing;
        $this->skipped = $skipped;
        $this->assignments = $assignments;
    }

    /**
     * Hydrate the result from the resource object of a response read as
     * $apiVersion. Each assignment hydrates as Assignment does, so a field one
     * lacks is reported at its place, such as "data.assignments[2].tag_id".
     *
     * @param array<string, mixed> $data the object's members as Transport passes them
     * @param string $apiVersion an ApiVersion constant
     *
     * @throws MalformedResponseException when a declared field is absent or has the wrong type, here or
     *                                    in an assignment
     */
    public static function fromArray(array $data, string $apiVersion): self
    {
        return Json::readAs($apiVersion, static fn (): self => new self(
            Json::int($data, 'created'),
            Json::int($data, 'existing'),
            Json::int($data, 'skipped'),
            Json::resources($data, 'assignments', \Closure::fromCallable([Assignment::class, 'fromArray']), $apiVersion),
        ));
    }
}
