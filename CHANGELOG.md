# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

This is the changelog of the 1.x line, on `support/php7.4`; `main`'s is 2.x's. The line's first release,
`1.0.0`, carries everything below, generated for PHP 7.4 from `main` at `c1626d4` (#29): the classes and
methods 2.x has, on PHP 7.4+ and Guzzle 7, with mutable models, and without the named arguments PHP 7.4
lacks. After `1.0.0` this file records this line's own fixes.

### Added
- `Octoverse\Octonomy\Rector\FlagModelPropertyWriteRector`, an opt-in rule for Rector 2 that finds
  writes to model properties before a move from 1.x to 2.x, where they throw. Named in a project's own
  Rector configuration, it puts a `// TODO octonomy-php 2.x:` comment above each assignment,
  reference, increment, decrement or `unset()` aimed at a property that is readonly on 2.x, so
  `rector process --dry-run` lists them and exits non-zero while any is left. On this line it finds
  them through the `@readonly` docblocks, and nothing loads it otherwise, so Rector is not a
  dependency. `docs/upgrading-1-to-2.md` shows how to run it and what it misses (#33).
- The support policy for the two lines, in `docs/versioning.md`, `SECURITY.md` and the README: which
  line each PHP version installs, 1.x supported indefinitely, bounded in practice by Guzzle 7's support
  for PHP 7.4, and taking security fixes and major bug fixes only after its `1.0.0`. No runtime
  deprecation notice ships on either line. The README also states that 1.x needs Guzzle 7, and how to
  install it with Guzzle 6 locked through other packages (`composer require ... -W`) (#33).
- The 1.x line, for PHP 7.4 to 8.1, on the `support/php7.4` branch: this SDK generated once from the
  same source by Rector, with the same classes and methods, released as `1.x` beside `2.x`. Two
  differences reach your code: its models are mutable where 2.x's are `readonly`, and PHP 7.4 has no
  named arguments. `docs/upgrading-1-to-2.md` covers both and what else changes when you move to 2.x,
  and the README and the guide link it (#29).
- `docs/guide.md`, one path through the SDK: what Octonomy keeps, trying it with the dev server, a
  client, each resource group with the example that shows it, namespaces, pagination, errors, audit
  logs, health and webhooks, testing your code (`FixtureClient`, and a PSR-18 client of your own for
  error paths), wiring the client into Laravel and Symfony, and upgrading. The README starts with it.
  Its test and framework snippets were run in a Laravel 13 and a Symfony 8.1 application, and
  `GuideTest` fails when an example goes unlinked, a link in it loses its file or heading, or code
  appears outside those two sections (#27).
- Eight runnable examples beside the quickstart, one per resource group, each run with
  `vendor/bin/octonomy-dev-server run examples/<name>.php`: `vocabularies.php`, `tags.php` (a hierarchy,
  search, partial updates, delete as deactivation), `aliases.php`, `resolution.php` (a slug to its tag,
  through an alias, with an application's own tag overriding a shared one), `assignments.php` (assign
  by id, by alias and in bulk, then read both directions), `resource-tags.php` (`replaceTags()`),
  `audit-logs.php` (finding a change by your own request id) and `health.php`, which exits 1 when the
  server is not ready. Each runs again against the server it wrote to. CI's `dev-server` job runs all
  nine twice from a Composer install, PHPStan now checks `examples/` on `main` as it does on the 7.4
  line, and the README's resource table links each one (#27).
- `Testing\FixtureClient`, a PSR-18 client for testing your own code against the SDK without a server:
  `$fake = FixtureClient::withCapturedFixtures()`, passed as `http_client`, answers each request the SDK
  makes with the response a real Octonomy sent for that endpoint and api_version, from the captured
  corpus the package now ships in `resources/fixtures`. It matches by method and by path below
  `/api/{version}` or a health probe's, skipping a path on `base_url`, so `get('t1')` gets the captured
  tag whatever its id. `assertSent($method, $path)` checks that it answered such a request and returns
  it, or throws PHP's `\AssertionError` naming what it did answer. A request it has no response for
  throws `Testing\FixtureClientException`, a PSR-18 `ClientExceptionInterface` naming the method and
  path, which the SDK reports as a `TransportException` quoting it. So does a path that reads as two
  endpoints, which only a `base_url` ending in `/api/v1/resources` or `/api/v2/resources`, or a resource
  of type `api` and id `v1` or `v2`, produces: it names both readings rather than guess. It replays and nothing more: no
  state, no paging (a `walk*()` whose captured page says more follow stops with
  `WalkInterruptedException`), no api_version rules, no failures, and no way to hand it a response.
  CI's `dev-server` job loads it from a Composer install. The bodies change when the corpus is
  re-captured; its methods and the endpoints it answers are versioned API (#28).
- `Webhook::verify($secret, $signatureHeader, $rawBody)`, which checks the `X-Octonomy-Signature` on a
  webhook delivery (`Webhook::SIGNATURE_HEADER`): `sha256=` and the HMAC-SHA256 of the raw body,
  compared with `hash_equals()`. It returns false for anything the sender controls (no header, a
  malformed one, a mismatch) and throws `InvalidArgumentException` only for the caller's mistakes (an
  empty or non-string secret, a header that is not a string or null, a body that is not a string). Its
  parameters are declared `mixed` and checked inside, and what it throws goes through
  `Redaction::scrubAll()`, which removes the arguments of every frame, so the secret reaches no frame of
  its trace on either PHP line. (2.x marks it `#[\SensitiveParameter]` too; this line cannot carry the
  attribute, so the scrub is its whole protection.) Tested against
  octonomy-go's signature vectors, generated from the server's signing code and vendored under
  `tests/fixtures/webhook`. The README and `docs/api.md` say to reject a failed check with anything but a
  2xx, which the server takes as delivered for good (#26).
- `examples/quickstart.php` demonstrates namespace isolation: two merchants on one tenant each create
  a tag with the same slug, each reads back only its own row, and neither can read the other's by id
  (`NotFoundException`). It reads `OCTONOMY_APPLICATION_ID` beside the other three variables and
  refuses to run with any of them unset rather than guess a server, and a second run finds the tags the
  first created instead of failing on the conflict. The README's quickstart, its expected output, and
  the instructions for pointing it at your own Octonomy (a token that reaches both namespaces, and
  `OCTONOMY_NAMESPACE_WRITE_ENABLED`) match it (#22).
- `vendor/bin/octonomy-dev-server`, the package's Composer bin (`scripts/octonomy-dev-server`): a
  throwaway local Octonomy in Docker, so `vendor/bin/octonomy-dev-server run examples/quickstart.php`
  takes a project from `composer require` to the quickstart's output. `run <script>` boots the server
  unless it is running, hands the script the harness's `OCTONOMY_TEST_*` credentials as `OCTONOMY_*`
  (the harness's env file keeps its own names, so the integration suite's skip gate is unchanged), and
  loads the Composer autoloader from a project or a checkout before it; `up`, `down`, `logs` and `env`
  manage the server. It runs `scripts/octonomy-harness.sh` under its own container prefix and env file,
  never sharing a server with the read-only integration suite, and the harness gains a `status`
  command. There is one dev server per Docker daemon: in another project `run` and `up` refuse to
  replace it rather than destroy that project's data and leave it holding rejected tokens.
  `examples/quickstart.php` finds its autoloader in a Composer install as well as a checkout, and the
  README documents the Docker prerequisite, teardown, and pointing the quickstart at your own
  Octonomy (#13).
- `walk()` beside every list method (`walk()` on the tags, vocabularies, aliases and audit-log services,
  `tags()->walkAliases()`, `walkResources()` and `walkAuditLogs()`, `resources()->walkTags()` and
  `walkAuditLogs()`), taking the same arguments and returning a lazy `\Generator` over every page,
  keyed by each item's offset in the list. It sends one request per page, in sequence, at limit 200,
  the server's maximum, unless the params set one, rather than the server's default of 50. A failed
  page throws `WalkInterruptedException`, whose `reachedOffset` is the offset that page was asked for
  and whose `getPrevious()` is the failure, so the walk can resume from there. Offset drift under
  concurrent writes is documented, not solved: the server has no cursor, and before server 3.2.1 the
  tags list had no order at all (#25).
- Client foundation targeting the Octonomy REST API: `Client` with
  options-array/`Config` construction and validation, a Guzzle-backed `Transport` that sets
  `Authorization`, `X-Tenant-ID`, optional `X-Actor-ID`, `Accept`, and `User-Agent`, and an
  injectable `http_client`. Guzzle is the only runtime dependency that ships code.
- `api_version` option (`Config::$apiVersion`, `Client::apiVersion()`): the client calls **`/api/v2`**
  by default, or `/api/v1` with `'api_version' => 'v1'`, which current servers still serve and a server
  older than 2.0.0 needs. It is set at construction only and never per request, and any other value
  throws `ConfigurationException` when the client is built (#15).
- `NamespaceScope`, an atomic merchant/sub-tenant namespace type + id pair passed per request as
  `RequestOptions(namespace: ...)` and sent as `X-Namespace-Type` / `X-Namespace-ID`. There is no
  client-wide namespace. A blank type or id throws `InvalidArgumentException` (#15).
- `includeGlobal` on `TagListParams` and `VocabularyListParams` (`include_global`, v2), appended as the
  last parameter so no existing one moves (#15).
- `get()`, `update()` and `delete()` on both services take `?string $applicationId`, sent as the
  `application_id` query parameter that a namespaced call needs, and `get()` takes `?bool
  $includeGlobal`. Both come before `$options`, so a call that passed options positionally now names
  them (`options:`) (#15).
- A client-side guard: a namespace or `include_global` on a v1 client throws `LogicException` before
  any request is sent, the one server rule the SDK checks locally (`AGENTS.md` exemption).
  `Tag::$usageCount` is documented as tenant-wide on v1 and limited to what the request can see on v2
  (#15).
- Response envelopes: single-resource methods unwrap the server's `{"data": {...}}`, and list methods
  decode `{data, pagination}` into a generic `ResultList<T>` and `Pagination` (#6).
- Strict hydration: every field a model declares for the response's api_version must be present as a
  key, nullable fields included, and have its declared JSON type. A missing key, a wrong type (`"5"`
  for an integer, a list where an object is declared), or an unparseable timestamp throws `MalformedResponseException` naming the
  endpoint, the api_version and the field (`$field`, e.g. `data.usage_count`) instead of hydrating a
  zero value or coercing one. Tag's `parent_id` and `vocabulary_id`, which the server omits when null,
  are the named exception (#14).
- `ApiVersion::V1` / `ApiVersion::V2`, and the v2 namespace pair on `Tag` and `Vocabulary`
  (`namespaceType`, `namespaceId`; null on v1, which sees only the global partition). `fromArray()`
  takes the api_version the data was read as (#14).
- Typed exception hierarchy: `ApiException` (with `statusCode`, `errorCode`, `details`, `requestId`)
  and `NotFoundException`, `ConflictException`, `ValidationException`, `AuthenticationException`,
  `ForbiddenException`, decoded from the `{error:{code,message,details,request_id}}` envelope; plus
  `ConfigurationException`, `TransportException`, and `MalformedResponseException`, which is thrown
  when a 2xx body lacks its envelope instead of hydrating an empty model (#6).
- Error mapping for the v2 codes (#16): `FeatureDisabledException` for `namespaced_writes_disabled`
  (403) and `namespace_api_disabled` (503), so a deployment flag is no longer a `ForbiddenException`
  and an enveloped 503 is no longer a bare `ApiException`; `UnsupportedApiVersionException` for
  `namespace_not_supported` (400); `AmbiguousResolutionException` for `ambiguous_resolution` (400);
  `namespace_invalid` maps to `ValidationException` and `scope_immutable` to `ConflictException`.
  `ErrorCode` declares all 14 codes the server does, and `ApiException::classFor()` exposes the
  table. A 503 without the envelope stays a bare `ApiException`.
- `RequestContext` (api_version, method, path, query, namespace; no token, header or body) on every
  `ApiException` and `TransportException` as `$context`, and on a `MalformedResponseException` raised
  during a request (#16). `ApiException`'s and `TransportException`'s constructors take it as a
  required argument, and `MalformedResponseException`'s takes it in place of the endpoint string.
- Error messages written to one formula (#17): what happened, why when the SDK actually knows, the
  fix, and the values involved (the request, its namespace, the code, status, server message and
  request id). A deployment flag names the server setting to change; a 404 names the resource kind and
  id without suggesting where else it might be; a transport failure, an error without the envelope and
  an unknown code say that the SDK cannot tell why. `ApiException::$serverMessage` keeps the server's
  own message, unedited, which `getMessage()` now quotes rather than returns. A refused
  `Config::fromArray()` names the option keys it did not read.
- Redaction (#17): no exception carries the token, the `Authorization` header, the request body or
  `X-Actor-ID` in its message, its `RequestContext`, or the arguments of the SDK's frames in its trace,
  which `OctonomyException` removes on construction. Text a message quotes from the HTTP client or the
  server, a malformed field's value included, has the request's token, actor id and body (whole, and
  each value and nested key) replaced with `[redacted]`.
  `TransportException` covers anything the HTTP client throws and no longer chains it, since
  Guzzle's exception holds the request. The SDK encodes the body itself, once, and builds each request
  itself, sending it with an injected client's own default headers, query, body, `json` and `auth`
  cancelled (its transport options and middleware still apply; a client must not set a default
  `form_params` or `multipart`), so what it sends is what it describes and redacts; a body that cannot be encoded throws `InvalidArgumentException` without quoting it. A non-JSON 2xx no longer chains
  the `JsonException`. A token, tenant
  id, actor id, user agent or namespace with a control character is refused up front, and again just
  before sending, without being quoted.
- `VocabularyService`: create, get, list, update, delete.
- `TagService`: create, get, list (full filter set), update, delete.
- `RequestOptions::withActor()`, response models (`readonly` on 2.x; on this line writable, each
  property marked `@readonly` in its docblock), and `*Create`/`*Update`/`*ListParams` input DTOs.
- Tri-state `TagUpdate` and `VocabularyUpdate` (#20). Every string field defaults to the new
  `Undefined::VALUE`, which omits it, so an explicit `null` is now sent: `new TagUpdate(description:
  null)` sends `{"description": null}` and clears the description. A null used to be dropped: beside
  another field the server answered 200 with the description unchanged, and on its own it left an
  empty body the server refused. `metadata` and `isActive`, which the server cannot set to null, still
  omit a `null`.
- Empty JSON objects (#46). An update with nothing set sends `{}` rather than `[]`, which the server
  refused with 400, and an empty `metadata` on any create or update DTO is sent as `{}` rather than
  `[]`, so `metadata: []` now empties the stored object and a model's empty `metadata` round-trips.
- Metadata round trips (#48). A model's `metadata` keeps each nested JSON object as a `\stdClass`
  instead of converting it to an array, so read its members as properties
  (`$tag->metadata['attrs']->size`). A nested `{}` or `{"0": "a"}` read from a model used to go back
  out as the list `[]` or `["a"]`, and the server stored the list without an error. Every create and
  update DTO now sends `metadata` as a JSON object whatever its keys, so metadata stored as
  `{"0": "a"}`, which reads as `[0 => 'a']`, is no longer sent as a list the server refuses.
- Any PSR-18 client as `http_client` (#18), such as Symfony's `Psr18Client`, where only a Guzzle
  client was accepted; no adapter class is involved. A Guzzle client is still sent each request through
  `send()` with its own defaults cancelled, since its PSR-18 `sendRequest()` would put its default
  `auth`, query and body in place of the SDK's; any other client gets `sendRequest()`. `timeout` is now
  `?float`, null by default, and sets only the client the SDK builds (30 seconds when omitted): given
  together with `http_client`, which used to drop it without a word, it throws
  `ConfigurationException`. Requests are built with PSR-17 factories, Guzzle's unless
  `request_factory` and `stream_factory` are given. `psr/http-client`, `psr/http-factory` and
  `psr/http-message` are now required directly; they hold interfaces only.
- `X-Request-ID` (#19): `RequestOptions::withRequestId()`, or `RequestOptions(requestId: ...)` beside
  an actor or a namespace, sends the caller's own id for one call. The server records it in place of
  the `req_<uuid>` it would mint: in the audit row of a mutation, the outbox event and its webhook, its
  request log, and the error envelope (`ApiException::$requestId`). The SDK never generates an id, so
  without one no header is sent, and there is no `Config` field for one. An id the server would record
  changed (blank, outside printable ASCII, or with a space at either end) throws
  `InvalidArgumentException` before sending.
- The remaining resource groups (#23), each on both api_versions with the v2 namespace pair:
  - `aliases()` (`TagAliasService`): create, get, list, update and delete (deactivation) tag aliases,
    with `TagAlias`, `TagAliasCreate`, a tri-state `TagAliasUpdate` and `TagAliasListParams`; and
    `tags()->listAliases()` for one tag's.
  - `tags()->resolve($slug, ...)`: resolves a slug to the tag it names, its own or an alias's, as a
    `TagResolution` (`matchedType`, `matchedAlias`, `tag`), with `type`, `scope` and `include_global`.
  - `assignments()` (`AssignmentService`): `create()`, with `AssignmentCreate::forTag()`,
    `forAliasId()` and `forAliasSlug()`, one per way to name the tag; `remove()` (`AssignmentRemove`);
    and `bulkAssign()` / `bulkRemove()`, which return the server's real summary objects,
    `BulkAssignResult` and `BulkRemoveResult`, not the list the spec declares. Re-assigning returns
    the existing assignment.
  - `resources()` (`ResourceService`): `listTags()` (`ResourceTag` rows, each with its `Tag`),
    `replaceTags()` (`ResourceTagsReplace`, whose `tagIds` is required so clearing is never a default,
    returning `ResourceTagsReplaceResult`) and `listAuditLogs()`; and `tags()->listResources()`
    (`TagResource` rows).
  - `auditLogs()` (`AuditLogService`): `list()`, and `tags()->listAuditLogs()`, all three taking
    `AuditLogListParams`. `AuditLog::$changes` keeps each snapshot's objects as `\stdClass`.
  - The bulk and replace DTOs send their tag ids and alias slugs as JSON lists whatever the array's
    keys, so an array with gaps from `array_filter()` is not sent as an object.
  - A model nested in another (a resolution's tag, a bulk result's assignments) is read strictly, and a
    field it breaks is reported at its path, such as `data.assignments[2].tag_id`.
  - A 404 on `/tag-aliases/{id}` names the tag alias it asked for.
- `Internal\Path::of()` builds every request path (#23): each id percent-encoded as one segment, in
  place of the six inline `rawurlencode()` calls. An id of `.` or `..` is sent as `%2E` or `%2E%2E`,
  where `rawurlencode()` left it for libcurl to remove as a dot segment, which sent the request to
  another endpoint. An empty id is still sent as it is, and the server's 404 throws `NotFoundException`.
  libcurl 8.13.0 and later removes `%2E` and `%2E%2E` segments too, so the client the SDK builds sets
  `CURLOPT_PATH_AS_IS` on each cURL handle and sends the path as written (#64). Guzzle 8 refuses that
  option as a `curl` request option, so a handle factory sets it. A caller's own `http_client` has to
  set it itself (README).
- The health probes (#24): `health()->live()` and `health()->ready()` (`HealthService`), returning a
  `HealthStatus` with `ok` and the server's `status` word. They are sent to `base_url` itself, outside
  `/api/{version}`, with no token, tenant, actor, namespace or request id header, by a
  `HealthTransport` of their own, so `Transport` keeps reading every 2xx through its envelope check.
  - `Client::health()` for a caller with a `Client`, and `HealthClient` (with `HealthConfig`) for one
    without credentials, built from `base_url` alone; a `Client`'s options build one too.
  - The 503 `/health/ready` answers when the server cannot reach its database returns a `HealthStatus`
    with `ok` false rather than throwing. A 503 without the probe's `{"status": ...}` body, and every
    other non-2xx, throws `ApiException`; no response throws `TransportException`.
  - A 2xx is read strictly: a body that is not a JSON object with a string `status` throws
    `MalformedResponseException`.
  - `RequestContext::$apiVersion` is null for a probe, which calls no api_version.
  - `make fixtures` on `main` captures the probes, the 503 with the harness database stopped
    (`scripts/octonomy-harness.sh stop-db`); this line carries that corpus.
- Runnable `examples/quickstart.php`, and the vendored contract references `docs/openapi.yaml` (v1) and
  `docs/openapi-v2.yaml` (v2), both the server's own files at tag `v3.2.1`, each with a header listing
  where it diverges from what the server sends (#21).

[Unreleased]: https://github.com/octoverse-id/octonomy-php/commits/support/php7.4
