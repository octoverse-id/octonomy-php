# API mapping

How SDK methods map to Octonomy REST **v1** endpoints. The authoritative contract is the vendored
[`openapi.yaml`](openapi.yaml); this page is the client-side view.

## Base URL and headers

The client targets `base_url + /api/v1`. Every request carries:

| Header | Source | Required |
| ------ | ------ | -------- |
| `Authorization: Bearer <token>` | `token` | yes |
| `X-Tenant-ID` | `tenant_id` | yes |
| `X-Actor-ID` | `actor_id` or `RequestOptions::withActor(...)` | no |
| `Accept: application/json` | always | — |
| `Content-Type: application/json` | requests with a body | — |
| `User-Agent` | `user_agent` (default `octonomy-php/<version>`) | — |

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
`parent_id`, `q` (as `query`), `slug`, `type`, `vocabulary_id`, plus `limit`/`offset`.
`VocabularyListParams` exposes `application_id`, `include_shared`, `is_active`, and paging. Boolean
params are serialized as `"true"`/`"false"`.

## Responses

- **Single resource:** the model object (e.g. `Tag`).
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

## Not yet implemented

Tag aliases, tag resolution, tag assignments (incl. bulk), resource tags, audit logs, and health — see
[roadmap.md](roadmap.md).
