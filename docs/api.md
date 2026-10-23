# API mapping

How SDK methods map to Octonomy REST endpoints. The vendored specs, [`openapi.yaml`](openapi.yaml) (v1)
and [`openapi-v2.yaml`](openapi-v2.yaml) (v2), are the contract reference; read the known-divergence
note at the top of each before trusting a response shape in them. This page is the client-side view.

## Base URL and API version

The client targets `base_url + /api/v2`, or `base_url + /api/v1` when constructed with
`'api_version' => 'v1'` (`ApiVersion::V1`). The two surfaces carry the same paths; v2 adds the
namespace axis below. The version is read once, at construction, and cannot change afterwards or per
request. Any other value throws `ConfigurationException` when the client is built.

## Headers

Every request carries:

| Header | Source | Required |
| ------ | ------ | -------- |
| `Authorization: Bearer <token>` | `token` | yes |
| `X-Tenant-ID` | `tenant_id` | yes |
| `X-Actor-ID` | `actor_id` or `RequestOptions::withActor(...)` | no |
| `Accept: application/json` | always | — |
| `Content-Type: application/json` | requests with a body | — |
| `User-Agent` | `user_agent` (default `octonomy-php/<version>`) | — |
| `X-Namespace-Type`, `X-Namespace-ID` | `RequestOptions(namespace: new NamespaceScope($type, $id))` | no; v2 only |

## Namespaces (v2)

A `NamespaceScope` places one request in a merchant or sub-tenant namespace. It is an atomic pair:
a blank type or id throws `InvalidArgumentException`. It is set per request only, through
`RequestOptions`, never on `Config`, because the server enforces namespace grants and fails closed.
Omit it for the global (tenant-shared) partition. A namespaced request must also name its application,
or the server answers 403: `applicationId` on the list params and in the create body, and the
`$applicationId` argument of `get()`, `update()` and `delete()`, which is sent as the `application_id`
query parameter. On `update()` it scopes the request and cannot move the row: an application is fixed
at creation (`409 scope_immutable`).

`includeGlobal` (`include_global`) on `TagListParams`, `VocabularyListParams` and the `get()` methods
widens a namespaced read to the global rows the token may also see. Without it, a namespaced `get()`
of a global row answers 404.

`Tag::$usageCount` is the same field with two meanings: on v1 it counts the tag's assignments across
the tenant; on v2 only those the request can see, in its namespace (plus global ones the caller may see)
and within its application scope.

### v2-only features on a v1 client

A v1 client throws before sending the request:

| Request | Exception |
| ------- | --------- |
| any method with `RequestOptions(namespace: ...)` | `LogicException`: `octonomy: a namespace requires api_version v2; this client is v1` |
| a list or `get()` with `includeGlobal` set (`true` or `false`) | `LogicException`: `octonomy: include_global requires api_version v2; this client is v1` |

This is the SDK's one check against a server rule (an `AGENTS.md` exemption). `/api/v1` answers
namespace headers with `400 namespace_not_supported` and drops `include_global` without a word, so
the SDK refuses both up front.

## Scopes

Service tokens carry scopes enforced by the server: `tags:read`, `tags:write`, `audit:read`. Read
methods need `tags:read`; mutating methods need `tags:write`.

## Implemented

| SDK method | HTTP | Path |
| ---------- | ---- | ---- |
| `vocabularies()->create()` | POST | `/vocabularies` |
| `vocabularies()->get()` | GET | `/vocabularies/{id}` (`?application_id`, `?include_global`) |
| `vocabularies()->list()` | GET | `/vocabularies` |
| `vocabularies()->update()` | PATCH | `/vocabularies/{id}` (`?application_id`) |
| `vocabularies()->delete()` | DELETE | `/vocabularies/{id}` (`?application_id`) |
| `tags()->create()` | POST | `/tags` |
| `tags()->get()` | GET | `/tags/{id}` (`?application_id`, `?include_global`) |
| `tags()->list()` | GET | `/tags` |
| `tags()->update()` | PATCH | `/tags/{id}` (`?application_id`) |
| `tags()->delete()` | DELETE | `/tags/{id}` (`?application_id`) |

### List parameters

