<?php

declare(strict_types=1);

/**
 * Quickstart for the Octonomy PHP SDK: configure a client, create a vocabulary
 * and a tag, list tags, and handle a typed error.
 *
 * Run it against a local Octonomy instance:
 *
 *   OCTONOMY_BASE_URL=http://localhost:8000 \
 *   OCTONOMY_TOKEN=svc_... \
 *   OCTONOMY_TENANT_ID=acme \
 *   php examples/quickstart.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\VocabularyCreate;

function env(string $key, string $fallback): string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $fallback : $value;
}

$client = new Client([
    'base_url' => env('OCTONOMY_BASE_URL', 'http://localhost:8000'),
    'token' => env('OCTONOMY_TOKEN', 'svc_local_dev'),
    'tenant_id' => env('OCTONOMY_TENANT_ID', 'acme'),
    'actor_id' => 'quickstart-example',
]);

try {
    $vocab = $client->vocabularies()->create(new VocabularyCreate(name: 'Labels', slug: 'labels'));
    printf("created vocabulary %s (%s)\n", $vocab->name, $vocab->id);

    try {
        $tag = $client->tags()->create(new TagCreate(
            name: 'Featured',
            slug: 'featured',
            type: 'label',
            vocabularyId: $vocab->id,
            metadata: ['source' => 'quickstart'],
        ));
        printf("created tag %s (%s)\n", $tag->name, $tag->id);
    } catch (ConflictException) {
        echo "tag already exists; continuing\n";
    }

    $page = $client->tags()->list(new TagListParams(type: 'label', limit: 20));
    printf("tenant has %d label tag(s) on this page\n", count($page->data));
} catch (OctonomyException $e) {
    fwrite(STDERR, 'Octonomy error: ' . $e->getMessage() . "\n");
    exit(1);
}
