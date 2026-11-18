# Guide

One path through the Octonomy PHP SDK, from trying it to wiring it into an application. Each step
says what Octonomy does, names the runnable example that shows it, and links the reference that has
every detail: the [README](../README.md) for how the SDK behaves, and [`api.md`](api.md) for how each
method maps to the REST API.

The examples are where the code is. Each one is a program that CI runs against a real Octonomy on
every change, so it cannot drift from the SDK the way a snippet on this page could. This page shows
code only where no example can: in tests and in framework configuration.

## 1. What Octonomy keeps for you

Octonomy keeps the tags of your own records: products, articles, books, anything. Your application
names each record by a type and an id, such as `book/9780441013593`, and Octonomy stores nothing else
about it. What it adds over a `tags` table and a join:

| Term | What it is |
| ---- | ---------- |
| **Tenant** | The top-level boundary. Every request names one (`X-Tenant-ID`), and nothing crosses tenants. |
| **Application** | A consumer within a tenant. A tag either belongs to one application or is shared by every application in the tenant. |
| **Namespace** | A partition within an application, such as one merchant on a marketplace. Two merchants can hold the same slug, and neither can read the other's rows. |
| **Vocabulary** | A named group of tags, such as `genres`. |
| **Tag** | A `type` and a `slug`, unique among a scope's active tags, with an optional parent, vocabulary and metadata. |
| **Alias** | Another slug for a tag, such as `sci-fi` for `science-fiction`. |
| **Assignment** | One tag on one resource, within an application. |
| **Audit log** | An entry for every change, with who made it and from which request. |

## 2. Try it

```bash
composer require octoverse-id/octonomy-php
vendor/bin/octonomy-dev-server run examples/quickstart.php
```

