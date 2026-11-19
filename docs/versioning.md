# Versioning Policy

`octonomy-php` follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). This document is the
source of truth for how a change maps to a version bump.

## Version surfaces

| Surface | Where | Meaning |
| ------- | ----- | ------- |
| **SDK version** | `Client::VERSION` + git tag `vX.Y.Z` | Canonical SemVer for the SDK and CHANGELOG. Composer/Packagist resolve versions from git tags. |
| **Targeted server contract** | this document + the vendored `docs/openapi.yaml` (v1) and `docs/openapi-v2.yaml` (v2) | Which Octonomy REST contract the SDK is written against: `/api/v2` by default and `/api/v1` on request, both as served since server `2.0.0` (unchanged through `3.x`). Both specs are the server's own files at tag **`v3.2.1`**, the release the captured fixtures come from too. Each one's header lists where it diverges from what the server sends. |
| **Captured server release** | `resources/fixtures/manifest.json` | The server build the unit suite's response bodies, and `Testing\FixtureClient`'s, were captured from (currently `3.2.1`, the harness pin). It moves only in a reviewed fixture-refresh PR. |

The SDK versions **independently** of the Octonomy server. `make version-check` asserts `Client::VERSION`
matches the latest `CHANGELOG.md` release heading. Before the first release there is no such heading, so
it checks instead that `Client::VERSION` is still the placeholder; `make release-check` runs it in a
strict mode that fails without a heading.

## Before the first release

Nothing has been released yet: there is no git tag, GitHub release, or Packagist version, and
`CHANGELOG.md` has only an `[Unreleased]` section. `Client::VERSION` (`0.1.0`) is a placeholder that the
first release PR replaces; it does not name a release.

The first releases are planned as two lines ([epic #4](https://github.com/octoverse-id/octonomy-php/issues/4)): `2.0.0` from `main` for PHP 8.2+, and `1.0.0`
from `support/php7.4` for PHP 7.4+. No `0.x` version will be released. Until then the public API may
change without a deprecation path, and every user-facing change is still recorded under `[Unreleased]`.
The release-line guard holds each line to its version range and its `php` constraint, on the release
PR and on the tag ([release.md](release.md#the-release-line-guard)).

## Release lines and support

One package, `octoverse-id/octonomy-php`, ships as two lines, and Composer picks one by your PHP
version:

| Line | Branch | PHP | Guzzle | What it gets |
| ---- | ------ | --- | ------ | ------------ |
| 2.x | `main` | 8.2 and later | 7.8 or later, or 8 | Every feature and fix |
| 1.x | `support/php7.4` | 7.4 and later | 7.8 or later 7.x | Until `1.0.0`, what `main` ships for the current `/api/v2`; after it, security fixes and major bug fixes only |

| Your PHP | `composer require octoverse-id/octonomy-php` installs |
| -------- | ----------------------------------------------------- |
| 7.4, 8.0 or 8.1 | 1.x, the only line it can install |
| 8.2 or later | 2.x. Require `^1.0` to stay on 1.x, or `"^1.0 \|\| ^2.0"` to accept either |

Either line also accepts any PSR-18 client in place of Guzzle, but installs Guzzle regardless.

**What 1.x gets.** `support/php7.4` was generated from `main` once
([#29](https://github.com/octoverse-id/octonomy-php/issues/29)) and is never regenerated. Its `1.0.0`
carries every feature `main` ships for the current `/api/v2`, the contract the vendored
`docs/openapi-v2.yaml` records. After `1.0.0` the 1.x line takes security fixes and major bug fixes
only. New features, support for later changes to the API included, ship on 2.x alone
([#33](https://github.com/octoverse-id/octonomy-php/issues/33)). A fix both lines need lands on `main`
first and reaches `support/php7.4` as a pull request into it.

**How long 1.x is supported: indefinitely.** It is a product line for applications that still run PHP
7.4, not a bridge for one application's upgrade, so it has no end date. In practice it stays current
only while its dependencies support PHP 7.4, and Guzzle 7 is the one that binds. If a Guzzle 7 release
drops PHP 7.4, Composer keeps a PHP 7.4 application on the last release that supports it, which gets
no further Guzzle fixes, and that is the line's effective end.

**No runtime notice.** Neither line warns at runtime that you should move. An `E_USER_DEPRECATED` from
a working client would break applications whose error handler turns notices into exceptions, and
would repeat on every request news that is never urgent. The signal is this page, the
[upgrade guide](upgrading-1-to-2.md), and its opt-in Rector rule, which runs only when you run it.

## Bump rules

Decide the bump from the **most significant** change in the release.

### PATCH — `x.y.Z`
Backward-compatible **bug fixes**. No change to the public API.
- Examples: fix a header, correct envelope decoding, fix a query param name.

### MINOR — `x.Y.0`
Backward-compatible **additions** to the public API.
- Examples: a new resource service, a new method, a new optional property on a `*Params`/`*Create` DTO,
  a new exception subclass.
- Existing callers keep working unchanged.

### MAJOR — `X.0.0`
Backward-**incompatible** changes to the public API.
- Examples: removing/renaming a public class or method, changing a method signature or a model's
  property types.

## `Testing\FixtureClient`

Its methods, and which endpoints it answers, are public API under the bump rules above: no longer
answering an endpoint is a major change. The bodies it answers with are not. They are what the captured
server release sent, and a fixture refresh can change any value in them in any release, recorded in
`CHANGELOG.md`. A test that asserts a literal from the corpus, such as the captured tag's slug, may
need updating after one.

## The Rector rule

`Octoverse\Octonomy\Rector\FlagModelPropertyWriteRector`, which finds writes to model properties
before a move from 1.x to 2.x ([upgrading-1-to-2.md](upgrading-1-to-2.md#finding-writes-before-you-move)),
is public API under the bump rules above: its class name, that it runs on Rector 2, and that it marks
each write it finds with a comment starting `TODO octonomy-php 2.x:`. The rest of the comment's wording
is not, and it can learn to find more writes in any minor release.

## Relationship to the server's API version

`/api/v2` became the server's primary surface at server `2.0.0`, adding the merchant/sub-tenant
namespace axis. `/api/v1` is still served and not deprecated, and server `3.x` changed neither REST
contract; there is no `/api/v3`. The SDK calls v2 unless a client is constructed with
`api_version` v1, which a server older than `2.0.0` needs. Server minor/patch releases that keep these
contracts are additive and require at most a **minor** SDK bump to surface new fields or endpoints. A
new server URL contract would be tracked by a corresponding major SDK effort.

## Where this shows up

- **Per PR:** keep `CHANGELOG.md` `[Unreleased]` current. Do **not** bump `Client::VERSION` in
  feature/fix PRs.
- **At release time:** the version bump in `Client::VERSION` and the git tag happen in a dedicated
  release PR — see the runbook in [`release.md`](release.md).
