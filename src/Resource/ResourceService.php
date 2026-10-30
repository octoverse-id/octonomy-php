<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
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
}
