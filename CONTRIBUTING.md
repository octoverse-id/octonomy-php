# Contributing to octonomy-php

Thanks for contributing! This is the official PHP SDK for the Octonomy taxonomy service — a
hand-written, Guzzle-based client. Please keep the runtime dependency surface minimal.

## Getting started

```bash
git clone https://github.com/octoverse-id/octonomy-php.git
cd octonomy-php
composer install
make test
```

Requires PHP 8.2+. Guzzle is the only runtime dependency; everything else is `require-dev`.

## Quality gates

Run these before opening a PR; CI runs the same checks and must pass before merge:

```bash
make cs      # php-cs-fixer --dry-run (PSR-12 / PER-CS)
make stan    # phpstan analyse
make test    # phpunit
```

`make check` bundles all three; `make release-check` runs the full pre-release gate. Apply style
fixes with `make cs-fix`.

## Coding conventions

These mirror [AGENTS.md](AGENTS.md):

- `declare(strict_types=1)` in every file; PSR-4 / PSR-12.
- **Guzzle is the only runtime dependency.**
- One file per resource service (`src/Resource/*Service.php`), reached from an accessor on `Client`.
- Methods take required inputs first, `?RequestOptions $options = null` last.
- List methods return `ResultList<T>` decoded from the `{data, pagination}` envelope.
- Services read responses only through `Transport::one()` / `many()` / `none()`; never hydrate a
  raw body.
- Non-2xx responses throw typed exceptions extending `ApiException`; never return error codes.
- Response models are `readonly` DTOs with `::fromArray($data, $apiVersion)`, reading every field
  through a strict `Internal\Json` reader that matches its declared type (see
  [docs/architecture.md](docs/architecture.md#strict-hydration)); input DTOs use nullable promoted
  properties + `toArray()` that omits nulls.
- Keep models faithful to `docs/openapi.yaml`; document any deliberate divergence.
- Every public class and method has a doc comment.

## Testing expectations

- PHPUnit + Guzzle `MockHandler` (extend `tests/SdkTestCase.php`). Assert request
  method/path/headers/query/body and the decoded response.
- Replay responses captured from a real server (`self::captured('v1/tags/get')`); never write a
  response body by hand. Only shapes no conforming server sends go through
  `self::syntheticNegative(SyntheticNegative::<KIND>, ...)`. See
  [docs/development.md](docs/development.md#captured-response-fixtures).
- Cover success, the list envelope, and error→exception mapping.
- Use PHPUnit attributes (`#[DataProvider]`), not doc-comment annotations.

## Branches, commits, and PRs

- Branch names follow [Conventional Branch](https://conventional-branch.github.io/):
  `<type>/<description>`, types `feature|feat|bugfix|fix|hotfix|release|chore`.
- For planned work tracked by an issue, use `<type>/<issue-number>-<description>` and put
  `Closes #<n>` in the PR body.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/).
- Fill out the PR checklist (cs, stan, test, docs, CHANGELOG).

## Changelog and versioning

- Add a bullet under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md) for any user-facing change.
- **Do not** bump `Client::VERSION` in feature/fix PRs. Version bumps happen only in a dedicated
  `release/<version>` PR — see [docs/versioning.md](docs/versioning.md) and
  [docs/release.md](docs/release.md).

## Security

Please report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Do not open a public issue
for security problems.
