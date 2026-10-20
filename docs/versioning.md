# Versioning Policy

`octonomy-php` follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). This document is the
source of truth for how a change maps to a version bump.

## Version surfaces

| Surface | Where | Meaning |
| ------- | ----- | ------- |
| **SDK version** | `Client::VERSION` + git tag `vX.Y.Z` | Canonical SemVer for the SDK and CHANGELOG. Composer/Packagist resolve versions from git tags. |
| **Targeted server contract** | this document + the vendored `docs/openapi.yaml` (v1) and `docs/openapi-v2.yaml` (v2) | Which Octonomy REST contract the SDK is written against: `/api/v2` by default and `/api/v1` on request, both as served since server `2.0.0` (unchanged through `3.x`). Both specs are the server's own files at tag **`v3.2.1`**, the release the captured fixtures come from too. Each one's header lists where it diverges from what the server sends. |
| **Captured server release** | `tests/fixtures/captured/manifest.json` | The server build the unit suite's response bodies were captured from (currently `3.2.1`, the harness pin). It moves only in a reviewed fixture-refresh PR. |

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