The second command boots a throwaway Octonomy in Docker, then runs
[`quickstart.php`](../examples/quickstart.php) against it: two merchants each create a `featured` tag,
and each reads back only its own. It needs Docker and curl, on macOS, Linux or WSL. The README's
[Run it against a local Octonomy](../README.md#run-it-against-a-local-octonomy) has the details, and
`vendor/bin/octonomy-dev-server down` removes the server.

Every example runs the same way, and runs again against the server it wrote to:

| Example | Shows |
| ------- | ----- |
| [`quickstart.php`](../examples/quickstart.php) | Namespace isolation: the same slug under two merchants |
| [`vocabularies.php`](../examples/vocabularies.php) | An application's own vocabulary beside the tenant's shared ones |
| [`tags.php`](../examples/tags.php) | A hierarchy, search, partial updates, and delete as deactivation |
| [`aliases.php`](../examples/aliases.php) | A tag's other names |
| [`resolution.php`](../examples/resolution.php) | From a slug to its tag, through an alias, with an application overriding a shared tag |
| [`assignments.php`](../examples/assignments.php) | Tagging a resource, then reading the links both ways |
| [`resource-tags.php`](../examples/resource-tags.php) | Setting a resource's whole set of tags at once |
| [`audit-logs.php`](../examples/audit-logs.php) | Finding a change by your own request id |
| [`health.php`](../examples/health.php) | The liveness and readiness probes, as a deploy check |

To run them against an Octonomy of your own, set the variables the README's
[Point at your own Octonomy](../README.md#point-at-your-own-octonomy) lists. Each example's first comment
says what its token needs.

## 3. A client

A `Client` takes three options: `base_url`, `token` and `tenant_id`. The examples build theirs from
the environment in `exampleClient()`, in [`examples/support/bootstrap.php`](../examples/support/bootstrap.php),
and the [quickstart](../examples/quickstart.php) builds its own at the top.

A client talks to one tenant. Build it once and share it: it holds no state of its own between calls,
and every per-call choice (a namespace, an actor, a request id) goes in that call's `RequestOptions`.
The application is not a client option either: pass it to each call that needs one.

The token is a service token with scopes: `tags:read` for reads, `tags:write` for changes, and
`audit:read` for the audit log ([scopes](api.md#scopes)). The README covers the
[other options](../README.md#authentication-and-tenant-scope), including `actor_id`, and how to pass
[your own HTTP client](../README.md#http-client).

## 4. Vocabularies and tags

Run [`vocabularies.php`](../examples/vocabularies.php) and [`tags.php`](../examples/tags.php). They show:

- **Shared or an application's own.** A row created with an `applicationId` belongs to that
  application. Without one it is shared by the whole tenant, and only a token that is not bound to one
  application can write it. A list for an application includes the shared rows unless `includeShared`
  is false.
- **Type and slug.** A tag's slug is unique per type among the active tags of its scope, so `mystery`
  can be both a `genre` and a `label`.
- **A hierarchy.** `parentId` puts a tag under another, and `vocabularyId` groups it.
- **Partial updates.** An update changes only the fields it names, and `null` clears one. `metadata`
  replaces the stored object as a whole. See [Partial updates](../README.md#partial-updates) and
  [update bodies](api.md#update-bodies).
- **Delete deactivates.** `delete()` keeps the row, readable by id, and removes it from the active
  lists; an update with `isActive: true` brings it back. A deactivated row does not hold its slug, so
  a new row can take it.

## 5. Aliases and resolution

An alias gives a tag another slug, and `tags()->resolve()` turns any slug into the tag it means. Run
[`aliases.php`](../examples/aliases.php) and [`resolution.php`](../examples/resolution.php).

`resolve()` returns a `TagResolution`: the tag, whether the slug matched the tag itself or an alias
(`matchedType`), and the alias when it was one. When more than one row has the slug, the most specific
wins:

1. A tag's own slug wins over an alias's.
2. The request's namespace wins over its application's global rows (v2).
3. The application's own rows win over the tenant's shared ones.

So an application can override a shared tag by creating its own with the same type and slug, which is
what `resolution.php` does. A slug that matches nothing active throws `ValidationException`. One that
matches tags of several types throws `ValidationException` naming `type`; pass it to choose.

## 6. Tagging your resources

Run [`assignments.php`](../examples/assignments.php) and
[`resource-tags.php`](../examples/resource-tags.php). Every assignment names its application.

- **Assign.** `assignments()->create()` takes an `AssignmentCreate` naming the tag by id, by alias id or
  by alias slug. Assigning a tag the resource already has returns the existing assignment rather than
  failing, so a retry is safe.
- **In bulk.** `bulkAssign()` and `bulkRemove()` take several tags at once and report counts.
- **Replace.** `resources()->replaceTags()` leaves a resource with exactly the tags it names; `[]`
  removes them all.
- **Read both ways.** `resources()->listTags()` lists a resource's tags, and `tags()->listResources()`
  the resources that carry a tag.
- **Remove.** `assignments()->remove()` succeeds whether or not the resource had the tag.

The README's [Tagging resources](../README.md#tagging-resources) and
[assignment bodies](api.md#assignment-bodies) have the details.

## 7. Namespaces

A namespace places one request in a partition, such as one merchant, and the
[quickstart](../examples/quickstart.php) shows what it isolates. It is a v2 feature, chosen per call:

- Pass `new RequestOptions(namespace: new NamespaceScope('merchant', $merchantId))` to the call.
  There is no client-wide namespace, because the server checks each request against the token's
  grants and a default would send the wrong one.
- A namespaced call must name its application, or the server answers 403.
- Without a namespace a call reads and writes the global partition. `includeGlobal: true` widens a
  namespaced read to the global rows the token may see.
- The server must allow it: namespaced writes are off by default (`FeatureDisabledException` says so),
  and the token needs a grant for the namespace (`ForbiddenException` without one).

See [API version and namespaces](../README.md#api-version-and-namespaces) and
[`api.md`](api.md#namespaces-v2). A client built with `'api_version' => 'v1'`, which a server older
than 2.0.0 needs, refuses a namespace before sending anything.

## 8. Lists and pagination

A list method returns one page, a `ResultList` with `data` and `pagination` (`count` is the size of the
whole list). Each has a `walk*()` beside it that reads every page lazily, one request per page, as
[`tags.php`](../examples/tags.php) does to print its tree.

A walk that fails partway throws `WalkInterruptedException`, whose `reachedOffset` is where to resume.
The server pages by offset, so a walk is not a snapshot: a row written during the walk can be yielded
twice or missed. See [Pagination](../README.md#pagination) and [walking a list](api.md#walking-a-list).

## 9. Errors

Every failure the SDK reports extends `OctonomyException`:

| Exception | When |
| --------- | ---- |
| `ApiException` and its subclasses: `NotFoundException`, `ConflictException`, `ValidationException`, `ForbiddenException`, `AuthenticationException`, `FeatureDisabledException`, … | The server answered with an error. `$errorCode`, `$details` and `$requestId` come from its response. |
| `TransportException` | No response: the server was unreachable, timed out, or the HTTP client threw. |
| `MalformedResponseException` | A 2xx whose body is missing a field or has one of the wrong type. The SDK never fills in a default. |

A mistake in the call itself, such as a namespace on a v1 client, throws PHP's `LogicException` or
`InvalidArgumentException` before anything is sent: fix the call rather than retry it. A bad option
throws `ConfigurationException` when the client is built.

Each message says what happened, why when the SDK knows, and how to fix it, and each exception's
`$context` names the request that failed. No exception carries the token or the request body. See
[Errors](../README.md#errors) and the [code-to-exception table](api.md#error-codes--exceptions).

## 10. Audit logs

Octonomy writes an audit entry for every change, and the SDK reads them. Run
[`audit-logs.php`](../examples/audit-logs.php): it sends a change with its own request id, then finds
that change in the tag's history by the id. Two options make the log useful:

- **`actor_id`** on the client, or `RequestOptions::withActor()` per call, records who made a change.
  In a web application the actor is usually the signed-in user, so pass it per call.
- **`requestId`** in `RequestOptions` records your own id, such as the trace id you log, in place of one
  the server mints. See [Request correlation](../README.md#request-correlation).

Reading the log needs the `audit:read` scope. `auditLogs()->list()` reads the whole tenant's, and
`tags()->listAuditLogs()` and `resources()->listAuditLogs()` one tag's or one resource's.

## 11. Health probes and webhooks

[`health.php`](../examples/health.php) asks the liveness and readiness probes through a `HealthClient`,
which needs only the base URL, and exits non-zero when the server is not ready. A server that answers
but is not ready returns `ok` false rather than throwing. See [Health probes](../README.md#health-probes).

An Octonomy set to deliver events by webhook signs each delivery. `Webhook::verify()` checks the
signature against the raw body; answer a delivery that fails it with anything but a 2xx. See
[Webhooks](../README.md#webhooks).

## 12. Testing your code

### Without a server

`Testing\FixtureClient` stands in for Octonomy in your tests. Pass it as the client's `http_client`,
and it answers each request with the response a real Octonomy sent for that endpoint, captured into
the package:

```php
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Testing\FixtureClient;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function testItReadsATag(): void
    {
        $fake = FixtureClient::withCapturedFixtures();
        $client = new Client([
            'base_url' => 'https://octonomy.test',
            'token' => 'test',
            'tenant_id' => 'test',
            'http_client' => $fake,
        ]);

        $this->assertSame('featured', $client->tags()->get('any-id')->slug);
        $fake->assertSent('GET', 'tags/any-id');
    }
}
```

It replays and nothing more: whatever id you ask for, the answer is that endpoint's one capture, a
write is not there to read back, and it never fails. `assertSent()` returns the request it answered,
for asserting its query, headers or body. See [Testing with FixtureClient](api.md#testing-with-fixtureclient)
for what it answers each endpoint with.

### Error paths

To drive your code's handling of an error, pass a PSR-18 client of your own that answers with one:

```php
use GuzzleHttp\Psr7\Response;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\NotFoundException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$notFound = new class implements ClientInterface {
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // The body a real Octonomy sends with a 404.
        return new Response(404, ['Content-Type' => 'application/json'], (string) json_encode(['error' => [
            'code' => 'not_found',
            'message' => 'Resource not found.',
            'details' => ['detail' => 'Tag was not found.'],
            'request_id' => 'req_test',
        ]]));
    }
};

$client = new Client(['base_url' => 'https://octonomy.test', 'token' => 'test', 'tenant_id' => 'test', 'http_client' => $notFound]);

$this->expectException(NotFoundException::class);
$client->tags()->get('missing');
```

Write error bodies like this one by hand if you must, but take success bodies from `FixtureClient`: a
body written from documentation agrees with its author rather than with the server.

## 13. In a framework

The SDK ships no framework package. A client is one object built from three settings, so bind it
once in the container and inject it where it is needed.

### Laravel

Add the settings to `config/services.php`, with their values in `.env`:

```php
'octonomy' => [
    'base_url' => env('OCTONOMY_BASE_URL'),
    'token' => env('OCTONOMY_TOKEN'),
    'tenant_id' => env('OCTONOMY_TENANT_ID'),
    'application_id' => env('OCTONOMY_APPLICATION_ID'),
],
```

Bind the client in `app/Providers/AppServiceProvider.php`:

```php
use Octoverse\Octonomy\Client;

public function register(): void
{
    $this->app->singleton(Client::class, fn () => new Client([
        'base_url' => config('services.octonomy.base_url'),
        'token' => config('services.octonomy.token'),
        'tenant_id' => config('services.octonomy.tenant_id'),
    ]));
}
```

Type-hint `Client` in a controller, job or command, and read the application with
`config('services.octonomy.application_id')`. A setting left unset throws `ConfigurationException`
naming it the first time the client is resolved. In a test, put a client on `FixtureClient` in its
place:

```php
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Testing\FixtureClient;

$fake = FixtureClient::withCapturedFixtures();
$this->app->instance(Client::class, new Client([
    'base_url' => 'https://octonomy.test',
    'token' => 'test',
    'tenant_id' => 'test',
    'http_client' => $fake,
]));
```

### Symfony

Register the client in `config/services.yaml`, with the values in `.env`:

```yaml
services:
    # ... the services your application already has

    Octoverse\Octonomy\Client:
        arguments:
            $config:
                base_url: '%env(OCTONOMY_BASE_URL)%'
                token: '%env(OCTONOMY_TOKEN)%'
                tenant_id: '%env(OCTONOMY_TENANT_ID)%'
```

Autowiring then injects it wherever a constructor asks for `Client`. To answer from `FixtureClient`
in the test environment, add to the same file:

```yaml
when@test:
    services:
        Octoverse\Octonomy\Testing\FixtureClient:
            factory: ['Octoverse\Octonomy\Testing\FixtureClient', 'withCapturedFixtures']
            public: true

        Octoverse\Octonomy\Client:
            arguments:
                $config:
                    base_url: 'https://octonomy.test'
                    token: 'test'
                    tenant_id: 'test'
                    http_client: '@Octoverse\Octonomy\Testing\FixtureClient'
```

A test then reads the fake with `static::getContainer()->get(FixtureClient::class)` to call
`assertSent()`.

## 14. Upgrading

The SDK follows Semantic Versioning, and [`versioning.md`](versioning.md) says how each kind of
change maps to a version. No version has been released yet. The first releases are planned as two
lines from one source: `2.x` for PHP 8.2 and later, and `1.x` for PHP 7.4 to 8.1, which Composer picks
by your PHP version ([before the first release](versioning.md#before-the-first-release)). Until then the
public API can change, and [`CHANGELOG.md`](../CHANGELOG.md) records every change that reaches you.
[Upgrading from 1.x to 2.x](upgrading-1-to-2.md) lists what differs between the lines: 1.x models are
mutable, and PHP 7.4 has no named arguments.

The server's API version is a separate choice: a client calls `/api/v2` unless built with
`'api_version' => 'v1'`, which a server older than 2.0.0 needs. Server releases that keep that
contract need no change in your code.
