<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Http\Walker;
use Octoverse\Octonomy\Model\AuditLog;
use Octoverse\Octonomy\Model\AuditLogListParams;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * AuditLogService accesses /audit-logs, the tenant's append-only mutation
 * history. Reach it via Client::auditLogs(). The entries about one tag or one
 * resource are also listed by TagService::listAuditLogs() and
 * ResourceService::listAuditLogs(), and walked by their walkAuditLogs(). Every
 * call needs a token with the audit:read scope.
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

    /**
     * Walk every audit log entry $params selects (GET /audit-logs), yielding
     * each AuditLog keyed by its offset in the list, newest first.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence: it is the loop over list()
     * you did not have to write, not a bulk fetch. A page holds up to
     * $params->limit entries, or 200, the server's maximum, when that is null,
     * so 10,000 entries take 50 requests. Nothing is sent until the walk is
     * iterated, and each page only once the one before it is used up.
     * $params->offset starts the walk part of the way in.
     *
     * A failed page request throws WalkInterruptedException, whose
     * reachedOffset is where that page starts: walk again with the same params
     * and that offset to resume.
     *
     * It is not a snapshot, and the log moves more than any other list. The
     * server pages by offset, newest first, and has no cursor, so every write
     * the tenant makes while the walk runs logs an entry at the front of the
     * list, moves every entry after it by one, and makes the walk yield one
     * entry twice; a resume drifts the same way. Narrow the walk with filters,
     * de-duplicate on id, or walk while nothing writes.
     *
     * @return \Generator<int, AuditLog, mixed, void>
     *
     * @throws \InvalidArgumentException when $params->offset is negative (nothing is sent)
     */
    public function walk(?AuditLogListParams $params = null, ?RequestOptions $options = null): \Generator
    {
        return Walker::walk(
            $this->transport,
            'audit-logs',
            AuditLog::fromArray(...),
            $params?->toQuery() ?? [],
            $params?->offset,
            $options,
        );
    }
}
