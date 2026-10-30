# Development

## Setup

```bash
git clone https://github.com/octoverse-id/octonomy-php.git
cd octonomy-php
composer install
make test
```

Requires PHP 8.2+. Guzzle is the only runtime dependency that ships code, beside the PSR HTTP interface
packages it implements; dev tools (PHPUnit, PHPStan, php-cs-fixer) are installed via `composer install`.

## Quality gates

```bash
make cs        # php-cs-fixer --dry-run (PSR-12 / PER-CS)
make cs-fix    # apply style fixes
make stan      # phpstan analyse (level in phpstan.neon.dist)
make test      # phpunit
make coverage  # phpunit --coverage-text (needs Xdebug or PCOV)
make check     # cs + stan + test (fast pre-push gate)
make release-check  # the full pre-release gate
```

These are also Composer scripts: `composer cs`, `composer stan`, `composer test`, `composer check`.

## Testing approach

Tests use Guzzle's `MockHandler` (via `tests/SdkTestCase.php`) to stand up a fake Octonomy and assert
the wire contract. `psr18ClientWith()` does the same through `ReplayingClient`, a PSR-18 client that is
not Guzzle's, for what the SDK sends through `sendRequest()`:

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

## Captured response fixtures

Every response body the unit suite replays was sent by a real Octonomy. `tests/fixtures/captured/`
holds one JSON file per exchange, by API version, resource group, and operation:

```text
tests/fixtures/captured/
  manifest.json                  server image + version, and a sha256 per fixture
  v1/tags/get.json               {"request": {...}, "response": {"status", "headers", "body"}}
  v1/errors/not-found.json
  v2/vocabularies/list-include-global.json
  ...
```

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
null namespace pairs side by side; v1's holds no namespace fields at all.

Some of what the server really sends differs from what you might assume. This is why the fixtures
exist:

- A bad or missing bearer token gets **403**, not 401, with code `authentication_required`. The
  server sends no `WWW-Authenticate` challenge, so DRF downgrades the status.
- A tag's `parent_id` and `vocabulary_id` are **omitted** when null, while `application_id` and
  `description` are sent as `null`.
- `metadata` read back from the database comes in Postgres `jsonb` key order, which can differ from
  the order it was written in.

### Refreshing

```bash
make fixtures    # boot a fresh harness, capture, tear it down
```

Capture writes rows, so `make fixtures` boots its own harness (replacing any you have running) and
removes it afterwards. It needs Docker, curl and PHP. `scripts/capture-fixtures.php` talks to the
server over raw HTTP, never through the SDK, for the reason the harness seeds with curl. Each request
must return the status a conforming server gives, so a changed contract stops the capture rather
than being recorded as the new truth.

Two captures of the same server produce byte-identical files. Volatile values are replaced with stable
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

## The PHP 7.4 line

