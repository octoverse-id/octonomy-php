# Development

## Setup

```bash
git clone --branch support/php7.4 https://github.com/octoverse-id/octonomy-php.git
cd octonomy-php
composer install
make test
```

This is the 1.x line, on `support/php7.4` ([this branch](#this-branch-the-1x-line)). It requires
PHP 7.4+, and so do its dev tools. Guzzle is the only runtime dependency that ships code, beside the PSR
HTTP interface packages it implements; dev tools (PHPUnit 9.6, PHPStan, php-cs-fixer) are installed via
`composer install`, resolved for PHP 7.4 whichever PHP runs it (`config.platform.php` is 7.4.33).

## Quality gates

```bash
make cs        # php-cs-fixer --dry-run (@PSR12)
make cs-fix    # apply style fixes
make stan      # phpstan analyse (level in phpstan.neon.dist)
make test      # phpunit
make coverage  # phpunit --coverage-text (needs Xdebug or PCOV)
make check     # cs + stan + test + the release-line guard and its tests (fast pre-push gate)
make version-check       # Client::VERSION against the latest CHANGELOG.md release heading
make release-line-guard  # this tree against its release line (docs/release.md)
make release-check  # the full pre-release gate
```

`cs`, `cs-fix`, `stan`, `test` and `check` are also Composer scripts, though `composer check` runs
only the first three gates, not the guard. CI runs `make version-check` on every PR, and the
release-line guard with its tests in a workflow of its own, `release-line.yml`, which also runs on
every tag push ([release.md](release.md#the-release-line-guard)).

## Testing approach

Tests use Guzzle's `MockHandler` (via `tests/SdkTestCase.php`) to stand up a fake Octonomy and assert
the wire contract. `psr18ClientWith()` does the same through `ReplayingClient`, a PSR-18 client that is
not Guzzle's, for what the SDK sends through `sendRequest()`. Only `tests/Http/SdkBuiltClientTest.php`
sends for real. It covers the client the SDK builds, which a mock handler would replace, by sending to a
`php -S` server that records the request line and answers 204 with no body (#64). The other tests
check:

- **Request side:** method, path, `Authorization` and `X-Tenant-ID` headers, query params, and JSON
  body — read back through `$this->lastRequest()`, `$this->lastQuery()`, `$this->lastJsonBody()`.
- **Response side:** the decoded model / `ResultList`, and error→exception mapping via
  `expectException(...)` or a try/catch on `ApiException`. The responses are never written by hand:
  each test replays one captured from a real server with `self::captured('v1/tags/get')` (see
  [Captured response fixtures](#captured-response-fixtures)). A shape no conforming server sends — a
  missing envelope, invalid JSON, a gateway's HTML — is built with
  `self::syntheticNegative(SyntheticNegative::MISSING_ENVELOPE, 200, '[]')`, whose first argument names
  which carve-out applies. A negative that differs from a captured response in one named respect, such
  as a field removed or mistyped, is built with `self::syntheticNegativeFrom('v1/tags/get',
  SyntheticNegative::MISSING_FIELD, $break)`, which applies `$break` to the captured body and keeps
  everything else as the server sent it. A capture from one api_version replayed as the other's is a
  negative too (`SyntheticNegative::UNDECLARED_FIELD` for the namespace pair on v1), never a capture. `clientWith()` and `transportWith()` accept only these,
  refuse a capture (or a negative made from one) on a client of the other api_version, which is v2
  unless the test passes `['api_version' => ApiVersion::V1]`, and
  `NoHandWrittenResponseBodiesTest` fails on any `GuzzleHttp\` reference outside the fixture helpers,
  so there is no third way in.

Use PHPUnit attributes (`#[DataProvider]`), not doc-comment annotations. Keep new code covered.

## Integration tests against a real server

The unit suite replays bodies captured from a real server, but only those, and only as that server
was when they were captured. `scripts/octonomy-harness.sh` boots a real Octonomy and the
`integration` PHPUnit suite (`tests/Integration/`) runs the SDK end to end against it. You need
Docker and curl.

```bash
make harness-up         # boot, migrate, mint tokens, seed, write .octonomy-harness.env
make test-integration   # sources .octonomy-harness.env, runs --testsuite integration
make harness-down       # remove the containers, the network, and the env file
make harness-logs       # dump container logs when a boot or a test fails
```

`harness-up` takes about a minute warm; the first run also pulls the images. It fails, with container
logs, unless the environment is genuinely usable: namespaced writes return 201 and persist their
namespace, each exact merchant grant is refused outside its own namespace, and every seeded partition
reads back with exactly the rows it was given. Overrides must use only `A-Z a-z 0-9 . _ -`, because they
are written unescaped into Docker flags, JSON, URLs, and the sourced env file.

**The gate.** With `OCTONOMY_TEST_BASE_URL` empty every integration test skips, so `make test` and a
bare `vendor/bin/phpunit` stay hermetic. CI sets `OCTONOMY_INTEGRATION_REQUIRED=1`, which turns that
skip into a failure. A base URL without a token or tenant is always a failure: that is a broken
harness, not an absent one.

**Images** are pinned by digest (Octonomy `3.2.1`, Postgres `16`), and an override through
`OCTONOMY_HARNESS_IMAGE` / `OCTONOMY_HARNESS_POSTGRES_IMAGE` that is not a digest is refused unless
`OCTONOMY_HARNESS_ALLOW_UNPINNED=1` is set. Moving the Octonomy pin means re-capturing the fixtures
(`make fixtures`) and re-vendoring both specs ([Keeping the contract current](#keeping-the-contract-current))
in the same PR; the unit suite fails until you do. Every other knob is an `OCTONOMY_HARNESS_*` variable read
at the top of the script; `OCTONOMY_HARNESS_PORT` (default `8000`) is the one you are most likely to
need.

**The seeded corpus** is written with curl, not through the SDK, so a defect in the SDK cannot shape
the data its own tests and fixtures are read from. All rows belong to tenant `harness-tenant`:

| Partition | Vocabularies | Tags | Aliases | Assignments (`product/…`) |
| --------- | ------------ | ---- | ------- | ------------------------- |
| global | `colors` (shared), `sizes` (`harness-app`) | `color/red`, `color/green`, `color/maroon` (child of red), `size/large` | `scarlet` → red, `big` → large | `sku-001`: red, large; `sku-002`: green, red via `scarlet` |
| `merchant:harness-merchant-a` | `harness-probe-a` | `color/red` (in global `colors`), `label/sale` | `promo` → sale | `sku-001`: red, sale |
| `merchant:harness-merchant-b` | `harness-probe-b` | `color/red`, `label/clearance` | — | `sku-001`: red |

The `harness-probe-*` vocabularies are left by the grant probes that run before seeding; a third,
`harness-probe`, sits alone in `merchant:harness-merchant`. `/api/v1` can address only the global
partition. After seeding, `assert_corpus` reads every resource back from every partition above and
requires these exact counts. The script's comment at `seed_corpus` is the source of truth; keep this
table in step with it.

**Provenance.** The script is a port of `octonomy-go/scripts/octonomy-harness.sh`. Its header lists what
is deliberately different here; offer changes to the shared parts upstream.

### The dev server

`scripts/octonomy-dev-server` is the package's Composer bin (`vendor/bin/octonomy-dev-server` in a
project that requires the SDK; the README documents it for users). It runs this same harness under
names of its own: containers prefixed `octonomy-php-dev-server` and the env file
`.octonomy-dev-server.env` in the package directory, which here is the repository root. A script run
through it writes, and the integration suite needs the corpus exactly as seeded, so the two never
share a server, and `up` on one never removes the other. Both default to port 8000, so run them side
by side with `OCTONOMY_HARNESS_PORT` set for one.

```bash
scripts/octonomy-dev-server run examples/quickstart.php   # or: composer exec octonomy-dev-server run ...
scripts/octonomy-dev-server down
```

Composer links a package's bin only into projects that require it, so in a checkout it is
`scripts/octonomy-dev-server`, or `composer exec octonomy-dev-server`, which finds it in both.
`octonomy-harness.sh status` decides what `run` does: 0, up, reuse it; 1, down, boot; 2, containers
under the prefix exist without this env file, refuse. That last is another installation's server (a
consumer project's, if you also run one from this checkout): every installation keeps its env file in
its own package directory under the one prefix, so booting would destroy that server and leave its
project holding rejected tokens. `up` refuses it too. `run` then hands the script each
`OCTONOMY_TEST_<NAME>` from the env file as `OCTONOMY_<NAME>`: the file itself keeps the names the
integration suite reads, and the script sees none, not even one inherited from your shell, so a test
suite it runs stays skipped. `tests/DevServerTest.php` covers it with a fake `docker` on `PATH`,
without booting anything; CI's `dev-server` job installs the SDK into an empty Composer project and
runs the quickstart through the real `vendor/bin` proxy.

## Captured response fixtures

Every response body the unit suite replays was sent by a real Octonomy. `resources/fixtures/` holds
one JSON file per exchange, by API version, resource group, and operation:

```text
resources/fixtures/
  manifest.json                  server image + version, and a sha256 per fixture
  v1/tags/get.json               {"request": {...}, "response": {"status", "headers", "body"}}
  v1/errors/not-found.json
  v2/vocabularies/list-include-global.json
  health/ready-unavailable.json  a health probe: no api_version, outside /api
  ...
```

The corpus sits under `resources/`, not `tests/`, because it ships: `Testing\FixtureClient` replays it
to callers' own tests from a Composer install (#28), and CI's `dev-server` job proves it loads from
one. Never mark `resources/` `export-ignore`.

The `response` half is what tests replay. The `request` half records what produced it — method,
path, query, the namespace headers, which credential sent it (never the token), and the body — so a
reviewer can see why the server answered as it did.

Coverage, for each of `/api/v1` (the global partition) and `/api/v2` (merchant A's namespace): the
create, get, list, update and delete of vocabularies, tags and tag aliases; a tag's aliases,
resources and audit log; assignment create (201 new, 200 existing), delete, bulk-assign and
bulk-remove; resource tags and their replacement; tag resolution by slug and by alias; audit logs;
an empty list and a middle page with both links; and one error envelope per code the SDK maps
(`not_found`, `conflict`, `validation_error`, `authentication_required`, `forbidden`,
`tenant_mismatch`, `inactive_tag`, plus v1's `application_mismatch` and `namespace_not_supported`).
v2 also reads the global partition and an `include_global` list, so its corpus holds populated and
null namespace pairs side by side, and walks merchant A's tags as `walk()` does (#25): at limit 3 a
full page and then a short last one, asked for at the offset the first ended on, an empty page past
the end, the walk's own limit of 200, and a negative offset, which the server serves from 0; v1's holds no namespace fields at all. `health/` holds the probes,
sent with no credentials: `/health/live` and `/health/ready` answering 200, and `/health/ready`
answering 503 with the harness database stopped.

Some of what the server really sends differs from what you might assume. This is why the fixtures
exist:

- A bad or missing bearer token gets **403**, not 401, with code `authentication_required`. The
  server sends no `WWW-Authenticate` challenge, so DRF downgrades the status.
- A tag's `parent_id` and `vocabulary_id` are **omitted** when null, while `application_id` and
  `description` are sent as `null`.
- `metadata` read back from the database comes in Postgres `jsonb` key order, which can differ from
  the order it was written in.
- `/health/ready` keeps answering 200 for up to a minute after the database stops. The server keeps
  each database connection for 60 seconds and checks readiness by opening a cursor on it, which needs
  no round trip, so only a request served on a new connection sees the database gone.

### Refreshing

The fixtures are captured on `main`, and this branch has no `make fixtures`: its corpus is `main`'s from
the commit it was generated from. A refresh reaches 1.x, when one has to, as a pull request that copies
`resources/fixtures`, the harness pin in `scripts/octonomy-harness.sh` and the vendored specs from
`main` together, since `CapturedCorpusTest` ties the three to each other. On `main`:

```bash
make fixtures    # boot a fresh harness, capture, tear it down
```

Capture writes rows, so `make fixtures` boots its own harness (replacing any you have running) and
removes it afterwards. It needs Docker, curl and PHP. `scripts/capture-fixtures.php` talks to the
server over raw HTTP, never through the SDK, for the reason the harness seeds with curl. Each request
must return the status a conforming server gives, so a changed contract stops the capture rather
than being recorded as the new truth.

The health probes are captured last, because the not-ready answer needs the database gone: the
capture runs `scripts/octonomy-harness.sh stop-db` and re-sends `/health/ready` every two seconds, for
up to two minutes, until it answers 503. That adds about a minute to `make fixtures` (see the
readiness note above), and leaves the harness serving nothing until it is removed.

Two captures of the same server produce byte-identical files. A new capture goes after every existing
one of its version, so the ids and timestamps captured before it keep their stand-ins. Request ids are
numbered across the whole capture, though, so one added to v2 renumbers the health probes' (#25). Volatile values are replaced with stable
stand-ins of the same format: each UUID becomes `00000000-0000-4000-8000-<n>` and each request id
`req_<n>`, both numbered by first appearance; each timestamp becomes `2026-01-01T00:00:00` plus its
chronological rank in seconds, keeping its fraction length and zone suffix; and the harness base URL
becomes `http://127.0.0.1:8000`. Everything else is exactly what the server sent, including key
order, null versus absent keys, and `{}` versus `[]`.

A refresh is its own PR, reviewed like code: the diff is the server's behavior change. Never edit a
fixture by hand. `CapturedCorpusTest` fails if a file differs from the hash in the manifest, if a
file is added or lost, if the manifest names a different image than the harness pins, or if the
corpus stops holding exactly the operations it lists as required. Capturing a new operation means
adding it to that list in the same PR.

## This branch: the 1.x line

`support/php7.4` holds the 1.x line: the tree `scripts/php74-downgrade.sh` wrote from `main` at
`c1626d4`, reviewed and committed in [#29](https://github.com/octoverse-id/octonomy-php/issues/29), and
never regenerated. The Rector setup that produced it (`tools/rector/`, `compat/`, the downgrade and
check scripts, and `main`'s `php74` CI job) stays on `main`; this branch carries none of it.

What differs from `main`:

| On this branch | On `main` | Why |
| -------------- | --------- | --- |
| PHP 7.4+; Guzzle `^7.8` | PHP 8.2+; Guzzle `^7.8 \|\| ^8.0` | `composer.json`, which `compat/composer.json` on `main` supplied |
| Models and DTOs are mutable, each property marked `@readonly` in its docblock | `readonly` | The downgrade deletes the keyword ([upgrading-1-to-2.md](upgrading-1-to-2.md)) |
| PHPUnit 9.6, `@dataProvider` annotations, `phpunit.xml.dist` on the 9.x schema | PHPUnit 11, `#[DataProvider]` | PHP 7.4 reads an attribute as a comment |
| php-cs-fixer at `@PSR12` | `@PER-CS2.0` | Rector's output (de-promoted constructors, `switch` blocks) is not `@PER-CS` clean |
| PHPStan level 8 at `phpVersion: 70400`, with the ignores below | level 8 | |
| CI: `static` on 7.4, `test` on 7.4 to 8.4, the integration suite and the dev server on 7.4, and the release-line guard | the 8.x matrix, the downgrade proof, and the same integration, dev-server and guard jobs on 8.3 | This tree is proven where it runs |
| No `make php74` or `make fixtures` | both | Generation stopped; capture happens on `main` |

PHPStan ignores two kinds of error, each checked for staleness so it fails once the cause goes.
`property.readOnlyByPhpDocDefaultValue`: Rector keeps a promoted parameter's default on the property it
de-promotes, beside the `@readonly` tag, and the constructor always assigns it. And `class.notFound`
with `argument.type` in `src/Http/PathAsIsCurlFactory.php`: PHPStan for 7.4 knows no `\CurlHandle`,
which the downgraded handle check names for PHP 8's sake.

**Writing code here.** PHP 7.4 syntax only: no union types, named arguments, `match`, nullsafe
operator, constructor promotion, `readonly`, enums or attributes. A data provider is named in a
`@dataProvider` annotation, since PHPUnit 9 reads no attributes. PHPStan at `phpVersion: 70400` and the
PHP 7.4 jobs reject what slips through.

### Changing the 1.x line

`support/php7.4` takes what `main` ships for the current `/api/v2` until its `1.0.0`, and after that
security fixes and major bug fixes only; features ship on 2.x alone
([#33](https://github.com/octoverse-id/octonomy-php/issues/33)). Every change reaches it the same way:

1. Branch from `support/php7.4` as usual (`fix/<issue>-...`) and open the pull request against it.
   Its CI runs PHP 7.4 to 8.4, PHPStan at `phpVersion: 70400`, the integration suite and the dev
   server on PHP 7.4, and the release-line guard.
2. A fix both lines need lands on `main` first. Carry it across by cherry-picking when it applies
   cleanly. When it does not, run `scripts/php74-downgrade.sh` from `main` into a temp dir and copy
   only the files the fix changed, so the 7.4 form is Rector's rather than written by hand.
3. Never regenerate the whole branch over itself: its README, CHANGELOG, Makefile, CI and
   `AGENTS.md` section are its own, and copying the full tree would revert them.

## Running against your own Octonomy

Start an Octonomy server (see that repo's README), create a service token, then run an example:

```bash
OCTONOMY_BASE_URL=http://localhost:8000 \
OCTONOMY_TOKEN=svc_... \
OCTONOMY_TENANT_ID=acme \
OCTONOMY_APPLICATION_ID=storefront \
php examples/quickstart.php
```

Each example's first comment says what its token needs. `health.php` reads `OCTONOMY_BASE_URL` alone.

## The examples

`examples/` holds one runnable program per resource group beside the quickstart (#27). They share
`examples/support/bootstrap.php`, which loads the autoloader, builds the client from the environment,
and holds the `ensure*()` helpers; the quickstart does not use it, so it stays one file. An example must:

- **Run twice in a row against one server.** The dev server keeps what each run wrote, and so does a
  reader's own Octonomy. Create a row it needs through `ensureVocabulary()`, `ensureTag()` or
  `ensureAlias()`, which take the 409 an earlier run's row causes and find that row, and undo what it
  changes: a row it deletes it reactivates, and a resource it tags it untags. A second run then prints
  what the first did.
- **Use slugs and resource ids no other example uses.** Each one then starts from the same state
  whatever ran before it.
- **Exit non-zero when something fails,** so CI notices: an `OctonomyException` ends it through
  `exampleFail()`, which says why on stderr. It checks a result itself only where a wrong one would
  print nothing amiss, as `audit-logs.php` does when its own request is missing from the history.

CI's `dev-server` job runs every `examples/*.php` the installed package ships twice, from an empty
Composer project, so a new one is covered without being listed. PHPStan reads them on both lines.

A new example also needs a row in [`guide.md`](guide.md)'s table: `GuideTest` fails until the guide links
every `examples/*.php`, and fails on any link in the guide whose file or heading is gone, or on PHP or
YAML outside its testing and framework sections, where it would duplicate an example.

## Keeping the contract current

On this branch the contract does not move: the 1.x line stays on the `/api/v2` contract of its `1.0.0`,
and support for later API changes ships on 2.x only
([#33](https://github.com/octoverse-id/octonomy-php/issues/33)). The steps below are `main`'s.

`docs/openapi.yaml` (v1) and `docs/openapi-v2.yaml` (v2) are vendored from the Octonomy server at the
release the harness pins, the same one the fixtures are captured from. Each is the server's file byte
for byte below a comment header that names the tag, the commit, and the file's sha256, and lists the
known divergences from what the server sends. Moving the harness pin therefore means re-vendoring both
specs in the same PR. To refresh them:

1. Copy each file from the tag, not from a working tree: `git -C <octonomy> show vX.Y.Z:docs/openapi.yaml`
   and `...:docs/openapi-v2.yaml`.
2. Keep each header, updating its tag, commit, and sha256 (`sha256sum` of the server's file).
3. Re-check every divergence in the note against the server source and every 2xx response in
   `resources/fixtures`, and add any new one. A divergence the server has fixed comes out of the
   note.
4. Name the tag in [versioning.md](versioning.md), and reconcile any model changes.

`VendoredSpecTest` fails if a spec's body no longer hashes to its header, if a spec's tag is not the
harness pin, if the specs name different commits, if `versioning.md` does not name the tag, if the
divergence note loses an item, or if shipped PHP (`src/`, `examples/`) names a spec. Those checks read
only this repository. `Integration\VendoredSpecMatchesServerTest` is the independent one: it compares
each body with what the pinned server serves at `/api/v1/schema/` and `/api/v2/schema/`.
