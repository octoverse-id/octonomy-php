<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Http\Walker;
use Octoverse\Octonomy\Internal\Path;
use Octoverse\Octonomy\Internal\ScopeQuery;
use Octoverse\Octonomy\Model\AuditLog;
use Octoverse\Octonomy\Model\AuditLogListParams;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagAliasListParams;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagResolution;
use Octoverse\Octonomy\Model\TagResource;
use Octoverse\Octonomy\Model\TagResourceListParams;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * TagService accesses the /tags endpoints, and /tag-resolution, which resolves
 * a slug to a tag. Reach it via Client::tags().
 *
 * A call inside a namespace (RequestOptions::$namespace, api_version v2) must
 * name its application: in the body on create, in the params on the lists, and
 * as $applicationId on get, update, delete and resolve, which send it as the
 * application_id query parameter. The server refuses a namespaced call without
 * one (403).
 */
final class TagService
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * Create a tag (POST /tags). A duplicate (type, slug) for the tenant raises a
     * ConflictException.
     */
    public function create(TagCreate $input, ?RequestOptions $options = null): Tag
    {
        return $this->transport->one('POST', 'tags', Tag::fromArray(...), [], $input->toArray(), $options);
    }

    /**
     * Retrieve a tag by id (GET /tags/{id}). $applicationId scopes the read to an
     * application, and a namespaced read needs it. $includeGlobal (v2 only) lets
     * a namespaced read reach a global tag, which it otherwise answers with 404.
     */
    public function get(
        string $id,
        ?string $applicationId = null,
        ?bool $includeGlobal = null,
        ?RequestOptions $options = null,
    ): Tag {
        return $this->transport->one(
            'GET',
            Path::of('tags', $id),
            Tag::fromArray(...),
            ScopeQuery::of($applicationId, $includeGlobal),
            null,
            $options,
        );
    }

    /**
     * List a page of tags (GET /tags).
     *
     * @return ResultList<Tag>
     */
    public function list(?TagListParams $params = null, ?RequestOptions $options = null): ResultList
    {
        return $this->transport->many(
            'GET',
            'tags',
            Tag::fromArray(...),
            $params?->toQuery() ?? [],
            null,
            $options,
        );
    }

    /**
     * Walk every tag $params selects (GET /tags), yielding each Tag keyed by
     * its offset in the list. Tags are sorted by name, slug and id.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence: it is the loop over list()
     * you did not have to write, not a bulk fetch. A page holds up to
     * $params->limit tags, or 200, the server's maximum, when that is null, so
     * 10,000 tags take 50 requests. Nothing is sent until the walk is iterated,
     * and each page only once the one before it is used up. $params->offset
     * starts the walk part of the way in.
     *
     * A failed page request throws WalkInterruptedException, whose
     * reachedOffset is where that page starts: walk again with the same params
     * and that offset to resume.
     *
     * It is not a snapshot. The server pages by offset and has no cursor, so a
     * tag created, or deactivated (which removes it from a list of active
     * tags), that sorts before the walk's position while the walk runs moves
     * every tag after it by one: the walk then yields one twice, or never
     * yields one, and a resume drifts the same way. Narrow the walk with
     * filters nothing changes while it runs, de-duplicate on id, or walk while
     * nothing writes.
     *
     * @return \Generator<int, Tag, mixed, void>
     *
     * @throws \InvalidArgumentException when $params->offset is negative (nothing is sent)
     */
    public function walk(?TagListParams $params = null, ?RequestOptions $options = null): \Generator
    {
        return Walker::walk(
            $this->transport,
            'tags',
            Tag::fromArray(...),
            $params?->toQuery() ?? [],
            $params?->offset,
            $options,
        );
    }

    /**
     * Partially update a tag (PATCH /tags/{id}). $applicationId is the
     * application the request is scoped to, which a namespaced update needs. It
     * cannot move the tag: a tag's application is fixed at creation, and the
     * server answers a change to it with 409 scope_immutable.
     */
    public function update(
        string $id,
        TagUpdate $input,
        ?string $applicationId = null,
        ?RequestOptions $options = null,
    ): Tag {
        return $this->transport->one(
            'PATCH',
            Path::of('tags', $id),
            Tag::fromArray(...),
            ScopeQuery::of($applicationId),
            $input->toArray(),
            $options,
        );
    }

    /**
     * Deactivate a tag (DELETE /tags/{id}). Octonomy treats deletion as
     * deactivation, which cascades to the tag's aliases. $applicationId scopes
     * the request to an application, and a namespaced delete needs it.
     */
    public function delete(string $id, ?string $applicationId = null, ?RequestOptions $options = null): void
    {
        $this->transport->none('DELETE', Path::of('tags', $id), ScopeQuery::of($applicationId), null, $options);
    }

    /**
     * Resolve a slug to the tag it names (GET /tag-resolution): the tag whose
     * own slug it is, or else the tag an alias with that slug points to.
     * TagResolution says which matched. A tag's own slug wins over an alias's,
     * and the most specific scope wins: the request's namespace, then the
     * global partition, and within $applicationId that application's tags
     * before shared ones.
     *
     * $type keeps tags of one type, $scope is TagResolution::SCOPE_GLOBAL or
     * SCOPE_MERCHANT to resolve in one partition alone, and $includeGlobal (v2
     * only) lets a namespaced request fall back to global rows. A slug that
     * matches nothing is a 400 (ValidationException), and one that matches
     * equally well in several places an AmbiguousResolutionException or a
     * ValidationException naming what tells them apart.
     */
    public function resolve(
        string $slug,
        ?string $applicationId = null,
        ?string $type = null,
        ?string $scope = null,
        ?bool $includeGlobal = null,
        ?RequestOptions $options = null,
    ): TagResolution {
        $query = ['slug' => $slug];
        if ($type !== null) {
            $query['type'] = $type;
        }
        if ($scope !== null) {
            $query['scope'] = $scope;
        }

        return $this->transport->one(
            'GET',
            'tag-resolution',
            TagResolution::fromArray(...),
            $query + ScopeQuery::of($applicationId, $includeGlobal),
            null,
            $options,
        );
    }

    /**
     * List a page of a tag's aliases (GET /tags/{id}/aliases). The server
     * applies every TagAliasListParams filter here too; without $isActive it
     * lists active aliases only. A tag the request cannot see is a 404.
     *
     * @return ResultList<TagAlias>
     */
    public function listAliases(
        string $id,
        ?TagAliasListParams $params = null,
        ?RequestOptions $options = null,
    ): ResultList {
        return $this->transport->many(
            'GET',
            Path::of('tags', $id, 'aliases'),
            TagAlias::fromArray(...),
            $params?->toQuery() ?? [],
            null,
            $options,
        );
    }

    /**
     * Walk every alias of a tag (GET /tags/{id}/aliases), yielding each
     * TagAlias keyed by its offset in the list. Aliases are sorted by name,
     * slug and id.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence, of up to $params->limit
     * aliases, or 200 when that is null: not a bulk fetch. Nothing is sent
     * until the walk is iterated. $params->offset starts it part of the way
     * in. A failed page request throws WalkInterruptedException, whose
     * reachedOffset is where to resume with the same params.
     *
     * It is not a snapshot: the server pages by offset, so an alias created or
     * deactivated before the walk's position while it runs makes the walk
     * yield one twice or never yield one (see walk()).
     *
     * @return \Generator<int, TagAlias, mixed, void>
     *
     * @throws \InvalidArgumentException when $params->offset is negative (nothing is sent)
     */
    public function walkAliases(
        string $id,
        ?TagAliasListParams $params = null,
        ?RequestOptions $options = null,
    ): \Generator {
        return Walker::walk(
            $this->transport,
            Path::of('tags', $id, 'aliases'),
            TagAlias::fromArray(...),
            $params?->toQuery() ?? [],
            $params?->offset,
            $options,
        );
    }

    /**
     * List a page of the resources a tag is assigned to
     * (GET /tags/{id}/resources), newest assignment first. A tag the request
     * cannot see is a 404.
     *
     * @return ResultList<TagResource>
     */
    public function listResources(
        string $id,
        ?TagResourceListParams $params = null,
        ?RequestOptions $options = null,
    ): ResultList {
        return $this->transport->many(
            'GET',
            Path::of('tags', $id, 'resources'),
            TagResource::fromArray(...),
            $params?->toQuery() ?? [],
            null,
            $options,
        );
    }

    /**
     * Walk every resource a tag is assigned to (GET /tags/{id}/resources),
     * yielding each TagResource keyed by its offset in the list, newest
     * assignment first.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence, of up to $params->limit
     * resources, or 200 when that is null: not a bulk fetch. Nothing is sent
     * until the walk is iterated. $params->offset starts it part of the way
     * in. A failed page request throws WalkInterruptedException, whose
     * reachedOffset is where to resume with the same params.
     *
     * It is not a snapshot: the server pages by offset, so a resource the tag
     * is assigned to while the walk runs, which lists first, makes the walk
     * yield one resource twice, and an assignment removed before the walk's
     * position makes it never yield one (see walk()).
     *
     * @return \Generator<int, TagResource, mixed, void>
     *
     * @throws \InvalidArgumentException when $params->offset is negative (nothing is sent)
     */
    public function walkResources(
        string $id,
        ?TagResourceListParams $params = null,
        ?RequestOptions $options = null,
    ): \Generator {
        return Walker::walk(
            $this->transport,
            Path::of('tags', $id, 'resources'),
            TagResource::fromArray(...),
            $params?->toQuery() ?? [],
            $params?->offset,
            $options,
        );
    }

    /**
     * List a page of the audit log entries about a tag
     * (GET /tags/{id}/audit-logs), newest first: its own changes, and those of
     * its aliases and assignments. Needs a token with the audit:read scope.
     *
     * @return ResultList<AuditLog>
     */
    public function listAuditLogs(
        string $id,
        ?AuditLogListParams $params = null,
        ?RequestOptions $options = null,
    ): ResultList {
        return $this->transport->many(
            'GET',
            Path::of('tags', $id, 'audit-logs'),
            AuditLog::fromArray(...),
            $params?->toQuery() ?? [],
            null,
            $options,
        );
    }

    /**
     * Walk every audit log entry about a tag (GET /tags/{id}/audit-logs),
     * yielding each AuditLog keyed by its offset in the list, newest first.
     * Needs a token with the audit:read scope.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence, of up to $params->limit
     * entries, or 200 when that is null: not a bulk fetch. Nothing is sent
     * until the walk is iterated. $params->offset starts it part of the way
     * in. A failed page request throws WalkInterruptedException, whose
     * reachedOffset is where to resume with the same params.
     *
     * It is not a snapshot: the server pages by offset, newest first, so each
     * change to the tag, its aliases or its assignments while the walk runs
     * logs an entry at the front of the list and makes the walk yield one
     * entry twice (see walk()).
     *
     * @return \Generator<int, AuditLog, mixed, void>
     *
     * @throws \InvalidArgumentException when $params->offset is negative (nothing is sent)
     */
    public function walkAuditLogs(
        string $id,
        ?AuditLogListParams $params = null,
        ?RequestOptions $options = null,
    ): \Generator {
        return Walker::walk(
            $this->transport,
            Path::of('tags', $id, 'audit-logs'),
            AuditLog::fromArray(...),
            $params?->toQuery() ?? [],
            $params?->offset,
            $options,
        );
    }
}
