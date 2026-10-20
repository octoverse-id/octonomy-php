# API mapping

How SDK methods map to Octonomy REST endpoints. The vendored [`openapi.yaml`](openapi.yaml) is the v1
contract (the v2 spec is #21); this page is the client-side view.

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
Omit it for the global (tenant-shared) partition. A namespaced request must also name its application
(`applicationId` on a list, `application_id` in a write body); the server refuses one that does not.

`includeGlobal` on `TagListParams` and `VocabularyListParams` (`include_global`) widens a namespaced
read to the global rows the token may also see.

`Tag::$usageCount` is the same field with two meanings: on v1 it counts the tag's assignments across
the tenant; on v2 only those the request can see, in its namespace (plus global ones the caller may see)
and within its application scope.

### v2-only features on a v1 client

A v1 client throws before sending the request:

| Request | Exception |
| ------- | --------- |
| any method with `RequestOptions(namespace: ...)` | `LogicException`: `octonomy: a namespace requires api_version v2; this client is v1` |
| a list with `includeGlobal` set (`true` or `false`) | `LogicException`: `octonomy: include_global requires api_version v2; this client is v1` |

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
| `vocabularies()->get()` | GET | `/vocabularies/{id}` |
| `vocabularies()->list()` | GET | `/vocabularies` |
| `vocabularies()->update()` | PATCH | `/vocabularies/{id}` |
| `vocabularies()->delete()` | DELETE | `/vocabularies/{id}` |
| `tags()->create()` | POST | `/tags` |
| `tags()->get()` | GET | `/tags/{id}` |
| `tags()->list()` | GET | `/tags` |
| `tags()->update()` | PATCH | `/tags/{id}` |
| `tags()->delete()` | DELETE | `/tags/{id}` |

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
| `validation_error`, `tenant_mismatch`, `application_mismatch`, `inactive_tag` | 400 | `ValidationException` |
| `authentication_required` | 401 | `AuthenticationException` |
| `forbidden` | 403 | `ForbiddenException` |
| `not_found` | 404 | `NotFoundException` |
| `conflict` | 409 | `ConflictException` |
| (other / no envelope) | any | `ApiException` |

Every exception exposes `$statusCode`, `$errorCode`, `$details`, and `$requestId`. The string codes are
available as constants on `Octoverse\Octonomy\ErrorCode`.

Two failures are not HTTP errors and do not extend `ApiException`:

| Condition | Exception |
| --------- | --------- |
| 2xx whose body lacks its envelope: a non-empty `data` object, or a `data` array of non-empty objects + a `pagination` object | `MalformedResponseException` |
| 2xx whose resource breaks the field set for the api_version: a declared key absent (nullable fields included), a value of the wrong JSON type, an unparseable timestamp, or the namespace pair missing on v2 / present on v1 | `MalformedResponseException`, with `$field` set to the field's path in the body (e.g. `data.usage_count`) |
| Network failure, timeout, or a non-JSON 2xx body from a method that returns data (`delete()` ignores any body) | `TransportException` |

## Not yet implemented

Tag aliases, tag resolution, tag assignments (incl. bulk), resource tags, audit logs, and health — see
[roadmap.md](roadmap.md).
