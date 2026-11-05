<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Http\Walker;
use Octoverse\Octonomy\Internal\Path;
use Octoverse\Octonomy\Model\AuditLog;
use Octoverse\Octonomy\Model\AuditLogListParams;
use Octoverse\Octonomy\Model\ResourceTag;
use Octoverse\Octonomy\Model\ResourceTagListParams;
use Octoverse\Octonomy\Model\ResourceTagsReplace;
use Octoverse\Octonomy\Model\ResourceTagsReplaceResult;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * ResourceService accesses the /resources/{type}/{id} endpoints: the tags on
 * one external resource, and its audit log. Reach it via Client::resources().
 * A resource is not stored on its own; it is the pair of a type and an id that
 * tags are assigned to.
 *
 * A call inside a namespace (RequestOptions::$namespace, api_version v2) must
 * name its application: listTags() and the replacement always do, and
 * listAuditLogs() takes it in its params. The server refuses a namespaced call
 * without one (403).
 */
final class ResourceService
{
    /**
     * Built by Client::resources(), over the client's one Transport; reach the
     * service there rather than constructing it.
     */
    public function __construct(private readonly Transport $transport) {}

    /**
     * List a page of the tags on a resource within $applicationId
     * (GET /resources/{type}/{id}/tags), each with the assignment that put it
     * there. The server requires the application. Without
     * ResourceTagListParams::$includeInactive it lists active tags only.
     *
     * @return ResultList<ResourceTag>
     */
    public function listTags(
        string $resourceType,
        string $resourceId,
        string $applicationId,
        ?ResourceTagListParams $params = null,
        ?RequestOptions $options = null,
    ): ResultList {
        return $this->transport->many(
            'GET',
            Path::of('resources', $resourceType, $resourceId, 'tags'),
            ResourceTag::fromArray(...),
            ['application_id' => $applicationId] + ($params?->toQuery() ?? []),
            null,
            $options,
        );
    }

    /**
     * Walk every tag on a resource within $applicationId
     * (GET /resources/{type}/{id}/tags), yielding each ResourceTag keyed by its
     * offset in the list, newest assignment first.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence, of up to $params->limit tags,
     * or 200, the server's maximum, when that is null: not a bulk fetch.
     * Nothing is sent until the walk is iterated. $params->offset starts it
     * part of the way in. A failed page request throws
     * WalkInterruptedException, whose reachedOffset is where that page starts:
     * walk again with the same arguments and that offset to resume.
     *
     * It is not a snapshot. The server pages by offset and has no cursor, so a
     * tag assigned to the resource while the walk runs, which lists first,
     * makes the walk yield one tag twice, and one removed before the walk's
     * position makes it never yield one; a resume drifts the same way.
     *
     * @return \Generator<int, ResourceTag, mixed, void>
     *
     * @throws \InvalidArgumentException when $params->offset is negative (nothing is sent)
     */
    public function walkTags(
        string $resourceType,
        string $resourceId,
        string $applicationId,
        ?ResourceTagListParams $params = null,
        ?RequestOptions $options = null,
    ): \Generator {
        return Walker::walk(
            $this->transport,
            Path::of('resources', $resourceType, $resourceId, 'tags'),
            ResourceTag::fromArray(...),
            ['application_id' => $applicationId] + ($params?->toQuery() ?? []),
            $params?->offset,
            $options,
        );
    }

    /**
     * Replace every tag on a resource within the application and namespace
     * (POST /resources/{type}/{id}/tags): afterwards it has exactly the tags
     * $input names, and the others are removed. $input->tagIds [] with no
     * alias slugs removes them all.
     */
    public function replaceTags(
        string $resourceType,
        string $resourceId,
        ResourceTagsReplace $input,
        ?RequestOptions $options = null,
    ): ResourceTagsReplaceResult {
        return $this->transport->one(
            'POST',
            Path::of('resources', $resourceType, $resourceId, 'tags'),
            ResourceTagsReplaceResult::fromArray(...),
            [],
            $input->toArray(),
            $options,
        );
    }

    /**
     * List a page of the audit log entries about a resource
     * (GET /resources/{type}/{id}/audit-logs), newest first. Needs a token with
     * the audit:read scope.
     *
     * @return ResultList<AuditLog>
     */
    public function listAuditLogs(
        string $resourceType,
        string $resourceId,
        ?AuditLogListParams $params = null,
        ?RequestOptions $options = null,
    ): ResultList {
        return $this->transport->many(
            'GET',
            Path::of('resources', $resourceType, $resourceId, 'audit-logs'),
            AuditLog::fromArray(...),
            $params?->toQuery() ?? [],
            null,
            $options,
        );
    }

    /**
     * Walk every audit log entry about a resource
     * (GET /resources/{type}/{id}/audit-logs), yielding each AuditLog keyed by
     * its offset in the list, newest first. Needs a token with the audit:read
     * scope.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence, of up to $params->limit
     * entries, or 200, the server's maximum, when that is null: not a bulk
     * fetch. Nothing is sent until the walk is iterated. $params->offset
     * starts it part of the way in. A failed page request throws
     * WalkInterruptedException, whose reachedOffset is where that page starts:
     * walk again with the same arguments and that offset to resume.
     *
     * It is not a snapshot. The server pages by offset, newest first, and has
     * no cursor, so each change to the resource's tags while the walk runs
     * logs an entry at the front of the list and makes the walk yield one
     * entry twice; a resume drifts the same way.
     *
     * @return \Generator<int, AuditLog, mixed, void>
     *
     * @throws \InvalidArgumentException when $params->offset is negative (nothing is sent)
     */
    public function walkAuditLogs(
        string $resourceType,
        string $resourceId,
        ?AuditLogListParams $params = null,
        ?RequestOptions $options = null,
    ): \Generator {
        return Walker::walk(
            $this->transport,
            Path::of('resources', $resourceType, $resourceId, 'audit-logs'),
            AuditLog::fromArray(...),
            $params?->toQuery() ?? [],
            $params?->offset,
            $options,
        );
    }
}
