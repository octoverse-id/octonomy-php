<?php

declare(strict_types=1);

/**
 * Assignments: putting tags on your own resources.
 *
 * Tags a book, named `book/9780441013593` the way an application names its own
 * rows: by tag id, through an alias, then several at once. Then it reads the
 * links both ways, the book's tags and the tag's books, and removes them, so
 * the book ends untagged as it began.
 *
 *   vendor/bin/octonomy-dev-server run examples/assignments.php
 *
 * Against your own Octonomy, set OCTONOMY_BASE_URL, OCTONOMY_TOKEN,
 * OCTONOMY_TENANT_ID and OCTONOMY_APPLICATION_ID, as for the quickstart, with a
 * token holding tags:read and tags:write for that application.
 */

use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Model\AssignmentCreate;
use Octoverse\Octonomy\Model\AssignmentRemove;
use Octoverse\Octonomy\Model\BulkAssign;
use Octoverse\Octonomy\Model\BulkRemove;
use Octoverse\Octonomy\Model\TagAliasCreate;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagResourceListParams;

require __DIR__ . '/support/bootstrap.php';

[$client, $application] = exampleClient('assignments');

// Octonomy stores no books: a resource is a type and an id that mean something
// to your application, and an assignment always belongs to an application.
$type = 'book';
$isbn = '9780441013593';

try {
    $staffPick = ensureTag($client, new TagCreate('Staff pick', 'staff-pick', 'label', $application));
    $awardWinner = ensureTag($client, new TagCreate('Award winner', 'award-winner', 'label', $application));
    ensureAlias($client, new TagAliasCreate($staffPick->id, "Editor's choice", 'editors-choice', $application));

    // By tag id. assignedBy is free text recorded on the assignment.
    $assignment = $client->assignments()->create(
        AssignmentCreate::forTag($application, $type, $isbn, $staffPick->id, 'assignments-example'),
    );
    printf("%s/%s tagged '%s'\n", $type, $isbn, $staffPick->slug);

    // A tag the resource already has is not an error: the server answers 200
    // with the existing assignment.
    $again = $client->assignments()->create(AssignmentCreate::forTag($application, $type, $isbn, $staffPick->id));
    printf("  again, by id: %s\n", $again->id === $assignment->id ? 'the same assignment back' : 'a second assignment');

    // An alias assigns the tag it stands for.
    $viaAlias = $client->assignments()->create(AssignmentCreate::forAliasSlug($application, $type, $isbn, 'editors-choice'));
    printf(
        "  again, by alias '%s': %s\n",
        'editors-choice',
        $viaAlias->id === $assignment->id ? "the same assignment back, of tag '{$staffPick->slug}'" : 'a second assignment',
    );

    // Several at once, by id and by alias slug, with a count of what changed.
    $bulk = $client->assignments()->bulkAssign(new BulkAssign($application, $type, $isbn, [$awardWinner->id], ['editors-choice']));
    printf("  in bulk, two more: %d created, %d already there\n\n", $bulk->created, $bulk->existing);

    // From the resource: its tags, each with the assignment that put it there.
    $tags = $client->resources()->listTags($type, $isbn, $application);
    printf("%s/%s has: %s\n", $type, $isbn, implode(', ', array_map(fn ($row) => $row->tag->slug, $tags->data)));

    // From the tag: the resources that carry it.
    $books = $client->tags()->listResources($staffPick->id, new TagResourceListParams($application, $type));
    printf("'%s' is on: %s\n\n", $staffPick->slug, implode(', ', array_map(fn ($row) => "{$row->resourceType}/{$row->resourceId}", $books->data)));

    // Removing a tag the resource does not have is not an error either.
    $client->assignments()->remove(new AssignmentRemove($application, $type, $isbn, $awardWinner->id));
    $removed = $client->assignments()->bulkRemove(new BulkRemove($application, $type, $isbn, [$staffPick->id, $awardWinner->id]));
    printf("removed '%s', then %d more in bulk: %d tags left\n", $awardWinner->slug, $removed->removed, count($client->resources()->listTags($type, $isbn, $application)->data));
} catch (OctonomyException $e) {
    exampleFail('assignments', 'Octonomy error: ' . $e->getMessage());
}
