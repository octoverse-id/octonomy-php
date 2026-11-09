# Release Runbook

The SDK is distributed via Packagist/Composer, which resolves versions from git tags `vX.Y.Z`. There is
nothing to upload — tagging the repository (and the Packagist webhook) publishes the release.

## Versioning

See [versioning.md](versioning.md). `Client::VERSION` is canonical and must match the latest
`CHANGELOG.md` release heading (`make version-check`). Nothing has been released yet; until the first
release, `Client::VERSION` is the `0.1.0` placeholder, and `make version-check` passes without a heading
only while it keeps that value.

## Pre-release gate

```bash
make release-check
```

This runs `cs` (php-cs-fixer), `stan` (phpstan), `test` (phpunit), and `version-check` in strict mode,
which fails if `CHANGELOG.md` has no release heading. All must pass.

## Cutting a release

1. **Branch:** `git switch -c release/<version>` (e.g. `release/2.0.0`).
2. **Bump the version:** set `Client::VERSION` in `src/Client.php` to the new `X.Y.Z`.
3. **Update the CHANGELOG:** move `[Unreleased]` items under a new `## [X.Y.Z] - <date>` heading and
   refresh the links at the bottom. On the first release, `[Unreleased]` still points at
   `commits/main`: repoint it to `compare/vX.Y.Z...HEAD` and add `[X.Y.Z]` pointing at
   `releases/tag/vX.Y.Z`. Both resolve once step 6 completes (tag pushed and GitHub release created).
4. **Run the gate:** `make release-check`.
5. **Open the release PR**, get it reviewed, and merge to `main`.
6. **Tag the merge commit and publish:**
   ```bash
   git tag -a v<version> -m "v<version>"
   git push origin v<version>
   gh release create v<version> --title "v<version>" --notes-from-tag
   ```
   The `v` prefix is the Packagist/Composer convention.
7. **Verify** the version is resolvable once Packagist has indexed it:
   ```bash
   composer show octoverse-id/octonomy-php --all | grep versions
   ```
8. Close the milestone/issue and delete the release branch.

## Packagist

The package is registered at https://packagist.org/packages/octoverse-id/octonomy-php (submitted
2026-11-09, [#11](https://github.com/octoverse-id/octonomy-php/issues/11)), so a release needs only
its tag.

- **Owner:** Packagist user `yokz`, the package's only maintainer. Packagist has no organisations;
  another maintainer is added on the package page.
- **Webhook:** the repository's GitHub webhook posts push events only, as `application/json`, to
  `https://packagist.org/api/github?username=yokz`, signed with that user's **Safe API Token**.
  Packagist lets a safe-token hook schedule a crawl but never delete, so a leaked secret cannot remove
  a version. The cost: a deleted branch's `dev-*` version is not removed by that branch's delete event.
- **If the owner changes,** recreate the hook with the new owner's username in the URL and their Safe
  API Token as the secret. Regenerating the owner's safe token also breaks the hook until its secret
  is updated.
- **Checking the hook:** each push should show a `202` delivery in the repository's webhook settings
  (`gh api repos/octoverse-id/octonomy-php/hooks/<id>/deliveries`), and Packagist's `dev-main`
  should move to the pushed commit. A `403` "Signature verification failed" means the secret is not
  the token of the user named in the URL.
- **Never push a test tag.** Packagist publishes every version tag it sees, and a published version
  number is spent for good: no `0.x` release exists, and the first releases are `1.0.0` and `2.0.0`.
  Prove the hook with an ordinary push to `main`. Branches appear as `dev-<branch>` versions, which
  Composer installs only when asked for explicitly.

## Server contract changes

If a release targets a new Octonomy server contract, refresh the vendored `docs/openapi.yaml` and
`docs/openapi-v2.yaml` ([development.md](development.md#keeping-the-contract-current)), reconcile models,
and update the "targeted server contract" note in [versioning.md](versioning.md) in the same release PR.
