<?php

declare(strict_types=1);

/**
 * Quickstart for the Octonomy PHP SDK: namespace isolation.
 *
 * Two merchants on one tenant each create a tag with the same slug. Both rows
 * live side by side, each merchant reads back only its own, and neither can
 * reach the other's even by id. That partitioning is what Octonomy does that a
 * tags table and a join do not.
 *
 * Run it against a throwaway local Octonomy, booted in Docker for you, from a
 * project that requires the SDK:
 *
 *   vendor/bin/octonomy-dev-server run examples/quickstart.php
 *
 * (scripts/octonomy-dev-server in a checkout of the SDK), or against your own
 * Octonomy, as the README's "Point at your own Octonomy" describes:
 *
 *   OCTONOMY_BASE_URL=https://octonomy.example.com \
 *   OCTONOMY_TOKEN=svc_... \
 *   OCTONOMY_TENANT_ID=marketplace \
 *   OCTONOMY_APPLICATION_ID=storefront \
 *   php vendor/octoverse-id/octonomy-php/examples/quickstart.php
 *
 * It runs again against the same server: a tag that is already there, an
 * earlier run's or your own, is found rather than created twice.
 */

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;

// octonomy-dev-server has loaded the autoloader already. Run with php, this file
// finds it in a checkout (the SDK's own vendor/) or in a Composer install, where
// it is vendor/octoverse-id/octonomy-php/examples/ and the autoloader is the
// project's.
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (!class_exists(Client::class) && is_file($autoload)) {
        require $autoload;
    }
}
if (!class_exists(Client::class)) {
    fwrite(STDERR, "quickstart: no Composer autoloader found: run composer install\n");
    exit(1);
}

// No defaults: a guessed server or token would fail as a connection or token
// error far from the real cause, which is a variable left unset.
$env = [];
foreach (['OCTONOMY_BASE_URL', 'OCTONOMY_TOKEN', 'OCTONOMY_TENANT_ID', 'OCTONOMY_APPLICATION_ID'] as $name) {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "quickstart: {$name} is not set. Run it through vendor/bin/octonomy-dev-server, which sets all four, or set them for your own Octonomy.\n");
        exit(1);
    }
    $env[$name] = $value;
}

$client = new Client([
    'base_url' => $env['OCTONOMY_BASE_URL'],
    'token' => $env['OCTONOMY_TOKEN'],
    'tenant_id' => $env['OCTONOMY_TENANT_ID'],
    'actor_id' => 'quickstart-example',
]);
// A namespaced request names its application: the server refuses one that does not.
$application = $env['OCTONOMY_APPLICATION_ID'];

// A namespace is chosen per request, never for the whole client.
function merchant(string $id): RequestOptions
{
    return new RequestOptions(namespace: new NamespaceScope('merchant', $id));
}

/**
 * Create the merchant's `featured` tag, or find the one already there, such as
 * an earlier run's: a slug is taken once per merchant, so a second create there
 * is a 409. Only an active tag holds a slug, so that is the one looked up.
 */
function featuredTag(Client $client, string $application, string $merchant): Tag
{
    try {
        $tag = $client->tags()->create(
            new TagCreate(name: 'Featured', slug: 'featured', type: 'label', applicationId: $application),
            merchant($merchant),
        );
        $how = 'created';
    } catch (ConflictException $e) {
        $found = $client->tags()->list(
            new TagListParams(applicationId: $application, isActive: true, slug: 'featured', type: 'label'),
            merchant($merchant),
        )->data;
        if ($found === []) {
            throw $e;
        }
        $tag = $found[0];
        $how = 'already there';
    }
    printf("%-16s -> tag '%s' %s\n", "merchant:{$merchant}", $tag->slug, $how);

    return $tag;
}

try {
    // The same slug under two merchants: two rows, no conflict.
    $acme = featuredTag($client, $application, 'acme');
    featuredTag($client, $application, 'globex');
    echo "   same slug, both live, no conflict\n\n";

    // Each merchant reads back its own row, and only that: the label comes from
    // the namespace the server says the row is in.
    foreach (['acme', 'globex'] as $merchant) {
        $page = $client->tags()->list(new TagListParams(applicationId: $application, slug: 'featured'), merchant($merchant));
        foreach ($page->data as $tag) {
            printf("reading as %-16s -> %s  (%s's)\n", "merchant:{$merchant}", $tag->slug, $tag->namespaceId);
        }
    }

    // Not even by id: as far as globex can tell, acme's tag does not exist.
    try {
        $client->tags()->get($acme->id, applicationId: $application, options: merchant('globex'));
        fwrite(STDERR, "quickstart: merchant:globex read merchant:acme's tag by id, so this server is not isolating namespaces\n");
        exit(1);
    } catch (NotFoundException $e) {
        printf("\nacme's tag by id, as merchant:globex -> NotFoundException (HTTP %d)\n", $e->statusCode);
    }
} catch (OctonomyException $e) {
    fwrite(STDERR, 'Octonomy error: ' . $e->getMessage() . "\n");
    exit(1);
}
