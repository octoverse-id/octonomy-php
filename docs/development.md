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

## Running against a local Octonomy

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
