# Octonomy PHP SDK

[![CI](https://github.com/octoverse-id/octonomy-php/actions/workflows/ci.yml/badge.svg)](https://github.com/octoverse-id/octonomy-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

The official PHP client for [Octonomy](https://github.com/octoverse-id/octonomy) — a multi-tenant,
multi-application tag management and taxonomy service. A hand-written, Guzzle-based client for the
Octonomy REST API: **`/api/v2`** by default, with merchant and sub-tenant namespaces, or `/api/v1` on
request.

> **Status:** unreleased and early. No version has been tagged or published to Packagist yet, so the
> install command below works once the first release ships. The transport, auth, error, and pagination
> foundation plus the **Vocabularies** and **Tags** resources are implemented. The remaining resources
> are tracked in [`docs/roadmap.md`](docs/roadmap.md).

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
    'base_url'  => 'https://octonomy.example.com', // SDK appends /api/v2
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

$client->tags()->update($id, new TagUpdate(isActive: false), options: RequestOptions::withActor('svc-catalog'));
```

You can also pass a configured Guzzle client (e.g. with retries or a custom base) via the
`http_client` option. Its transport options and middleware apply to every SDK request; its own default
headers, query and body do not, since the SDK builds each request itself. Add a header with middleware
(`Middleware::mapRequest()`) instead. Do not give it a default `auth`, `form_params` or `multipart`:
Guzzle would put them in place of the SDK's `Authorization` header or body, and its option types offer
no way for a request to cancel them.

## API version and namespaces

A client calls `/api/v2` unless you construct it with `'api_version' => 'v1'` (`ApiVersion::V1`),
which an Octonomy server older than 2.0.0 needs. The version is fixed when the client is built; build
another client to call the other surface. Any value other than `v1` or `v2` throws
`ConfigurationException` when the client is built.

On v2 a request can be placed in a merchant or sub-tenant namespace. The namespace is per request
only, never a client default, because the server enforces namespace grants and fails closed:

```php
use Octoverse\Octonomy\Model\TagListParams;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;

$merchant = new RequestOptions(namespace: new NamespaceScope('merchant', 'm-42'));

// X-Namespace-Type: merchant, X-Namespace-ID: m-42. A namespaced request must name its application.
$own = $client->tags()->list(new TagListParams(applicationId: 'commerce'), $merchant);

// The namespace's rows plus the global ones the token may see.
$all = $client->tags()->list(new TagListParams(applicationId: 'commerce', includeGlobal: true), $merchant);

// get(), update() and delete() take the application as an argument (the application_id query
// parameter); get() also takes includeGlobal, without which a global tag is a 404 here.
$tag = $client->tags()->get($id, applicationId: 'commerce', options: $merchant);
```

Without a namespace a request reads and writes the global (tenant-shared) partition. A
`NamespaceScope` needs both a type and an id. A tag's `usageCount` is tenant-wide on v1 and counts only
the assignments the request can see on v2.

A v1 client refuses a namespace or `includeGlobal` with a `LogicException` before sending anything:
`/api/v1` has no namespace axis, and would reject the headers or silently drop the parameter.

## Errors

Non-2xx responses throw a typed exception extending `Octoverse\Octonomy\Exception\ApiException`, which
exposes `$statusCode`, `$errorCode`, `$details`, `$requestId`, and `$serverMessage`:

```php
use Octoverse\Octonomy\Exception\FeatureDisabledException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\ValidationException;

try {
    $tag = $client->tags()->get($id);
} catch (NotFoundException $e) {
    // 404
} catch (ValidationException $e) {
    var_dump($e->details);
} catch (FeatureDisabledException $e) {
    // The deployment has namespaced writes (403) or the namespaced v2 API (503) switched off:
    // a server flag, not a missing grant, which is a ForbiddenException.
}
```

Every `ApiException` and `TransportException` also carries `$context`, a `RequestContext` naming the
request that failed: its api_version, method, path, query and namespace. Log it beside the message: a
namespaced 404 then records where the read looked and whether it asked for `include_global`, which a
status alone cannot (the server never reveals whether the id exists in another scope). It never holds
the token, the tenant or actor id, any other header, or the request body.

Every message says what happened and with which request, why when the SDK actually knows, and how to fix
it, and quotes the server's own message (kept as sent in `$serverMessage`):

```text
octonomy: namespaced writes are disabled on this Octonomy deployment (namespaced_writes_disabled, HTTP 403).
Request: POST /api/v2/tags in namespace merchant:globex. OCTONOMY_NAMESPACE_WRITE_ENABLED is off on the
server, as it is by default. Fix: set OCTONOMY_NAMESPACE_WRITE_ENABLED=true on the server, or omit the
namespace to write globally. Server said: "Namespaced writes are not enabled." Request ID: req_...
```

No exception the SDK throws carries the token, the `Authorization` header, the request body, or
`X-Actor-ID`: not in its message (text quoted from the HTTP client or the server has them replaced
with `[redacted]`), its context, or the arguments of the SDK's frames in its trace.

A 2xx whose body is missing the envelope the endpoint guarantees, or whose resource lacks a field,
carries one of the wrong type, or has an unparseable timestamp, throws `MalformedResponseException`
naming the endpoint and the field: the SDK never fills in a zero value or coerces one. A failure the
HTTP client throws (a network failure, a timeout, or middleware that throws), or a 2xx body that is not
JSON from a method that returns data, throws `TransportException` (`delete()` ignores any body). Every one of these failures
extends `Octoverse\Octonomy\Exception\OctonomyException`. A mistake in the call itself, caught before
anything is sent (a v2-only feature on a v1 client, a `NamespaceScope` with a blank type or id, a
per-request header value with a control character in it, a body that cannot be encoded as JSON),
throws PHP's `LogicException` or `InvalidArgumentException` instead: fix the call, there is nothing to
retry. A configured value a header cannot carry throws `ConfigurationException`.

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
