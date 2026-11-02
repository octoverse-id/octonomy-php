<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Model\AuditLog;
use Octoverse\Octonomy\Model\AuditLogListParams;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * AuditLogService accesses /audit-logs, the tenant's append-only mutation
 * history. Reach it via Client::auditLogs(). The entries about one tag or one
 * resource are also listed by TagService::listAuditLogs() and
 * ResourceService::listAuditLogs(). Every call needs a token with the
 * audit:read scope.
 *
 * A call inside a namespace (RequestOptions::$namespace, api_version v2) must
 * name its application in the params. The server refuses a namespaced call
 * without one (403).
 */
final class AuditLogService
{
    /**
     * Built by Client::auditLogs(), over the client's one Transport; reach the
     * service there rather than constructing it.
     */
    public function __construct(private readonly Transport $transport) {}

    /**
     * List a page of audit log entries (GET /audit-logs), newest first.
     *
     * @return ResultList<AuditLog>
     */
    public function list(?AuditLogListParams $params = null, ?RequestOptions $options = null): ResultList
    {
        return $this->transport->many(
            'GET',
            'audit-logs',
            AuditLog::fromArray(...),
            $params?->toQuery() ?? [],
            null,
            $options,
        );
    }
}
