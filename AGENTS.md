# Octonomy PHP SDK — Agent Instructions

`octonomy-php` is the official PHP client SDK for [Octonomy](https://github.com/octoverse-id/octonomy),
a multi-tenant, multi-application tag management / taxonomy service. The SDK is a hand-written,
Guzzle-based client for the REST API: `/api/v2` by default, `/api/v1` when a client is constructed with
`api_version` v1.

## This branch: the 1.x line

This is `support/php7.4`, the 1.x line for PHP 7.4 and later. This section overrides the rest of the
file wherever they disagree; everything else applies as written.

- **What it is.** `main`'s tree downgraded by Rector once (#29) and never regenerated. It is not a
  function of `main`: its README, CHANGELOG, Makefile, CI and this section are its own. Never run
  `scripts/php74-downgrade.sh` over it; the Rector setup stays on `main`.
- **What it takes.** Until its `1.0.0`, what `main` ships for the current `/api/v2`; after that,
  security fixes and major bug fixes only. New features, support for later API changes included, ship
  on 2.x alone (#33). A fix both lines need lands on `main` first and reaches here as a pull request
  into `support/php7.4` (`docs/development.md#changing-the-1x-line`).
- **PHP 7.4 syntax only** (instead of "Target PHP 8.2+"): no union types, named arguments, `match`,
  nullsafe operator, constructor promotion, `readonly`, enums or attributes. Style is `@PSR12`, not
  `@PER-CS`. "`main` must also downgrade to PHP 7.4" is `main`'s rule; here the code is 7.4 already.
- **Models are mutable here**, since the downgrade deleted `readonly`, and each property keeps a
  `@readonly` docblock. Never write code here that relies on writing to one: it would throw on 2.x.
- **Tests run on PHPUnit 9.6.** Name a data provider in a `@dataProvider` annotation (instead of the
  attribute rule); PHP 7.4 reads `#[DataProvider]` as a comment and the provider silently stops.
- **Releases from here are 1.x only**, and the release-line guard refuses anything else. `Client::VERSION`
  and `CHANGELOG.md` are this line's own, set by `release/1.x.y` pull requests into `support/php7.4`.
- Run `make check` before pushing, and `make rector-rule` when a change touches `rector/`; there is no
  `make php74` or `make fixtures` here.

## Product Rules

These mirror server semantics the client must respect — business rules live on the server; the SDK
stays a faithful, ergonomic client.

- The SDK adds ergonomics, not behavior. Do not encode server-side validation or invariants here.
  **One exemption (#15):** a v2-only feature on a v1 client (a `NamespaceScope`, `include_global`)
  throws `LogicException` in `Transport` before any request. It is knowable from the client's own
  configuration, never from server state, and `/api/v1` would reject the namespace headers
  (`400 namespace_not_supported`) or silently drop `include_global`. It mirrors the Go SDK's resolved
  decision. Do not widen it into other server rules without amending this exemption.
- `api_version` is chosen at construction only (default v2) and never per request. A namespace is
  per request only (`RequestOptions`), never a `Config` field: the server fails closed on namespace
  grants, so the SDK must never default one.
- Every request is tenant-scoped via the `X-Tenant-ID` header; `tenant_id` is required config.
- `application_id` is optional on tags and vocabularies (`null` = shared across the tenant) and is
  required for assignments.
- Tag deletion is **deactivation** on the server, not a hard delete. `delete()` methods call HTTP
  `DELETE` and must document the deactivation semantics rather than implying data loss.
- Tag aliases are alternate identifiers that resolve to canonical tags and follow tenant/application
  compatibility rules.
- Keep the SDK faithful to `docs/openapi-v2.yaml` and `docs/openapi.yaml` (v1), the bundled contract
  references. Where the live server diverges from the generated specs — notably the `{data: {...}}`
  single-resource wrapper and the `{data, pagination}` list envelope, both of which the specs omit —
  trust the server's real behavior and document the divergence in a comment. Each spec's header lists
  the known divergences; never read required-ness or shapes from a spec in code.

## API Client Rules

- One file per resource service (`Resource/TagService.php`, …), reached from an accessor on `Client`.
- Methods take required inputs first and an optional `?RequestOptions $options = null` last.
- List methods return `ResultList<T>` decoded from the `{data, pagination}` envelope. Each has a
  `walk*()` beside it (#25) returning a lazy `\Generator` over every page, at limit 200 unless the
  params set one, that throws `WalkInterruptedException` with the `reachedOffset` to resume from.
- `http_client` is any PSR-18 client, and no adapter class wraps one (#18). Both transports send
  through `Http\Sender`, which hands a Guzzle client the request through `send()` with its own default
  headers, query, body, `json` and `auth` cancelled, never through its PSR-18 `sendRequest()`, which
  applies every one of them over the SDK's request; any other client gets `sendRequest()`. `timeout` sets only the client the SDK builds, and with `http_client`
  it is refused, not dropped.
- The client the SDK builds sends each path as written (#64). Where it sends through cURL,
  `Http\PathAsIsCurlFactory` sets `CURLOPT_PATH_AS_IS` on every handle, because libcurl 8.13.0 and later
  removes the `%2E` that `Internal\Path` sends for a `.` id. Never pass that option as a `curl` request
  option: Guzzle 8 refuses it.
- Services read responses only through `Transport::one()` (single resource), `many()` (list), or
  `none()` (204). A `walk*()` method reads its list through `Http\Walker`, which calls `many()` for
  each page. `Transport` exposes no raw-body method; a 2xx missing its envelope raises
  `MalformedResponseException` rather than hydrating an empty model. `one()`/`many()` run the model's
  `fromArray(...)` themselves, so a rejected field is reported with its endpoint and api_version.
- **The health probes are the one exception (#24).** They sit outside `/api`, are unauthenticated,
  answer with a bare `{"status": ...}` body, and answer 503 as a readiness report, so `HealthService`
  reads through `Http\HealthTransport::probe()`, reached from `Client::health()` and from
  `HealthClient`, which needs no credentials. A probe sends no credential header and takes no
  `RequestOptions`; a 2xx is read as strictly as `Transport` reads one, and only `/health/ready`'s own 503
  body becomes `HealthStatus` with `ok` false (`HealthStatus::fromArray($data, $ok)`, since a probe has
  no api_version). Never send an `/api` endpoint through it, and never give `Transport` a bare-body
  reader.
- **`Webhook::verify()` (#26) declares its parameters `mixed` and checks their types itself.** Never
  narrow them to native types. A native type lets a wrong argument (`getenv()`'s false, PSR-7's
  `getHeader()` array) raise a TypeError at the call. That TypeError's trace holds the webhook signing
  secret in every frame that passed it down, before any SDK code could remove it, and the 7.4 line has
  no `#[\SensitiveParameter]` to hide even `verify()`'s own frame.
  - **What it returns and throws:** false for anything a webhook's sender controls, and
    `InvalidArgumentException` only for the caller's mistakes, thrown through
    `Redaction::scrubAll()`, which removes every frame's arguments.
  - **Its one rule beyond the HMAC:** refusing an empty secret, since under an empty key anyone can
    sign. That is the verifier's own security, not a server rule; add no other.
  - **Its tests:** they replay octonomy-go's vendored signature vectors, never digests computed here.
- **`Testing\FixtureClient` (#28) replays, and nothing more.** It is a PSR-18 client for callers' tests,
  answering each endpoint in its `ROUTES` with that endpoint's one capture from `resources/fixtures`,
  which sits outside `tests/` because it ships. Never give it state, persisted writes, paging,
  api_version rules, failure injection, or a way to be handed a response: a behavioral fake is a second
  Octonomy that drifts and teaches callers wrong behavior with a green suite. A new endpoint gets a
  row in `ROUTES` and in `FixtureClientTest::endpoints()`.
- **`Rector\FlagModelPropertyWriteRector` (#33) flags writes and never rewrites them.** It lives in
  `rector/src/`, the one shipped code outside `src/`, for users' own Rector runs. Nothing in `src/` may
  load it, so Rector never becomes a dependency. It marks each write with a `// TODO octonomy-php 2.x:`
  comment, and must find the same writes on both lines: through the native `readonly` on 2.x and the
  `@readonly` docblocks on 1.x. Its test is the fixture project `rector/tests/project`, where a new
  case goes into `src/Writes.php` and `expected/Writes.php` together.
- Non-2xx responses become typed exceptions extending `ApiException` (`NotFoundException`,
  `ConflictException`, `ValidationException`, `AuthenticationException`, `ForbiddenException`). Callers
  catch these; the SDK never returns error codes.
- Exception messages follow the formula in `Internal\ErrorMessage`: what happened, why only when the
  SDK actually knows (and saying so when it does not), the fix, and the values. No message, context or
  chained exception may carry anything on `Internal\Redaction`'s list; a PHP-native exception thrown
  from a frame that holds a request goes through `Redaction::scrub()`, as every `OctonomyException` does.
- Response models are immutable `readonly` DTOs hydrated via `::fromArray($data, $apiVersion)`.
  Hydration is strict and the constructor signature is the field registry: every declared field must
  be present as a key (nullability governs the value only), a wrong JSON type raises, and nothing is
  coerced or defaulted. The only exceptions are named rules in `Internal\Json` with the server-side
  reason in a comment: the v2-only namespace pair, and Tag's `parent_id`/`vocabulary_id`, which the
  server omits when null. Never add a lenient reader. Caller-written JSON (`metadata`) becomes an
  array at its top level only: below it an object stays `\stdClass` and a list an array, so it goes
  back out with the shape it was read with (#48).
- `*Create` and `*ListParams` DTOs use nullable promoted properties + `toArray()` that omits nulls.
  `*Update` DTOs are tri-state (#20) so PATCH can clear a field: each `?string` field defaults to
  `Undefined::VALUE`, and `toArray()` omits only that (`!==`, never `instanceof`) and sends an explicit
  `null`. Which nulls the server accepts is its call, not the DTO's. A non-string field cannot default
  to the string sentinel and keeps `null` = omit, which is safe only while the server has no null for
  it. Where the contract wants a JSON object, the SDK sends one even when the array would encode as a
  list: `Transport` sends an empty body as `{}` (#46), and each DTO casts its `metadata` to an object
  (#48).
- No new public class or method without a doc comment and tests.

## PHP Conventions

- Target PHP **8.2+**. `declare(strict_types=1)` in every file. PSR-4 autoloading, PSR-12 / `@PER-CS`
  style (enforced by php-cs-fixer).
- `main` must also downgrade to PHP 7.4 (CI's `php74` job, `make php74`; see
  `docs/development.md#the-php-74-line`). No enums: use string class constants (#5). Never rely on a
  union type or `readonly` for behavior a test asserts, since the downgrade deletes both; make the
  check explicit.
- `support/php7.4` holds the 1.x line: generated from `main` once (#29), reviewed, committed, and never
  regenerated, so it is not a function of `main` and carries its own `AGENTS.md` section. Until its
  `1.0.0` it takes what `main` ships for the current `/api/v2`; after that, security fixes and major bug
  fixes only, and features ship on 2.x alone (#33). Either way a change reaches it as a pull request
  into it, ported by hand; a fix both lines need lands on `main` first. Its models are mutable, since
  the downgrade deletes `readonly` (`docs/upgrading-1-to-2.md`).
- Guzzle (`guzzlehttp/guzzle`) is the **only** runtime dependency that ships code. `psr/http-client`,
  `psr/http-factory` and `psr/http-message` are required too, because `Config` and `Transport` type
  against them (#18); they hold interfaces only, and Guzzle requires them anyway. Do not add others
  without a strong reason; dev tools (phpunit, phpstan, php-cs-fixer) are require-dev.
- Keep the tree php-cs-fixer-clean and PHPStan-clean at the configured level (`phpstan.neon.dist`).
- The library throws typed exceptions; it never `echo`/`var_dump`/`die`s or writes logs.
- When changing a non-obvious mapping or semantic, add a comment explaining why.

## Testing Expectations

- PHPUnit tests using Guzzle's `MockHandler` (see `tests/SdkTestCase.php`), or `psr18ClientWith()` for
  an `http_client` that is not Guzzle's. Only `Http\SdkBuiltClientTest` sends through the client the
  SDK builds, to a `php -S` server that records the request line and answers 204 with no body. Assert the request
  method, path, auth headers (`Authorization`, `X-Tenant-ID`), query params, and JSON body; assert the
  decoded model/`ResultList` on the response side.
- Response bodies in tests come from captured fixtures (`resources/fixtures/<api_version>/`,
  replayed with `SdkTestCase::captured()`); hand-written response bodies are banned, because a body
  written from the spec agrees with its author rather than the server (#6 shipped green that way).
  **Carve-out:** negative fixtures (missing envelope, missing field, wrong type, invalid JSON, gateway
  HTML, a body on a no-content response, mutually exclusive flag states, a field the api_version does
  not declare, a list page from an offset other than the one asked for) are synthetic, since a conforming server cannot produce them; build them only with
  `SdkTestCase::syntheticNegative()` or `syntheticNegativeFrom()`, naming the `SyntheticNegative`
  kind that applies. A body captured from one api_version is not a capture of the other's response.
  `clientWith()`/`transportWith()`/`psr18ClientWith()` accept nothing else, and no test outside the
  fixture helpers may reference `GuzzleHttp\`, except the integration suite's `InterruptingClient`,
  which forwards to the real server and only ever returns what it sent. Request-side assertions stay hand-written: they test SDK
  behavior, not server shape.
- Fixtures are written only by `make fixtures` (`scripts/capture-fixtures.php` against the harness's
  pinned server) and never edited by hand; the manifest records each file's hash and the unit suite
  fails on any difference. A refresh is its own reviewed PR, never automatic. Moving the harness
  image pin requires re-capturing, and re-vendoring both OpenAPI specs from that release, in the same
  PR.
- Cover success paths, the `{data, pagination}` list envelope, and error→exception mapping
  (404 → `NotFoundException`, 409 → `ConflictException`, 400 → `ValidationException`).
- Use PHPUnit attributes, not doc-comment annotations. `#[DataProvider]` is the only one the 7.4
  downgrade converts; any other fails it until `tools/rector`'s rule learns to convert it.
- Integration tests live in `tests/Integration/`, extend `IntegrationTestCase`, and run against
  `scripts/octonomy-harness.sh` (`make harness-up`, then `make test-integration`). They must skip when
  `OCTONOMY_TEST_BASE_URL` is empty and must only read, so the suite reruns against one harness.
- The harness seeds its corpus with curl, never through the SDK, so SDK defects cannot shape the data
  the SDK is tested against. When changing the corpus, update the counts in `assert_corpus` and the
  table in `docs/development.md`, re-run `make fixtures`, and keep both images pinned by digest.

## Local Development

- Run `make check` before pushing and `make release-check` before a release. Run `make php74` (needs
  Docker) when a change touches `src/`, `tests/`, `rector/`, `tools/rector/` or `compat/`, and
  `make rector-rule` when it touches `rector/`.
- Keep the README quickstart, `examples/`, `docs/guide.md`, and `composer.json`/`Makefile` scripts current
  with the public API. Every example must run twice in a row against one dev server (CI's `dev-server` job
  does), and the guide links each one. The guide shows code only where no example can: PHP and YAML in its
  testing and framework sections alone (`GuideTest` checks both).
- Refresh both vendored specs (`docs/openapi.yaml`, `docs/openapi-v2.yaml`) from one Octonomy server
  release tag when targeting a new contract, following `docs/development.md#keeping-the-contract-current`,
  and record the tag in `docs/versioning.md`.

## Development Pipeline

- Branch names must follow Conventional Branch naming from
  https://conventional-branch.github.io/.
- Use `<type>/<description>` with lowercase alphanumerics, hyphens, and dots only where valid.
- Allowed branch types are `feature`, `feat`, `bugfix`, `fix`, `hotfix`, `release`, and `chore`.
- Example branch names: `feature/tag-assignments`, `fix/pagination-decode`, `chore/update-agent-rules`.
- **One exception (#29):** `support/php7.4` is the 1.x line's long-lived release branch, not a unit of
  planned development, so its name is exempt from the type list and the issue-number rule. Work for it
  still arrives on ordinary `<type>/<issue-number>-...` branches, as pull requests into it.
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
- `scripts/release-line-guard.sh` (#30) holds 2.x to `main` and `php >=8.2`, and 1.x to
  `support/php7.4` and `php >=7.4`, on every PR into a line, every push to one, and every tag. Every
  check in it is blocking, because Composer and Packagist refuse no mistag; never relax one to let a
  release through. A change to it comes with a case in `scripts/release-line-guard-test.sh` that
  asserts the reason text, not only the exit status.
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
