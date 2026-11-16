<?php

declare(strict_types=1);

/**
 * Audit logs: who changed what, and from which request.
 *
 * Changes a tag's description with a request id of its own, finds that change
 * in the tag's history by the id, then lists what this example's actor has
 * done across the tenant and what has happened to one book. Octonomy writes an
 * audit entry for every change; the SDK only reads them.
 *
 *   vendor/bin/octonomy-dev-server run examples/audit-logs.php
 *
 * Against your own Octonomy, set OCTONOMY_BASE_URL, OCTONOMY_TOKEN,
 * OCTONOMY_TENANT_ID and OCTONOMY_APPLICATION_ID, as for the quickstart, with a
 * token holding tags:read, tags:write and audit:read for that application.
 */

use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Model\AssignmentCreate;
use Octoverse\Octonomy\Model\AssignmentRemove;
use Octoverse\Octonomy\Model\AuditLogListParams;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\RequestOptions;

require __DIR__ . '/support/bootstrap.php';

// The client sends actor_id "audit-logs-example" on every request, and the
// server records it on each entry the request writes.
[$client, $application] = exampleClient('audit-logs');

try {
    $tag = ensureTag($client, new TagCreate(name: 'Banned Books Week', slug: 'banned-books-week', type: 'label', applicationId: $application));

    // Your own request id, such as the one your application logs, is recorded on
    // the entry, so a log line leads to the change it made. The SDK never makes
    // one up; without it the server mints req_<uuid>. An update that changes
    // nothing writes no entry, so this one names the request in the description.
    $requestId = 'audit-example-' . bin2hex(random_bytes(6));
    $client->tags()->update(
        $tag->id,
        new TagUpdate(description: "Last changed by request {$requestId}"),
        options: new RequestOptions(requestId: $requestId),
    );

    // A tag's history, newest first. The filters narrow it, here to updates.
    $history = $client->tags()->listAuditLogs($tag->id, new AuditLogListParams(action: 'tag.updated', limit: 10));
    $entry = null;
    foreach ($history->data as $candidate) {
        if ($candidate->requestId === $requestId) {
            $entry = $candidate;
        }
    }
    if ($entry === null) {
        exampleFail('audit-logs', "the update sent as request {$requestId} is not among {$tag->slug}'s ten newest updates");
    }
    printf("%s's history has request %s:\n", $tag->slug, $requestId);
    // changes holds a before and an after object, read like metadata: an array
    // at the top, a \stdClass below it.
    printf("  %s by %s\n", $entry->action, $entry->actorId);
    printf("  after: %s\n\n", json_encode($entry->changes['after'] ?? null));

    // Across the tenant, filtered by the actor. pagination->count is the size of
    // the whole list, not of this page.
    $byActor = $client->auditLogs()->list(new AuditLogListParams(actorId: 'audit-logs-example', limit: 3));
    printf("audit-logs-example has %d entries; the newest:\n", $byActor->pagination->count);
    foreach ($byActor->data as $entry) {
        printf("  %-24s %s %s\n", $entry->action, $entry->entityType, $entry->entityId);
    }

    // A resource's history: assignments are audited too.
    $client->assignments()->create(AssignmentCreate::forTag($application, 'book', '9780547928227', $tag->id));
    $client->assignments()->remove(new AssignmentRemove($application, 'book', '9780547928227', $tag->id));
    $book = $client->resources()->listAuditLogs('book', '9780547928227', new AuditLogListParams(limit: 2));
    echo "\nbook/9780547928227, newest first:\n";
    foreach ($book->data as $entry) {
        printf("  %-24s tag %s\n", $entry->action, $entry->tagId === $tag->id ? $tag->slug : $entry->tagId);
    }
} catch (OctonomyException $e) {
    exampleFail('audit-logs', 'Octonomy error: ' . $e->getMessage());
}
