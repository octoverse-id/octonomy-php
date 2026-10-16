# Development

## Setup

```bash
git clone https://github.com/octoverse-id/octonomy-php.git
cd octonomy-php
composer install
make test
```

Requires PHP 8.2+. Guzzle is the only runtime dependency; dev tools (PHPUnit, PHPStan, php-cs-fixer)
are installed via `composer install`.

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
the wire contract:

- **Request side:** method, path, `Authorization` and `X-Tenant-ID` headers, query params, and JSON
  body — read back through `$this->lastRequest()`, `$this->lastQuery()`, `$this->lastJsonBody()`.
- **Response side:** the decoded model / `ResultList`, and error→exception mapping via
  `expectException(...)` or a try/catch on `ApiException`.

Use PHPUnit attributes (`#[DataProvider]`), not doc-comment annotations. Keep new code covered.

## Integration tests against a real server

The unit suite cannot tell you whether the SDK matches the server, only whether it matches the bodies
the tests were written with. `scripts/octonomy-harness.sh` boots a real Octonomy and the
`integration` PHPUnit suite (`tests/Integration/`) runs against it. You need Docker and curl.

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
`OCTONOMY_HARNESS_ALLOW_UNPINNED=1` is set. Every other knob is an `OCTONOMY_HARNESS_*` variable read
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

## Running against your own Octonomy

Start an Octonomy server (see that repo's README), create a service token, then run the example:

```bash
OCTONOMY_BASE_URL=http://localhost:8000 \
OCTONOMY_TOKEN=svc_... \
OCTONOMY_TENANT_ID=acme \
php examples/quickstart.php
```

## Keeping the contract current

`docs/openapi.yaml` is vendored from the Octonomy server. When targeting a new server contract, refresh
it (regenerate on the server with `make openapi`, copy the file here), reconcile any model changes, and
note the server version in [versioning.md](versioning.md).
