<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Internal\ScopeQuery;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * TagService accesses the /tags endpoints. Reach it via Client::tags().
 *
 * A call inside a namespace (RequestOptions::$namespace, api_version v2) must
 * name its application: in the body on create, in the params on list, and as
 * $applicationId on get, update and delete, which send it as the application_id
 * query parameter. The server refuses a namespaced call without one (403).
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
            'tags/' . rawurlencode($id),
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
            'tags/' . rawurlencode($id),
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
        $this->transport->none('DELETE', 'tags/' . rawurlencode($id), ScopeQuery::of($applicationId), null, $options);
    }
}