`TagListParams` exposes the full server filter set: `application_id`, `include_shared`, `is_active`,
`parent_id`, `q` (as `query`), `slug`, `type`, `vocabulary_id`, plus `limit`/`offset` and, on v2,
`include_global`. `VocabularyListParams` exposes `application_id`, `include_shared`, `is_active`,
paging, and, on v2, `include_global`. Boolean params are serialized as `"true"`/`"false"`.

## Responses

- **Single resource:** the model object (e.g. `Tag`), unwrapped from the server's `{ data: {...} }`.
- **List:** `ResultList<T>` = `{ data: T[], pagination: {limit, offset, count, next, previous} }`.
- **Delete:** no body (deactivation on the server).
- **Errors:** `{ error: {code, message, details, request_id} }` → a typed `ApiException` subclass.

## Error codes → exceptions

| Code | HTTP | Exception |
| ---- | ---- | --------- |
| `validation_error`, `tenant_mismatch`, `application_mismatch`, `inactive_tag`, `namespace_invalid` | 400 | `ValidationException` |
| `namespace_not_supported` | 400 | `UnsupportedApiVersionException` |
| `ambiguous_resolution` | 400 | `AmbiguousResolutionException` |
| `authentication_required` | 401 (403 from the server, which sends no challenge) | `AuthenticationException` |
| `forbidden` | 403 | `ForbiddenException` |
| `namespaced_writes_disabled` | 403 | `FeatureDisabledException` |
| `not_found` | 404 | `NotFoundException` |
| `conflict`, `scope_immutable` | 409 | `ConflictException` |
| `namespace_api_disabled` | 503 | `FeatureDisabledException` |
| another code, or no envelope | 400 / 401 / 403 / 404 / 409 | `Validation` / `Authentication` / `Forbidden` / `NotFound` / `Conflict` `Exception` |
| another code, or no envelope | any other, 503 included | `ApiException` |

The code decides before the status: a 403 is `ForbiddenException` for a token without the grant and
`FeatureDisabledException` for a deployment flag, so a caller tells them apart by type alone. A 503
without the envelope comes from a proxy or load balancer rather than the server's flag, and stays a
bare `ApiException`.

