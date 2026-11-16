<?php

declare(strict_types=1);

/**
 * Vocabularies: the groups an application keeps its tags in.
 *
 * Creates an application's `formats` vocabulary, lists what the application
 * sees beside it, changes and clears its description, then deletes it, which
 * deactivates it rather than removing it, and brings it back.
 *
 *   vendor/bin/octonomy-dev-server run examples/vocabularies.php
 *
 * Against your own Octonomy, set OCTONOMY_BASE_URL, OCTONOMY_TOKEN,
 * OCTONOMY_TENANT_ID and OCTONOMY_APPLICATION_ID, as for the quickstart, with a
 * token holding tags:read and tags:write for that application.
 */

use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyListParams;
use Octoverse\Octonomy\Model\VocabularyUpdate;

require __DIR__ . '/support/bootstrap.php';

[$client, $application] = exampleClient('vocabularies');

try {
    // An application id makes the vocabulary the application's own. Without one
    // it would be shared by every application in the tenant.
    $formats = ensureVocabulary($client, new VocabularyCreate(
        name: 'Formats',
        slug: 'formats',
        applicationId: $application,
        description: 'How a book is published',
    ));
    printf("vocabulary '%s' belongs to application %s\n\n", $formats->slug, $formats->applicationId);

    // An application sees its own vocabularies and the tenant's shared ones.
    // includeShared: false keeps its own alone.
    echo "what {$application} sees:\n";
    foreach ($client->vocabularies()->walk(new VocabularyListParams(applicationId: $application, isActive: true)) as $vocabulary) {
        printf("  %-12s %s\n", $vocabulary->slug, $vocabulary->applicationId === null ? 'shared' : 'its own');
    }

    // An update changes only the fields it names. null clears a field, which
    // leaving the field out never does.
    $formats = $client->vocabularies()->update($formats->id, new VocabularyUpdate(description: 'Hardcover, paperback, ebook, audio'));
    printf("\ndescription set:     %s\n", $formats->description);
    $formats = $client->vocabularies()->update($formats->id, new VocabularyUpdate(description: null));
    printf("description cleared: %s, name still '%s'\n", json_encode($formats->description), $formats->name);

    // Delete deactivates: the row stays, readable by id, and leaves the active
    // lists. Its slug is free again for a new vocabulary.
    $client->vocabularies()->delete($formats->id);
    $formats = $client->vocabularies()->get($formats->id);
    printf("\nafter delete:     is_active %s\n", var_export($formats->isActive, true));

    // An update can make it active again, with the same id.
    $formats = $client->vocabularies()->update($formats->id, new VocabularyUpdate(isActive: true));
    printf("after reactivate: is_active %s\n", var_export($formats->isActive, true));
} catch (OctonomyException $e) {
    exampleFail('vocabularies', 'Octonomy error: ' . $e->getMessage());
}
