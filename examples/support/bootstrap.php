<?php

declare(strict_types=1);

/**
 * What the examples share: the Composer autoloader, a Client built from the
 * environment, and find-or-create helpers.
 *
 * Each example shows one resource group, and this file keeps the setup out of
 * its way. The quickstart does not use it, so that it reads top to bottom as a
 * single file.
 *
 * Every example runs again against the server it already wrote to, so each one
 * leaves its rows as it found them, and creates the rows it needs through the
 * ensure*() helpers below: create, or on a 409 find the row an earlier run
 * created. Octonomy holds one active row per slug in each scope, so a conflict
 * means that row exists, and a deactivated row does not hold its slug.
 */

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagAlias;
use Octoverse\Octonomy\Model\TagAliasCreate;
use Octoverse\Octonomy\Model\TagAliasListParams;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyListParams;

// octonomy-dev-server has loaded the autoloader already. Run with php, an example
// finds it in a checkout (the SDK's own vendor/) or in a Composer install, where
// this file is vendor/octoverse-id/octonomy-php/examples/support/ and the
// autoloader is the project's.
foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../../autoload.php'] as $autoload) {
    if (!class_exists(Client::class) && is_file($autoload)) {
        require $autoload;
    }
}
if (!class_exists(Client::class)) {
    fwrite(STDERR, "examples: no Composer autoloader found: run composer install\n");
    exit(1);
}

/**
 * End the example with a message on stderr and exit status 1.
 */
function exampleFail(string $example, string $message): never
{
    fwrite(STDERR, "{$example}: {$message}\n");
    exit(1);
}

/**
 * Read the named environment variables, or end the example naming the first
 * one that is unset. There are no defaults: a guessed server or token would
 * fail as a connection or token error far from the real cause.
 *
 * @param list<string> $names
 *
 * @return array<string, string>
 */
function exampleEnv(string $example, array $names): array
{
    $env = [];
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value === false || $value === '') {
            exampleFail($example, "{$name} is not set. Run it through vendor/bin/octonomy-dev-server, which sets it, or set it for your own Octonomy.");
        }
        $env[$name] = $value;
    }

    return $env;
}

/**
 * A Client for the server in OCTONOMY_BASE_URL, OCTONOMY_TOKEN and
 * OCTONOMY_TENANT_ID, and the application in OCTONOMY_APPLICATION_ID. Its
 * writes are recorded in the audit log as made by "<example>-example".
 *
 * @return array{Client, string} the client and the application id
 */
function exampleClient(string $example): array
{
    $env = exampleEnv($example, ['OCTONOMY_BASE_URL', 'OCTONOMY_TOKEN', 'OCTONOMY_TENANT_ID', 'OCTONOMY_APPLICATION_ID']);
    $client = new Client([
        'base_url' => $env['OCTONOMY_BASE_URL'],
        'token' => $env['OCTONOMY_TOKEN'],
        'tenant_id' => $env['OCTONOMY_TENANT_ID'],
        'actor_id' => "{$example}-example",
    ]);

    return [$client, $env['OCTONOMY_APPLICATION_ID']];
}

/**
 * Create the vocabulary, or find the active one with its slug in the same
 * scope.
 */
function ensureVocabulary(Client $client, VocabularyCreate $vocabulary): Vocabulary
{
    try {
        return $client->vocabularies()->create($vocabulary);
    } catch (ConflictException $e) {
        // A vocabulary list has no slug filter, so walk the scope's active rows.
        $params = new VocabularyListParams(
            applicationId: $vocabulary->applicationId,
            includeShared: $vocabulary->applicationId === null ? null : false,
            isActive: true,
        );
        foreach ($client->vocabularies()->walk($params) as $found) {
            if ($found->slug === $vocabulary->slug && $found->applicationId === $vocabulary->applicationId) {
                return $found;
            }
        }
        throw $e;
    }
}

/**
 * Create the tag, or find the active one with its type and slug in the same
 * scope.
 */
function ensureTag(Client $client, TagCreate $tag): Tag
{
    try {
        return $client->tags()->create($tag);
    } catch (ConflictException $e) {
        // A list without an application holds every application's rows, and one
        // with an application holds the shared rows too unless includeShared is
        // false, so the scope is checked on each row. A shared row can sit past
        // the first page, behind applications' rows with the same slug, so the
        // walk reads on until it finds the row, and sends nothing more once it has.
        $params = new TagListParams(
            applicationId: $tag->applicationId,
            includeShared: $tag->applicationId === null ? null : false,
            isActive: true,
            slug: $tag->slug,
            type: $tag->type,
        );
        foreach ($client->tags()->walk($params) as $found) {
            if ($found->applicationId === $tag->applicationId) {
                return $found;
            }
        }
        throw $e;
    }
}

/**
 * Create the alias, or find the active one with its slug in the same scope.
 */
function ensureAlias(Client $client, TagAliasCreate $alias): TagAlias
{
    try {
        return $client->aliases()->create($alias);
    } catch (ConflictException $e) {
        // Walked for the same reason as in ensureTag().
        $params = new TagAliasListParams(
            applicationId: $alias->applicationId,
            includeShared: $alias->applicationId === null ? null : false,
            isActive: true,
            slug: $alias->slug,
        );
        foreach ($client->aliases()->walk($params) as $found) {
            if ($found->applicationId === $alias->applicationId) {
                return $found;
            }
        }
        throw $e;
    }
}