Every exception exposes `$statusCode`, `$errorCode`, `$details`, `$requestId`, `$serverMessage` (the
envelope's message as sent, `null` without one), and `$context`. The string codes, every one
`octonomy/core/errors.py` declares, are constants on `Octoverse\Octonomy\ErrorCode`.

### RequestContext

`ApiException`, `TransportException`, and a `MalformedResponseException` raised during a request carry
`$context`, a `RequestContext` describing that request, since the SDK never logs and the exception is the
only record of the call:

| Field | Example |
| ----- | ------- |
| `apiVersion` | `v2` |
| `method` | `GET` |
| `path` | `/api/v2/tags/7d1c...` (as sent, ids URL-encoded) |
| `query` | `['application_id' => 'commerce', 'include_global' => 'false']` |
| `namespace` | `NamespaceScope('merchant', 'm-42')`, or `null` for the global partition |

`endpoint()` returns `"GET /api/v2/tags/7d1c..."`. The context holds no token, tenant id, actor id, other
header, or request body.

### Messages

Every message the SDK writes follows one formula: what happened, why when the SDK actually knows, how to
fix it, and the values involved. For a non-2xx response the values are the request (method, path,
query, namespace), the code and status, the server's message and its `detail`, the names of the
`details` (never their values, which can quote the request), and the request id:

```text
octonomy: tag "7d1c..." was not found (not_found, HTTP 404). Request: GET
/api/v2/tags/7d1c...?application_id=commerce&include_global=false in namespace merchant:m-42. The
server does not say why, so the SDK cannot either. Fix: check that the request above, its id
included, is the one you meant to send. Server said: "Resource not found." Detail: "Tag was not
found." Request ID: req_...
```

- **Why, only when known.** A deployment flag (`namespaced_writes_disabled`, `namespace_api_disabled`)
  names the server setting, because the server sends that code for that flag alone. A 404 names the
  resource kind and the id, says the SDK cannot tell why, and asks you to check the request it shows:
  the server deliberately does not reveal whether the row exists outside what the request could see,
  so the message does not suggest it.
- **Unknown causes said so.** A `TransportException` quotes the HTTP client's error and says the SDK
  cannot tell from it why the request failed, or, for a write, whether the server received and applied
  it; when the client's exception holds a response, it names that status. A non-2xx without the error
  envelope, and a code this SDK release does not know, say the same.
- **Malformed responses too.** A `MalformedResponseException` names the endpoint, the api_version and
  the fault, says the SDK cannot tell which side is out of step, and asks you to check `base_url` and
  version support before reporting a contract mismatch.
- **No `doc_url`.** The fix is in the message.

### Redaction

No exception the SDK throws carries the token, the `Authorization` header, the request body, or
`X-Actor-ID` (`Internal\Redaction`):

- The SDK's own words never include them, and `RequestContext` has no field for them. Text a message
  quotes from elsewhere (the HTTP client's error; the server's message, detail, details' names, request
  id and Content-Type; a malformed field's value) has what the request carried replaced with
  `[redacted]` first, since a middleware or a server can echo the request. That is the token, the actor
  id, the body as the exact bytes sent (the SDK encodes it once, and snapshots all of these before the
  HTTP client runs), and every value and nested key in it, numbers as sent and `true`, `false` and
  `null` included. A value of eight characters or more is replaced wherever it appears, a shorter one only
  where it stands alone, so an actor id of `1` does not eat the digits of a port number. The body's
  top-level keys are the API's field names, which the SDK's own words use too, and stay.
  `$serverMessage` and `$details` stay as the server sent them: they are its data, not a message, and
  can echo what the request sent.
- The arguments of the SDK's own frames are removed from the trace of every `OctonomyException` and of
  the `LogicException` a v1 client throws, so an error tracker that records frame arguments does not
  capture the body from `TagService::create()` or the token from `Config::__construct()`. Frames outside
  the SDK keep theirs.
- Anything the HTTP client, a handler or middleware throws becomes a `TransportException` that quotes
  its error, redacted, and does not chain it as `getPrevious()`, because Guzzle's exception holds the
  request, `Authorization` header and body included. A non-JSON 2xx does not chain the
  `JsonException` either, since `json_decode()`'s frame in its trace holds the raw body; its message,
  PHP's own, is quoted.
- An injected client's own `json`, `form_params`, `multipart`, `body` or `auth` defaults are cancelled
  for SDK requests, so the body and `Authorization` header sent are the SDK's. A default `X-Actor-ID`
  it adds, when the request names none, is redacted like the SDK's own.
- A body that cannot be encoded as JSON is never sent. The `InvalidArgumentException` says why in
  fixed words, or names by class the exception an object's `jsonSerialize()` threw, and chains
  nothing.
- A header value holding a control character, such as a trailing newline, is refused without being
  quoted: as a `ConfigurationException` when a `Config` is built (token, tenant id, actor id, user
  agent), as an `InvalidArgumentException` when a `RequestOptions` or `NamespaceScope` is built, and
  again as an `InvalidArgumentException` by `Transport` just before sending (the PHP 7.4 line has no
  `readonly`). Guzzle would refuse it with an error that quotes the whole header, thrown from frames
  that hold the `Authorization` header and the body.

Two failures are not HTTP errors and do not extend `ApiException`:

| Condition | Exception |
| --------- | --------- |
| 2xx whose body lacks its envelope: a non-empty `data` object, or a `data` array of non-empty objects + a `pagination` object | `MalformedResponseException` |
| 2xx whose resource breaks the field set for the api_version: a declared key absent (nullable fields included), a value of the wrong JSON type, an unparseable timestamp, or the namespace pair missing on v2 / present on v1 | `MalformedResponseException`, with `$field` set to the field's path in the body (e.g. `data.usage_count`) |
| The HTTP client threw (network failure, timeout, or middleware that throws, possibly holding a response), or a non-JSON 2xx body from a method that returns data (`delete()` ignores any body) | `TransportException`, quoting the HTTP client's error rather than chaining it |

## Not yet implemented

Tag aliases, tag resolution, tag assignments (incl. bulk), resource tags, audit logs, and health — see
[roadmap.md](roadmap.md).
