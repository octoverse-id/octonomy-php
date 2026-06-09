# Octonomy PHP SDK — Agent Instructions

`octonomy-php` is the official PHP client SDK for [Octonomy](https://github.com/octoverse-id/octonomy),
a multi-tenant, multi-application tag management / taxonomy service. The SDK is a hand-written,
Guzzle-based client for the stable REST **v1** API (`/api/v1`).

## Product Rules

These mirror server semantics the client must respect — business rules live on the server; the SDK
stays a faithful, ergonomic client.

- The SDK adds ergonomics, not behavior. Do not encode server-side validation or invariants here.
- Every request is tenant-scoped via the `X-Tenant-ID` header; `tenant_id` is required config.
- `application_id` is optional on tags and vocabularies (`null` = shared across the tenant) and is
  required for assignments.
- Tag deletion is **deactivation** on the server, not a hard delete. `delete()` methods call HTTP
  `DELETE` and must document the deactivation semantics rather than implying data loss.
- Tag aliases are alternate identifiers that resolve to canonical tags and follow tenant/application
  compatibility rules.
- Keep the SDK faithful to `docs/openapi.yaml`, the bundled contract reference. Where the live server
  diverges from the generated spec — notably the `{data, pagination}` list envelope the spec omits —
  trust the server's real behavior and document the divergence in a comment.

## API Client Rules

- One file per resource service (`Resource/TagService.php`, …), reached from an accessor on `Client`.
- Methods take required inputs first and an optional `?RequestOptions $options = null` last.
- List methods return `ResultList<T>` decoded from the `{data, pagination}` envelope.
- Non-2xx responses become typed exceptions extending `ApiException` (`NotFoundException`,
  `ConflictException`, `ValidationException`, `AuthenticationException`, `ForbiddenException`). Callers
  catch these; the SDK never returns error codes.
- Response models are immutable `readonly` DTOs hydrated via `::fromArray()`. Input DTOs use nullable
  promoted properties + `toArray()` that omits nulls so PATCH sends only what the caller set.
- No new public class or method without a doc comment and tests.

## PHP Conventions

- Target PHP **8.2+**. `declare(strict_types=1)` in every file. PSR-4 autoloading, PSR-12 / `@PER-CS`
  style (enforced by php-cs-fixer).
- Guzzle (`guzzlehttp/guzzle`) is the **only** runtime dependency. Do not add others without a strong
  reason; dev tools (phpunit, phpstan, php-cs-fixer) are require-dev.
- Keep the tree php-cs-fixer-clean and PHPStan-clean at the configured level (`phpstan.neon.dist`).
- The library throws typed exceptions; it never `echo`/`var_dump`/`die`s or writes logs.
- When changing a non-obvious mapping or semantic, add a comment explaining why.

## Testing Expectations

- PHPUnit tests using Guzzle's `MockHandler` (see `tests/SdkTestCase.php`). Assert the request
  method, path, auth headers (`Authorization`, `X-Tenant-ID`), query params, and JSON body; assert the
  decoded model/`ResultList` on the response side.
- Cover success paths, the `{data, pagination}` list envelope, and error→exception mapping
  (404 → `NotFoundException`, 409 → `ConflictException`, 400 → `ValidationException`).
- Use PHPUnit attributes (`#[DataProvider]`, …), not doc-comment annotations.

## Local Development

- Run `make check` before pushing and `make release-check` before a release.
- Keep the README quickstart, `examples/quickstart.php`, and `composer.json`/`Makefile` scripts current
  with the public API.
- Refresh the vendored `docs/openapi.yaml` from the Octonomy server when targeting a new contract, and
  record the server version it tracks in `docs/versioning.md`.

## Development Pipeline

- Branch names must follow Conventional Branch naming from
  https://conventional-branch.github.io/.
- Use `<type>/<description>` with lowercase alphanumerics, hyphens, and dots only where valid.
- Allowed branch types are `feature`, `feat`, `bugfix`, `fix`, `hotfix`, `release`, and `chore`.
- Example branch names: `feature/tag-assignments`, `fix/pagination-decode`, `chore/update-agent-rules`.
- When the user explicitly asks to implement an approved development plan, such as
  `PLEASE IMPLEMENT THIS PLAN`, create a GitHub issue before creating the development branch.
- If the user provides an existing issue number, use that issue instead of creating a duplicate.
- New plan-tracking issues must include the plan summary, key implementation tasks, and acceptance
  checks.
- If GitHub issue creation fails, stop and report the blocker instead of implementing untracked work.
- Planned-development branches must include the issue number using
  `<type>/<issue-number>-<short-description>`, for example `feature/12-tag-assignments`.
- PR bodies for planned development must include `Closes #<issue-number>` and summarize how the
  implementation maps back to the approved plan.
- Releases follow Semantic Versioning: cut them with the runbook in `docs/release.md` and the policy
  in `docs/versioning.md`. The SDK version lives in `Client::VERSION` and is published as a git tag
  `vX.Y.Z`. Version bumps and tags happen only in a dedicated `release/<version>` PR, never in feature
  or fix PRs.
- The `code-review/` directory is reserved for local code review pipeline artifacts.
- Review agents must write findings to `code-review/findings.md`.
- Patch agents must read `code-review/findings.md`, apply valid fixes, and write the patch summary to
  `code-review/patches.md`.
- Agents must never stage or commit `code-review/findings.md`, `code-review/patches.md`, or any other
  generated review artifact.
- After creating a PR, remove all local files under `code-review/` except the tracked
  `code-review/.gitkeep` placeholder.

## Web Browsing

- Use the `/browse` skill from gstack for all web browsing.
- Do not use `mcp__claude-in-chrome__*` tools.
