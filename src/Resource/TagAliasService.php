<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Http\Walker;
use Octoverse\Octonomy\Internal\Path;
use Octoverse\Octonomy\Internal\ScopeQuery;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagAliasCreate;
use Octoverse\Octonomy\Model\TagAliasListParams;
use Octoverse\Octonomy\Model\TagAliasUpdate;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * TagAliasService accesses the /tag-aliases endpoints. Reach it via
 * Client::aliases(). A tag's own aliases are also listed by
 * TagService::listAliases().
 *
 * A call inside a namespace (RequestOptions::$namespace, api_version v2) must
 * name its application: in the body on create, in the params on list, and as
 * $applicationId on get, update and delete, which send it as the application_id
 * query parameter. The server refuses a namespaced call without one (403).
 */
final class TagAliasService
{
    /**
     * Built by Client::aliases(), over the client's one Transport; reach the
     * service there rather than constructing it.
     */
    public function __construct(private readonly Transport $transport) {}

    /**
     * Create an alias for a tag (POST /tag-aliases). An alias of a tag that
     * belongs to an application must name the same application.
     */
    public function create(TagAliasCreate $input, ?RequestOptions $options = null): TagAlias
    {
        return $this->transport->one('POST', 'tag-aliases', TagAlias::fromArray(...), [], $input->toArray(), $options);
    }

    /**
     * Retrieve an alias by id (GET /tag-aliases/{id}). $applicationId scopes the
     * read to an application, and a namespaced read needs it. $includeGlobal
     * (v2 only) lets a namespaced read reach a global alias, which it otherwise
     * answers with 404.
     */
    public function get(
        string $id,
        ?string $applicationId = null,
        ?bool $includeGlobal = null,
        ?RequestOptions $options = null,
    ): TagAlias {
        return $this->transport->one(
            'GET',
            Path::of('tag-aliases', $id),
            TagAlias::fromArray(...),
            ScopeQuery::of($applicationId, $includeGlobal),
            null,
            $options,
        );
    }

    /**
     * List a page of aliases (GET /tag-aliases). Without
     * TagAliasListParams::$isActive the server lists active aliases only.
     *
     * @return ResultList<TagAlias>
     */
    public function list(?TagAliasListParams $params = null, ?RequestOptions $options = null): ResultList
    {
        return $this->transport->many(
            'GET',
            'tag-aliases',
            TagAlias::fromArray(...),
            $params?->toQuery() ?? [],
            null,
            $options,
        );
    }

    /**
     * Walk every alias $params selects (GET /tag-aliases), yielding each
     * TagAlias keyed by its offset in the list. Aliases are sorted by name,
     * slug and id; without TagAliasListParams::$isActive the server lists
     * active aliases only.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence: it is the loop over list()
     * you did not have to write, not a bulk fetch. A page holds up to
     * $params->limit aliases, or 200, the server's maximum, when that is null.
     * Nothing is sent until the walk is iterated, and each page only once the
     * one before it is used up. $params->offset starts the walk part of the
     * way in.
     *
     * A failed page request throws WalkInterruptedException, whose
     * reachedOffset is where that page starts: walk again with the same params
     * and that offset to resume.
     *
     * It is not a snapshot. The server pages by offset and has no cursor, so an
     * alias created, or deactivated, that sorts before the walk's position
     * while the walk runs moves every one after it by one: the walk then
     * yields one twice, or never yields one, and a resume drifts the same way.
     * Narrow the walk with filters nothing changes while it runs, de-duplicate
     * on id, or walk while nothing writes.
     *
     * @return \Generator<int, TagAlias, mixed, void>
     *
     * @throws \InvalidArgumentException when $params->offset is negative (nothing is sent)
     */
    public function walk(?TagAliasListParams $params = null, ?RequestOptions $options = null): \Generator
    {
        return Walker::walk(
            $this->transport,
            'tag-aliases',
            TagAlias::fromArray(...),
            $params?->toQuery() ?? [],
            $params?->offset,
            $options,
        );
    }

    /**
     * Partially update an alias (PATCH /tag-aliases/{id}), such as pointing it
     * at another tag. $applicationId is the application the request is scoped
     * to, which a namespaced update needs. It cannot move the alias: an alias's
     * application is fixed at creation, and the server answers a change to it
     * with 409 scope_immutable.
     */
    public function update(
        string $id,
        TagAliasUpdate $input,
        ?string $applicationId = null,
        ?RequestOptions $options = null,
    ): TagAlias {
        return $this->transport->one(
            'PATCH',
            Path::of('tag-aliases', $id),
            TagAlias::fromArray(...),
            ScopeQuery::of($applicationId),
            $input->toArray(),
            $options,
        );
    }

    /**
     * Deactivate an alias (DELETE /tag-aliases/{id}). Octonomy treats deletion
     * as deactivation: the alias stays on record, inactive, and no longer
     * resolves or assigns. $applicationId scopes the request to an
     * application, and a namespaced delete needs it.
     */
    public function delete(string $id, ?string $applicationId = null, ?RequestOptions $options = null): void
    {
        $this->transport->none(
            'DELETE',
            Path::of('tag-aliases', $id),
            ScopeQuery::of($applicationId),
            null,
            $options,
        );
    }
}
