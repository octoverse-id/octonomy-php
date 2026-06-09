<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Internal\Json;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\Pagination;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * TagService accesses the /tags endpoints. Reach it via Client::tags().
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
        return Tag::fromArray(
            $this->transport->request('POST', 'tags', [], $input->toArray(), $options),
        );
    }

    /**
     * Retrieve a tag by id (GET /tags/{id}).
     */
    public function get(string $id, ?RequestOptions $options = null): Tag
    {
        return Tag::fromArray(
            $this->transport->request('GET', 'tags/' . rawurlencode($id), [], null, $options),
        );
    }

    /**
     * List a page of tags (GET /tags).
     *
     * @return ResultList<Tag>
     */
    public function list(?TagListParams $params = null, ?RequestOptions $options = null): ResultList
    {
        $body = $this->transport->request('GET', 'tags', $params?->toQuery() ?? [], null, $options);

        $data = [];
        foreach (Json::objectList($body, 'data') as $row) {
            $data[] = Tag::fromArray($row);
        }

        return new ResultList($data, Pagination::fromArray(Json::object($body, 'pagination')));
    }

    /**
     * Partially update a tag (PATCH /tags/{id}).
     */
    public function update(string $id, TagUpdate $input, ?RequestOptions $options = null): Tag
    {
        return Tag::fromArray(
            $this->transport->request('PATCH', 'tags/' . rawurlencode($id), [], $input->toArray(), $options),
        );
    }

    /**
     * Deactivate a tag (DELETE /tags/{id}). Octonomy treats deletion as
     * deactivation, which cascades to the tag's aliases.
     */
    public function delete(string $id, ?RequestOptions $options = null): void
    {
        $this->transport->request('DELETE', 'tags/' . rawurlencode($id), [], null, $options);
    }
}