`main` is the only source. The 1.x line for PHP 7.4 (epic #4) is generated from it by Rector, and CI
proves on every PR and every push to `main` that the generated tree works on a real PHP 7.4. The
proof runs in a throwaway directory and commits nothing. `support/php7.4` does not exist yet: P5
generates it once with the same script, reviews it, and freezes it.

```bash
make php74    # downgrade into a temp dir, then prove it on PHP 7.4 in Docker
```

`make php74` takes about a minute and needs Docker. The pieces, which CI's `php74` job runs one by one:

| Piece | What it is |
| ----- | ---------- |
| `tools/rector/` | An isolated Composer project with `rector/rector` pinned to one exact version, and the downgrade config `rector.php` (`withDowngradeSets(php74: true)`, the `down-to-php74` level set). It is kept out of the root `require-dev` because Rector requires its own PHPStan, which would tie `main`'s static analysis to every Rector upgrade, and `composer.lock` is not committed, so only an exact constraint pins it. The PHPStan Rector runs on is pinned exactly too: Rector's own range (`^2.3.0` for 2.7.0) would float to a PHPStan it was not released with, and PHPStan 2.3.4 makes Rector 2.7.0 fail on every file. Bump the two together, in a PR of their own; Dependabot opens those. |
| `compat/` | The 7.4 line's config, laid over the downgraded tree: `composer.json` (`php >=7.4`, Guzzle `^7.8` and the PSR HTTP interfaces, PHPUnit `^9.6`, and `config.platform.php` 7.4.33 so it resolves for 7.4 on any PHP), `phpunit.xml.dist` on the PHPUnit 9 schema, `phpstan.neon.dist` at `phpVersion: 70400`, and `.php-cs-fixer.dist.php` at `@PSR12`. |
| `scripts/php74-downgrade.sh <dir>` | Copies `src/`, `tests/`, `examples/`, the harness script, and the vendored specs with `docs/versioning.md` (which tests read) into `<dir>`, runs Rector over the PHP, lays `compat/` over it, and formats it at `@PSR12`. Any non-zero exit from Rector fails it. |
| `scripts/php74-check.sh <dir> <main-junit.xml>` | Run with PHP 7.4 after `composer install` in `<dir>`. It runs `php -l` on every file, the `@PSR12` dry run, PHPStan at `phpVersion: 70400`, and PHPUnit 9.6. Then it requires that the suite ran exactly the tests `main`'s suite ran, data sets included, each with the same outcome (passed, skipped or failed), compared through each run's JUnit log. It reports every failing check, not just the first. |

`phpVersion: 70400` applies to the downgraded tree only. On `main` it would flag every 8.x construct
`main` deliberately uses.

**What `main` must not do.** Rector downgrades most of 8.2, but not everything:

- **No enums.** Rector turns an enum case into a plain string constant, so code that tells a case
  apart by type stops working, and it crashes on a named-argument call that skips an enum default
  (#5). Use a class of string constants, like `ApiVersion`.
- **No behavior that depends on a union type, `readonly`, or another 8.x-only check.** The downgrade
  deletes union types and the `readonly` keyword, so on 7.4 a wrong type passes and a model's
  properties can be written. When a test asserts a refusal, make the check explicit, as
  `SdkTestCase::mockedHttpClient()` does.
- **No PHPUnit attribute but `#[DataProvider]`.** PHPUnit 9 reads annotations only, and the stock
  downgrade deletes an attribute it is not configured for, or writes a bare `@dataProvider` without
  the method name. `tools/rector/src/DataProviderAttributeToAnnotationRector.php` writes the
  annotation with the name, and fails the run on any other PHPUnit attribute (`#[Test]`, `#[Depends]`,
  ...) rather than let it be deleted. Teach that rule a new attribute before using it.

PHPStan on the 7.4 tree ignores exactly one error, `property.readOnlyByPhpDocDefaultValue`. Rector
keeps a promoted parameter's default on the property it de-promotes, beside a `@readonly` tag. The
ignore is checked for staleness, so it fails once Rector stops doing this.

## Running against your own Octonomy

Start an Octonomy server (see that repo's README), create a service token, then run the example:

```bash
OCTONOMY_BASE_URL=http://localhost:8000 \
OCTONOMY_TOKEN=svc_... \
OCTONOMY_TENANT_ID=acme \
php examples/quickstart.php
```

## Keeping the contract current

`docs/openapi.yaml` (v1) and `docs/openapi-v2.yaml` (v2) are vendored from the Octonomy server at the
release the harness pins, the same one the fixtures are captured from. Each is the server's file byte
for byte below a comment header that names the tag, the commit, and the file's sha256, and lists the
known divergences from what the server sends. Moving the harness pin therefore means re-vendoring both
specs in the same PR. To refresh them:

1. Copy each file from the tag, not from a working tree: `git -C <octonomy> show vX.Y.Z:docs/openapi.yaml`
   and `...:docs/openapi-v2.yaml`.
2. Keep each header, updating its tag, commit, and sha256 (`sha256sum` of the server's file).
3. Re-check every divergence in the note against the server source and every 2xx response in
   `tests/fixtures/captured`, and add any new one. A divergence the server has fixed comes out of the
   note.
4. Name the tag in [versioning.md](versioning.md), and reconcile any model changes.

`VendoredSpecTest` fails if a spec's body no longer hashes to its header, if a spec's tag is not the
harness pin, if the specs name different commits, if `versioning.md` does not name the tag, if the
divergence note loses an item, or if shipped PHP (`src/`, `examples/`) names a spec. Those checks read
only this repository. `Integration\VendoredSpecMatchesServerTest` is the independent one: it compares
each body with what the pinned server serves at `/api/v1/schema/` and `/api/v2/schema/`.
