<?php

declare(strict_types=1);

/**
 * Resolution: from a slug to the tag it means.
 *
 * The tenant shares a `thriller` tag, with the alias `suspense`, and one
 * application keeps its own `thriller`, with the alias `page-turner`.
 * tags()->resolve() turns each slug into a tag and says whether it matched a
 * tag or went through an alias. A tag's own slug wins over an alias's, and the
 * application's own row wins over the shared one, so an application can
 * override a tenant-wide default.
 *
 *   vendor/bin/octonomy-dev-server run examples/resolution.php
 *
 * Against your own Octonomy, set OCTONOMY_BASE_URL, OCTONOMY_TOKEN,
 * OCTONOMY_TENANT_ID and OCTONOMY_APPLICATION_ID, as for the quickstart. It
 * writes rows shared by the whole tenant, so its token needs tags:read and
 * tags:write and no --application: a token bound to one application cannot
 * write a shared row.
 */

use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\Model\TagAliasCreate;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagResolution;

require __DIR__ . '/support/bootstrap.php';

[$client, $application] = exampleClient('resolution');

try {
    // No application: shared by every application in the tenant.
    $shared = ensureTag($client, new TagCreate(name: 'Thriller', slug: 'thriller', type: 'genre'));
    ensureAlias($client, new TagAliasCreate(tagId: $shared->id, name: 'Suspense', slug: 'suspense'));

    // The same type and slug, as the application's own.
    $own = ensureTag($client, new TagCreate(name: 'Thrillers & crime', slug: 'thriller', type: 'genre', applicationId: $application));
    ensureAlias($client, new TagAliasCreate(tagId: $own->id, name: 'Page-turner', slug: 'page-turner', applicationId: $application));

    foreach ([['thriller', null], ['thriller', $application], ['suspense', $application], ['page-turner', $application]] as [$slug, $as]) {
        $resolution = $client->tags()->resolve($slug, applicationId: $as);
        $tag = $resolution->tag;
        // matchedType says what the slug matched, and matchedAlias is the alias
        // when it was one.
        $alias = $resolution->matchedAlias;
        printf(
            "%-13s as %-12s -> '%s' (%s)%s\n",
            "'{$slug}'",
            $as ?? 'the tenant',
            $tag->name,
            $tag->applicationId === null ? 'shared' : "{$tag->applicationId}'s own",
            $resolution->matchedType === TagResolution::MATCHED_ALIAS && $alias !== null ? ", through alias '{$alias->slug}'" : '',
        );
    }

    // A slug that matches nothing active is a 400, with the reason under the
    // field it is about.
    try {
        $client->tags()->resolve('space-opera', applicationId: $application);
    } catch (ValidationException $e) {
        printf("\n'space-opera' -> ValidationException (HTTP %d): %s\n", $e->statusCode, $e->details['slug'][0] ?? $e->serverMessage);
    }
} catch (OctonomyException $e) {
    exampleFail('resolution', 'Octonomy error: ' . $e->getMessage());
}
