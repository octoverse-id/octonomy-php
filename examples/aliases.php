<?php

declare(strict_types=1);

/**
 * Aliases: other names for a tag.
 *
 * Gives an application's `science-fiction` tag two aliases, `sci-fi` and `sf`,
 * reads them from the tag and by slug, renames one, and deletes it, which
 * deactivates it, before bringing it back. examples/resolution.php shows an
 * alias turning a slug into its tag.
 *
 *   vendor/bin/octonomy-dev-server run examples/aliases.php
 *
 * Against your own Octonomy, set OCTONOMY_BASE_URL, OCTONOMY_TOKEN,
 * OCTONOMY_TENANT_ID and OCTONOMY_APPLICATION_ID, as for the quickstart, with a
 * token holding tags:read and tags:write for that application.
 */

use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Model\TagAliasCreate;
use Octoverse\Octonomy\Model\TagAliasListParams;
use Octoverse\Octonomy\Model\TagAliasUpdate;
use Octoverse\Octonomy\Model\TagCreate;

require __DIR__ . '/support/bootstrap.php';

[$client, $application] = exampleClient('aliases');

try {
    $scienceFiction = ensureTag($client, new TagCreate(
        name: 'Science fiction',
        slug: 'science-fiction',
        type: 'genre',
        applicationId: $application,
    ));

    // An alias of an application's tag names the same application.
    ensureAlias($client, new TagAliasCreate(tagId: $scienceFiction->id, name: 'Sci-fi', slug: 'sci-fi', applicationId: $application));
    $sf = ensureAlias($client, new TagAliasCreate(tagId: $scienceFiction->id, name: 'SF', slug: 'sf', applicationId: $application));

    $aliases = $client->tags()->listAliases($scienceFiction->id);
    printf("%s is also: %s\n", $scienceFiction->slug, implode(', ', array_map(fn($alias) => $alias->slug, $aliases->data)));

    // From the other side: which tag does a slug stand for?
    $bySlug = $client->aliases()->list(new TagAliasListParams(applicationId: $application, slug: 'sf'))->data[0];
    $tag = $client->tags()->get($bySlug->tagId);
    printf("alias '%s' stands for tag '%s'\n\n", $bySlug->slug, $tag->slug);

    // An update changes only the fields it names. tagId would point the alias
    // at another tag.
    $sf = $client->aliases()->update($sf->id, new TagAliasUpdate(name: 'S.F.'));
    printf("renamed: %s is now '%s'\n", $sf->slug, $sf->name);

    // Delete deactivates. A tag's aliases list shows active ones unless asked
    // for the others.
    $client->aliases()->delete($sf->id);
    $active = $client->tags()->listAliases($scienceFiction->id);
    $inactive = $client->tags()->listAliases($scienceFiction->id, new TagAliasListParams(isActive: false));
    printf(
        "after delete: active %s; inactive %s\n",
        implode(', ', array_map(fn($alias) => $alias->slug, $active->data)),
        implode(', ', array_map(fn($alias) => $alias->slug, $inactive->data)),
    );

    $sf = $client->aliases()->update($sf->id, new TagAliasUpdate(isActive: true));
    printf("reactivated:  %s is_active %s\n", $sf->slug, var_export($sf->isActive, true));
} catch (OctonomyException $e) {
    exampleFail('aliases', 'Octonomy error: ' . $e->getMessage());
}
