<?php

declare(strict_types=1);

/**
 * Tags: a hierarchy of genres in a vocabulary.
 *
 * Creates `fiction`, `mystery` under it and `cozy-mystery` under that, in an
 * application's `genres` vocabulary. Then it prints the tree, searches it,
 * changes a tag's description and metadata, and deletes a tag, which
 * deactivates it, before bringing it back.
 *
 *   vendor/bin/octonomy-dev-server run examples/tags.php
 *
 * Against your own Octonomy, set OCTONOMY_BASE_URL, OCTONOMY_TOKEN,
 * OCTONOMY_TENANT_ID and OCTONOMY_APPLICATION_ID, as for the quickstart, with a
 * token holding tags:read and tags:write for that application.
 */

use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\Model\VocabularyCreate;

require __DIR__ . '/support/bootstrap.php';

[$client, $application] = exampleClient('tags');

try {
    $genres = ensureVocabulary($client, new VocabularyCreate(name: 'Genres', slug: 'genres', applicationId: $application));

    // A tag has a type, and its slug is unique per type among the scope's
    // active tags. parentId builds the hierarchy.
    $fiction = ensureTag($client, new TagCreate(
        name: 'Fiction',
        slug: 'fiction',
        type: 'genre',
        applicationId: $application,
        vocabularyId: $genres->id,
    ));
    $mystery = ensureTag($client, new TagCreate(
        name: 'Mystery',
        slug: 'mystery',
        type: 'genre',
        applicationId: $application,
        parentId: $fiction->id,
        vocabularyId: $genres->id,
        metadata: ['shelf' => 'B2'],
    ));
    $cozy = ensureTag($client, new TagCreate(
        name: 'Cozy mystery',
        slug: 'cozy-mystery',
        type: 'genre',
        applicationId: $application,
        parentId: $mystery->id,
        vocabularyId: $genres->id,
    ));

    // walk() reads every page of the list, so the tree is whole however many
    // tags the vocabulary holds.
    $children = [];
    foreach ($client->tags()->walk(new TagListParams(applicationId: $application, isActive: true, vocabularyId: $genres->id)) as $tag) {
        $children[$tag->parentId ?? ''][] = $tag;
    }
    $printTree = function (string $parentId, int $depth) use (&$printTree, $children): void {
        foreach ($children[$parentId] ?? [] as $tag) {
            printf("%s%s\n", str_repeat('  ', $depth), $tag->slug);
            $printTree($tag->id, $depth + 1);
        }
    };
    echo "the {$genres->slug} vocabulary:\n";
    $printTree('', 1);

    // query searches names and slugs.
    $found = $client->tags()->list(new TagListParams(applicationId: $application, query: 'myst', type: 'genre'));
    printf("\nsearching 'myst': %s\n", implode(', ', array_map(fn($tag) => $tag->slug, $found->data)));

    // An update changes only the fields it names. metadata replaces the stored
    // object as a whole, and null clears a field.
    $mystery = $client->tags()->update($mystery->id, new TagUpdate(
        description: 'Whodunits and detective fiction',
        metadata: ['shelf' => 'B3', 'staff_pick' => true],
    ));
    printf("\n%s: %s, metadata %s\n", $mystery->slug, $mystery->description, json_encode($mystery->metadata));
    $mystery = $client->tags()->update($mystery->id, new TagUpdate(description: null));
    printf("%s: description %s, metadata kept %s\n", $mystery->slug, json_encode($mystery->description), json_encode($mystery->metadata));

    // Delete deactivates: the tag stays, readable by id, and leaves the active
    // lists. Its slug is free again for a new tag.
    $client->tags()->delete($cozy->id);
    $cozy = $client->tags()->get($cozy->id);
    $active = $client->tags()->list(new TagListParams(applicationId: $application, isActive: true, parentId: $mystery->id));
    printf("\nafter delete: %s is_active %s; %s has %d active children\n", $cozy->slug, var_export($cozy->isActive, true), $mystery->slug, count($active->data));

    $cozy = $client->tags()->update($cozy->id, new TagUpdate(isActive: true));
    printf("reactivated:  %s is_active %s, the same id\n", $cozy->slug, var_export($cozy->isActive, true));
} catch (OctonomyException $e) {
    exampleFail('tags', 'Octonomy error: ' . $e->getMessage());
}
