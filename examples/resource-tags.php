<?php

declare(strict_types=1);

/**
 * Resource tags: a resource's whole set of tags at once.
 *
 * Sets the formats a book is sold in with resources()->replaceTags(), which
 * leaves the book with exactly the tags it names, then changes and lists them.
 * A deactivated tag stays on the book but leaves its list until asked for.
 * Replacing with no tags clears the book, so it ends as it began.
 *
 *   vendor/bin/octonomy-dev-server run examples/resource-tags.php
 *
 * Against your own Octonomy, set OCTONOMY_BASE_URL, OCTONOMY_TOKEN,
 * OCTONOMY_TENANT_ID and OCTONOMY_APPLICATION_ID, as for the quickstart, with a
 * token holding tags:read and tags:write for that application.
 */

use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Model\ResourceTagListParams;
use Octoverse\Octonomy\Model\ResourceTagsReplace;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\ResultList;

require __DIR__ . '/support/bootstrap.php';

[$client, $application] = exampleClient('resource-tags');

$type = 'book';
$isbn = '9780141439518';

/**
 * The slugs on a page of a resource's tags, or "none".
 *
 * @param ResultList<\Octoverse\Octonomy\Model\ResourceTag> $page
 */
function resourceTagSlugs(ResultList $page): string
{
    return $page->data === [] ? 'none' : implode(', ', array_map(fn($row) => $row->tag->slug, $page->data));
}

try {
    $hardcover = ensureTag($client, new TagCreate(name: 'Hardcover', slug: 'hardcover', type: 'format', applicationId: $application));
    $paperback = ensureTag($client, new TagCreate(name: 'Paperback', slug: 'paperback', type: 'format', applicationId: $application));
    $signed = ensureTag($client, new TagCreate(name: 'Signed copy', slug: 'signed-copy', type: 'edition', applicationId: $application));

    // Exactly these tags afterwards: the server adds what is missing and removes
    // the rest, and says how many of each.
    $result = $client->resources()->replaceTags($type, $isbn, new ResourceTagsReplace($application, [$hardcover->id, $signed->id]));
    printf("replace with hardcover, signed-copy: %d added, %d removed\n", $result->created, $result->removed);
    $result = $client->resources()->replaceTags($type, $isbn, new ResourceTagsReplace($application, [$paperback->id, $signed->id]));
    printf("replace with paperback, signed-copy: %d added, %d removed\n\n", $result->created, $result->removed);

    // listTags needs the application, and takes filters such as a tag type.
    printf("%s/%s:         %s\n", $type, $isbn, resourceTagSlugs($client->resources()->listTags($type, $isbn, $application)));
    $formats = $client->resources()->listTags($type, $isbn, $application, new ResourceTagListParams(type: 'format'));
    printf("formats only:               %s\n\n", resourceTagSlugs($formats));

    // Deactivating a tag keeps its assignments, and hides them from the list.
    $client->tags()->delete($signed->id);
    printf("signed-copy deactivated:    %s\n", resourceTagSlugs($client->resources()->listTags($type, $isbn, $application)));
    $all = $client->resources()->listTags($type, $isbn, $application, new ResourceTagListParams(includeInactive: true));
    printf("with includeInactive:       %s\n", resourceTagSlugs($all));
    $client->tags()->update($signed->id, new TagUpdate(isActive: true));

    // An empty list is how to clear a resource: never a default.
    $result = $client->resources()->replaceTags($type, $isbn, new ResourceTagsReplace($application, []));
    printf("\nreplace with nothing: %d removed, %s left\n", $result->removed, resourceTagSlugs($client->resources()->listTags($type, $isbn, $application)));
} catch (OctonomyException $e) {
    exampleFail('resource-tags', 'Octonomy error: ' . $e->getMessage());
}
