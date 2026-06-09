# Roadmap

The foundation (transport, auth, exceptions, pagination) and the **Vocabularies** and **Tags**
resources are implemented. The resources below are queued for future work. Each is a self-contained
unit that follows the established pattern.

## How to add a resource (the recipe)

Copy `src/Resource/TagService.php` (+ its models and `tests/Resource/TagServiceTest.php`) as the
template, then:

1. Read the matching schema(s) in [`openapi.yaml`](openapi.yaml).
2. Add `Model/<Resource>.php` (readonly, `::fromArray()`), the `*Create`/`*Update` input DTOs
   (`toArray()` omitting nulls), and any `*ListParams` (`toQuery()`).
3. Add `Resource/<Resource>Service.php` with methods that take inputs first and
   `?RequestOptions $options = null` last, delegating to `Transport::request`.
4. Wire an accessor onto `Client`.
5. Add `MockHandler`-based tests (assert method/path/headers/query/body; cover the error envelope),
   a `## [Unreleased]` CHANGELOG entry, and update [`api.md`](api.md).

Each item below is a good single GitHub issue (`feature/<n>-<slug>`).

## Tag aliases

Alternate identifiers that resolve to a canonical tag. Schemas: `TagAlias`, `TagAliasWrite`,
`PatchedTagAliasPatch`.

- `aliases()->create()` → POST `/tag-aliases`
- `aliases()->get()` → GET `/tag-aliases/{alias_id}`
- `aliases()->list()` → GET `/tag-aliases`
- `aliases()->update()` → PATCH `/tag-aliases/{alias_id}`
- `aliases()->delete()` → DELETE `/tag-aliases/{alias_id}`
- `tags()->listAliases()` → GET `/tags/{tag_id}/aliases`

## Tag resolution

Resolve a slug (optionally within an application) to a tag, possibly via an alias. Schema:
`TagResolution`.

- `tags()->resolve($slug, ...)` → GET `/tag-resolution?slug={slug}&application_id={app}` returning
  `{matched_type, matched_alias, tag}`.

## Tag assignments

Link tags to external resources; idempotent writes (re-assigning returns 200, not 201). Schemas:
`Assignment`, `AssignmentWrite`, `BulkAssign`, `BulkRemove`.

- `assignments()->create()` → POST `/tag-assignments`
- `assignments()->remove()` → DELETE `/tag-assignments`
- `assignments()->bulkAssign()` → POST `/tag-assignments/bulk-assign`
- `assignments()->bulkRemove()` → POST `/tag-assignments/bulk-remove`

Note: assignment writes accept `tag_id`, `alias_id`, or `alias_slug`. Watch for the
`application_mismatch` and `inactive_tag` error codes (already defined on `ErrorCode`).

## Resource tags

Read or replace the full tag set on a resource. Schemas: `ResourceTag`, `ResourceReplace`,
`TagResource`.

- `resources()->listTags($type, $id)` → GET `/resources/{resource_type}/{resource_id}/tags`
- `resources()->replaceTags($type, $id, ...)` → POST `/resources/{resource_type}/{resource_id}/tags`
- `tags()->listResources()` → GET `/tags/{tag_id}/resources`

## Audit logs

Append-only mutation history (needs the `audit:read` scope). Schema: `AuditLog`.

- `auditLogs()->list()` → GET `/audit-logs` (with filters)
- `tags()->listAuditLogs()` → GET `/tags/{tag_id}/audit-logs`
- `resources()->listAuditLogs($type, $id)` → GET `/resources/{resource_type}/{resource_id}/audit-logs`

## Health

Unauthenticated liveness/readiness probes. These live **outside** `/api/v1`, so they need a small
transport variant (or a dedicated method) that skips the prefix and auth headers.

- `health()->live()` → GET `/health/live`
- `health()->ready()` → GET `/health/ready`
