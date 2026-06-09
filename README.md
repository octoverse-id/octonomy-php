# Octonomy PHP SDK

[![CI](https://github.com/octoverse-id/octonomy-php/actions/workflows/ci.yml/badge.svg)](https://github.com/octoverse-id/octonomy-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

The official PHP client for [Octonomy](https://github.com/octoverse-id/octonomy) — a multi-tenant,
multi-application tag management and taxonomy service. A hand-written, Guzzle-based client for the
stable REST **v1** API.

> **Status:** `0.1.0` — early. The transport, auth, error, and pagination foundation plus the
> **Vocabularies** and **Tags** resources are implemented. The remaining resources are tracked in
> [`docs/roadmap.md`](docs/roadmap.md).

## Install

```bash
composer require octoverse-id/octonomy-php
```

Requires PHP 8.2+ and Guzzle 7.

## Quickstart

```php
<?php

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Model\TagCreate;

$client = new Client([
    'base_url'  => 'https://octonomy.example.com', // SDK appends /api/v1
    'token'     => 'svc_live_...',                 // Authorization: Bearer
    'tenant_id' => 'acme',                         // X-Tenant-ID
]);

try {
    $tag = $client->tags()->create(new TagCreate(name: 'Featured', slug: 'featured', type: 'label'));
    echo "created tag {$tag->id}\n";
} catch (ConflictException $e) {
    echo "a tag with this (type, slug) already exists\n";
}
```

A complete, runnable program lives in [`examples/quickstart.php`](examples/quickstart.php).

## Authentication and tenant scope

Every request carries credentials from the constructor options:

| Header | Source | Purpose |
| ------ | ------ | ------- |
| `Authorization: Bearer <token>` | `token` | Service token (scopes: `tags:read`, `tags:write`, `audit:read`) |
| `X-Tenant-ID` | `tenant_id` | Scopes every request to one tenant |
| `X-Actor-ID` *(optional)* | `actor_id` or `RequestOptions::withActor(...)` | Attributes mutations in the audit log |

```php
use Octoverse\Octonomy\Model\TagUpdate;
use Octoverse\Octonomy\RequestOptions;

$client->tags()->update($id, new TagUpdate(isActive: false), RequestOptions::withActor('svc-catalog'));
```

You can also pass a configured Guzzle client (e.g. with retries or a custom base) via the
`http_client` option.

## Errors

Non-2xx responses throw a typed exception extending `Octoverse\Octonomy\Exception\ApiException`, which
exposes `$statusCode`, `$errorCode`, `$details`, and `$requestId`:

```php
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\ValidationException;

try {
    $tag = $client->tags()->get($id);
} catch (NotFoundException $e) {
    // 404
} catch (ValidationException $e) {
    var_dump($e->details);
}
```

## Pagination

List methods return a `ResultList<T>` with `->data` (a list of models) and `->pagination`
(limit, offset, count, next, previous):

```php
use Octoverse\Octonomy\Model\TagListParams;

$page = $client->tags()->list(new TagListParams(type: 'label', limit: 50));
printf("%d of %d\n", count($page->data), $page->pagination->count);
```

## Implemented resources

| Resource | Status |
| -------- | ------ |
| Vocabularies (`$client->vocabularies()`) | ✅ create / get / list / update / delete |
| Tags (`$client->tags()`) | ✅ create / get / list / update / delete |
| Tag aliases, resolution, assignments (+bulk), resource tags, audit logs, health | 🚧 see [`docs/roadmap.md`](docs/roadmap.md) |

## Common commands

```bash
make test    # phpunit
make check   # php-cs-fixer + phpstan + phpunit
make help    # list all targets
```

## Documentation

- [Architecture](docs/architecture.md) — how the client is layered.
- [API mapping](docs/api.md) — SDK methods ↔ Octonomy endpoints, auth, scopes.
- [Development](docs/development.md) — setup, quality gates, testing.
- [Versioning](docs/versioning.md) — SemVer policy and which server contract this SDK targets.
- [Release](docs/release.md) — the release runbook.
- [Roadmap](docs/roadmap.md) — the backlog of remaining resources.
- [CHANGELOG](CHANGELOG.md)

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md), [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md), and
[SECURITY.md](SECURITY.md). The repository follows
[Conventional Branch](https://conventional-branch.github.io/) naming and Semantic Versioning.

## License

Apache License 2.0 — see [LICENSE](LICENSE).
