<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Model\Assignment;
use Octoverse\Octonomy\Model\AssignmentCreate;
use Octoverse\Octonomy\Model\AssignmentRemove;
use Octoverse\Octonomy\Model\BulkAssign;
use Octoverse\Octonomy\Model\BulkAssignResult;
use Octoverse\Octonomy\Model\BulkRemove;
use Octoverse\Octonomy\Model\BulkRemoveResult;
use Octoverse\Octonomy\RequestOptions;

/**
 * AssignmentService accesses the /tag-assignments endpoints, which link tags to
 * external resources. Reach it via Client::assignments(). Reading the tags on a
 * resource is ResourceService::listTags(), and the resources a tag is on
 * TagService::listResources().
 *
 * Every write names its application in the body, as every assignment belongs
 * to one; inside a namespace (RequestOptions::$namespace, api_version v2) the
 * assignment is made in that namespace. The server refuses an inactive tag
 * with 400 inactive_tag, and a tag outside the application with
 * application_mismatch.
 */
final class AssignmentService
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * Assign a tag to a resource (POST /tag-assignments). Assigning a tag the
     * resource already has is not an error: the server answers 200 with the
     * existing assignment instead of 201 with a new one, and this returns
     * either the same way, so the call can be repeated safely.
     */
    public function create(AssignmentCreate $input, ?RequestOptions $options = null): Assignment
    {
        return $this->transport->one('POST', 'tag-assignments', Assignment::fromArray(...), [], $input->toArray(), $options);
    }

    /**
     * Remove a tag from a resource (DELETE /tag-assignments, with the
     * assignment named in the body). Unlike deleting a tag, this removes the
     * assignment. The server answers 204 whether or not the resource had the
     * tag, so the call can be repeated safely.
     */
    public function remove(AssignmentRemove $input, ?RequestOptions $options = null): void
    {
        $this->transport->none('DELETE', 'tag-assignments', [], $input->toArray(), $options);
    }

    /**
     * Assign several tags to one resource in a single call
     * (POST /tag-assignments/bulk-assign). Tags the resource already has are
     * counted as existing and left as they are.
     */
    public function bulkAssign(BulkAssign $input, ?RequestOptions $options = null): BulkAssignResult
    {
        return $this->transport->one(
            'POST',
            'tag-assignments/bulk-assign',
            BulkAssignResult::fromArray(...),
            [],
            $input->toArray(),
            $options,
        );
    }

    /**
     * Remove several tags from one resource in a single call
     * (POST /tag-assignments/bulk-remove). Tags the resource does not have are
     * not counted in the result.
     */
    public function bulkRemove(BulkRemove $input, ?RequestOptions $options = null): BulkRemoveResult
    {
        return $this->transport->one(
            'POST',
            'tag-assignments/bulk-remove',
            BulkRemoveResult::fromArray(...),
            [],
            $input->toArray(),
            $options,
        );
    }
}
