# Release Runbook

The SDK is distributed via Packagist/Composer, which resolves versions from git tags `vX.Y.Z`. There is
nothing to upload — tagging the repository (and the Packagist webhook) publishes the release.

## Versioning

See [versioning.md](versioning.md). `Client::VERSION` is canonical and must match the latest
`CHANGELOG.md` release heading (`make version-check`).

## Pre-release gate

```bash
make release-check
```

This runs `cs` (php-cs-fixer), `stan` (phpstan), `test` (phpunit), and `version-check`. All must pass.

## Cutting a release

1. **Branch:** `git switch -c release/<version>` (e.g. `release/0.2.0`).
2. **Bump the version:** set `Client::VERSION` in `src/Client.php` to the new `X.Y.Z`.
3. **Update the CHANGELOG:** move `[Unreleased]` items under a new `## [X.Y.Z] - <date>` heading and
   refresh the compare links at the bottom.
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

## First publish

Submit the package once at https://packagist.org/packages/submit (or via the Packagist API) and enable
the GitHub webhook so future tags auto-update. Subsequent releases need only the tag.

## Server contract changes

If a release targets a new Octonomy server contract, refresh the vendored `docs/openapi.yaml`,
reconcile models, and update the "targeted server contract" note in [versioning.md](versioning.md) in
the same release PR.
