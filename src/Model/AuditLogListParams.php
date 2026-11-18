<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

/**
 * AuditLogListParams filters and pages audit log entries. All fields are
 * optional; a null field is omitted from the query string. Each filter keeps
 * the entries whose field equals it, and filters combine.
 *
 * The same params serve auditLogs()->list() and the two lists already narrowed
 * to one tag, tags()->listAuditLogs(), or one resource,
 * resources()->listAuditLogs(); the server applies every filter on all three.
 * On those two, $tagId, or $resourceType and $resourceId, narrow further: a
 * value other than the one the method names matches nothing.
 *
 * $applicationId keeps the entries of one application, and scopes the request
 * to it, which a namespaced read needs. $includeGlobal (api_version v2 only)
 * asks a namespaced read to return the global (tenant-shared) entries the
 * caller may see as well as the namespace's own. The server fails closed: a
 * grant without global authority still sees none. A v1 client refuses the
 * request before sending it, because /api/v1 would drop the parameter without
 * a word.
 */
final class AuditLogListParams
{
    /**
     * @readonly
     */
    public ?string $applicationId = null;
    /**
     * @readonly
     */
    public ?string $action = null;
    /**
     * @readonly
     */
    public ?string $entityType = null;
    /**
     * @readonly
     */
    public ?string $entityId = null;
    /**
     * @readonly
     */
    public ?string $tagId = null;
    /**
     * @readonly
     */
    public ?string $resourceType = null;
    /**
     * @readonly
     */
    public ?string $resourceId = null;
    /**
     * @readonly
     */
    public ?string $actorId = null;
    /**
     * @readonly
     */
    public ?string $operationId = null;
    /**
     * @readonly
     */
    public ?int $limit = null;
    /**
     * @readonly
     */
    public ?int $offset = null;
    /**
     * @readonly
     */
    public ?bool $includeGlobal = null;
    /**
     * Every argument is optional and sets the filter of the same name; null
     * leaves it out of the query string.
     */
    public function __construct(?string $applicationId = null, ?string $action = null, ?string $entityType = null, ?string $entityId = null, ?string $tagId = null, ?string $resourceType = null, ?string $resourceId = null, ?string $actorId = null, ?string $operationId = null, ?int $limit = null, ?int $offset = null, ?bool $includeGlobal = null)
    {
        $this->applicationId = $applicationId;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->tagId = $tagId;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->actorId = $actorId;
        $this->operationId = $operationId;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->includeGlobal = $includeGlobal;
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        if ($this->applicationId !== null) {
            $query['application_id'] = $this->applicationId;
        }
        if ($this->action !== null) {
            $query['action'] = $this->action;
        }
        if ($this->entityType !== null) {
            $query['entity_type'] = $this->entityType;
        }
        if ($this->entityId !== null) {
            $query['entity_id'] = $this->entityId;
        }
        if ($this->tagId !== null) {
            $query['tag_id'] = $this->tagId;
        }
        if ($this->resourceType !== null) {
            $query['resource_type'] = $this->resourceType;
        }
        if ($this->resourceId !== null) {
            $query['resource_id'] = $this->resourceId;
        }
        if ($this->actorId !== null) {
            $query['actor_id'] = $this->actorId;
        }
        if ($this->operationId !== null) {
            $query['operation_id'] = $this->operationId;
        }
        if ($this->limit !== null) {
            $query['limit'] = (string) $this->limit;
        }
        if ($this->offset !== null) {
            $query['offset'] = (string) $this->offset;
        }
        if ($this->includeGlobal !== null) {
            $query['include_global'] = $this->includeGlobal ? 'true' : 'false';
        }

        return $query;
    }
}
