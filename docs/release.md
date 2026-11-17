# Release Runbook

The SDK is distributed via Packagist/Composer, which resolves versions from git tags `vX.Y.Z`. There is
nothing to upload — tagging the repository (and the Packagist webhook) publishes the release.

## Versioning

See [versioning.md](versioning.md). `Client::VERSION` is canonical and must match the latest
`CHANGELOG.md` release heading (`make version-check`). Nothing has been released yet; until the first
release, `Client::VERSION` is the `0.1.0` placeholder, and `make version-check` passes without a heading
only while it keeps that value.

## Release lines

One Packagist package, two lines ([epic #4](https://github.com/octoverse-id/octonomy-php/issues/4)).
Composer picks a line for each consumer by the `php` constraint in the `composer.json` a tag points
at, and by nothing else.

| Line | Branch | `composer.json` `require.php` | A release PR comes from and targets |
| ---- | ------ | ----------------------------- | ----------------------------------- |
| 2.x | `main` | `>=8.2` | `main` |
| 1.x | `support/php7.4` | `>=7.4` | `support/php7.4` |

`support/php7.4` does not exist until [#29](https://github.com/octoverse-id/octonomy-php/issues/29)
generates it. No line publishes `0.x`, and later majors (`3.x`) are `main`'s.

## Pre-release gate

```bash
make release-check
```

This runs `cs` (php-cs-fixer), `stan` (phpstan), `test` (phpunit), the
[release-line guard](#the-release-line-guard) and its fixture tests, and `version-check` in strict
mode, which fails if `CHANGELOG.md` has no release heading. All must pass.

## Cutting a release

1. **Branch** from the line's branch: `git switch -c release/<version> <branch>` (e.g.
   `release/2.0.0` from `main`). Every `release/*` branch is read as a release PR, so name it after
   the version and nothing else.
2. **Bump the version:** set `Client::VERSION` in `src/Client.php` to the new `X.Y.Z`.
3. **Update the CHANGELOG:** move `[Unreleased]` items under a new `## [X.Y.Z] - <date>` heading and
   refresh the links at the bottom. On the first release, `[Unreleased]` still points at
   `commits/main`: repoint it to `compare/vX.Y.Z...HEAD` and add `[X.Y.Z]` pointing at
   `releases/tag/vX.Y.Z`. Both resolve once step 6 completes (tag pushed and GitHub release created).
4. **Run the gate:** `make release-check`.
5. **Open the release PR** against the line's branch, get it reviewed, and merge it. Its
   `release-line guard` check must be green: it refuses a PR whose base, `composer.json`,
   `Client::VERSION` and `CHANGELOG.md` do not all name the same release on the same line.
6. **Tag the merge commit and publish.** Check the tag against the commit it will point at first:
   Packagist publishes a tag the moment it is pushed, and nothing after that can take it back.
   ```bash
   git switch <branch> && git pull --ff-only     # the merge commit, with a clean tree
   make release-line-guard TAG=v<version>
   git tag -a v<version> -m "v<version>"
   git push origin v<version>
   gh release create v<version> --title "v<version>" --notes-from-tag
   ```
   The `v` prefix is the Packagist/Composer convention. Tags are `vX.Y.Z` only: no prerelease, and no
   build metadata, which Composer strips (`v2.0.0+build` would publish as `2.0.0`).
7. **Verify** the version is resolvable once Packagist has indexed it:
   ```bash
   composer show octoverse-id/octonomy-php --all | grep versions
   ```
8. Close the milestone/issue and delete the release branch.

## The release-line guard

`scripts/release-line-guard.sh` ([#30](https://github.com/octoverse-id/octonomy-php/issues/30)) stands
in front of the one mistake a release cannot take back. The dangerous mistag is the reverse of the
obvious one: a `2.x` tag cut from `support/php7.4` declares `php >=7.4`, installs on every platform,
wins as the highest version, and ships the 7.4 line's readonly-stripped, mutable models to every
PHP 8.2+ consumer. That branch is the machine-written one, which makes it the likelier mistake.

The guard reads `composer.json`'s `require.php`, `Client::VERSION` and, on a release PR, the latest
`CHANGELOG.md` heading, and requires each signal the context offers to name the same line:

| Context | Compared with the tree | Refused, for example |
| ------- | ---------------------- | -------------------- |
| Every run | `Client::VERSION`'s major (`0.x` is the pre-release placeholder, and no line's) | `Client::VERSION` `2.0.0` beside `php >=7.4` |
| A push, or a pull request's **base** branch | the line that branch is | `php >=7.4` on `main` |
| A release PR (head `release/X.Y.Z`) | the version it names, which must also equal `Client::VERSION` and the CHANGELOG heading, and the base must be that version's line | `release/1.0.0` targeting `main` |
| A tag, pushed or (`--tag`) about to be | the tag's major, and `Client::VERSION` must equal the tag | `v2.0.0` on `support/php7.4`, `v1.0.0` on `main`, `v2.0.1` on `Client::VERSION` `2.0.0` |

A tag carries no branch, so a tag is judged by the tree it points at, which is exactly what Packagist
publishes for it. Every tag Composer would read as a version (`2.0.0`, `V2.0.0`, `release-2.0.0`,
`v2.0`, `v2.0.0-rc.1`, ...) must be exactly `vX.Y.Z`. All of it is blocking: Composer and Packagist
accept any `composer.json` at any tag, so nothing downstream refuses a mistag. Moving a line's floor
means changing `MODERN_PHP` or `COMPAT_PHP` in the script in the same PR.

It runs in three layers:

1. **The release PR, as a gate.** `.github/workflows/release-line.yml` runs the guard and its fixture
   tests on every pull request into a line, including when one is retargeted, and on every push to a
   line. A red run there is fixed by pushing a commit. It blocks the merge only while
   `release-line guard` is a required status check on the line's branch, which is branch-protection
   state this repository cannot show; ask the API:
   `gh api repos/octoverse-id/octonomy-php/branches/main/protection/required_status_checks --jq .contexts`.
2. **The tag, as a check before the push and detection after it.** `make release-line-guard
   TAG=vX.Y.Z` (runbook step 6) runs the tag checks against the checked-out commit and refuses a
   working tree that differs from it. The same workflow runs them again on every tag push, by which
   time the ref is already public: if that run fails, delete the ref at once (`git push origin
   :refs/tags/<tag>`), and publish a corrected version, since the number is spent.
3. **Tag rulesets**, defined in `.github/rulesets/`. `tags-admins-only.json` lets only a repository
   admin create or delete a tag. `tags-never-move.json` refuses moving any tag, with no bypass at all:
   a moved version tag changes what Packagist serves under a number consumers have already locked.
   Both cover every tag, not only `v*`, for the reason above. Apply each once with
   `gh api -X POST repos/octoverse-id/octonomy-php/rulesets --input <file>`; after changing a file,
   `gh api -X PUT repos/octoverse-id/octonomy-php/rulesets/<id> --input <file>`, with the id from
   `gh api repos/octoverse-id/octonomy-php/rulesets`.

`make release-line-guard` without `TAG` checks the tree against the checked-out branch, and `make
check` and `make release-check` both run it with its fixture tests. Those tests
(`scripts/release-line-guard-test.sh`) build throwaway trees and assert the reason each refusal gives,
not only its exit status; a guard that fails for the wrong reason, or passes without reaching the
block under test, fails them.

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
