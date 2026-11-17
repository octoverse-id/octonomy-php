#!/bin/sh
# Fixture tests for scripts/release-line-guard.sh.
#
# The guard is the release gate for versions that cannot be recalled, so it gets tests of its own.
# Without them CI only ever runs its ordinary-PR path: every pull request into main comes from a
# feature/... or fix/... branch, so the release-PR and tag blocks would run for the first time on
# the one release they exist to stop. octonomy-go found exactly that with the guard this one ports.
#
# Each case builds a throwaway tree (composer.json, src/Client.php, CHANGELOG.md), runs the guard
# there under a synthetic GitHub event environment, and asserts both the exit status and that the
# output names the right thing. The exit status alone would pass a guard that fails for the wrong
# reason, or passes because it never reached the block under test.
#
# Usage: scripts/release-line-guard-test.sh   (or `make release-line-guard-test`)
#
# POSIX sh, plus git and php (the guard's own needs), and mktemp, which is not POSIX but is present
# everywhere this runs and is the only safe way to get a temporary directory.

# $PR_MAIN, $PR_COMPAT and $ARGS are expanded unquoted on purpose, to split into their words.
# shellcheck disable=SC2086
set -eu

GUARD=$(cd "$(dirname "$0")" && pwd)/release-line-guard.sh
test -x "$GUARD" || { echo "not executable: $GUARD"; exit 1; }

WORK=$(mktemp -d "${TMPDIR:-/tmp}/release-line-guard-test-XXXXXX")
trap 'rm -rf "$WORK"' EXIT INT TERM
# git and the guard run with this as HOME, so no global git config (signing, hooks, templates) can
# change what a fixture commit holds or what the guard reads.
mkdir "$WORK/home"

passed=0
failed=0

MODERN='>=8.2'
COMPAT='>=7.4'

# fixture <php|-> <version|-> <changelog-version|-|unreleased>
# Writes a tree into $WORK/tree. "-" omits the file; for the CHANGELOG, "unreleased" writes one with
# only an [Unreleased] section. composer.json always carries config.platform.php, BEFORE require, as
# compat/composer.json does: a reader that takes the first "php" key gets 7.4.33, not the constraint.
fixture() {
	rm -rf "$WORK/tree"
	mkdir -p "$WORK/tree/src"
	if [ "$1" != "-" ]; then
		cat > "$WORK/tree/composer.json" << EOF
{
    "name": "octoverse-id/octonomy-php",
    "config": {
        "platform": {
            "php": "7.4.33"
        }
    },
    "require": {
        "php": "$1",
        "ext-json": "*"
    }
}
EOF
	fi
	if [ "$2" != "-" ]; then
		client "public const VERSION = '$2';"
	fi
	case "$3" in
	-) ;;
	unreleased) printf '# Changelog\n\n## [Unreleased]\n\n### Added\n- Something.\n' > "$WORK/tree/CHANGELOG.md" ;;
	*) printf '# Changelog\n\n## [Unreleased]\n\n## [%s] - 2026-11-17\n\n### Added\n- Something.\n' "$3" > "$WORK/tree/CHANGELOG.md" ;;
	esac
}

# client <declaration> writes src/Client.php around one line of PHP.
client() {
	printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace Octoverse\\Octonomy;\n\nfinal class Client\n{\n    %s\n}\n' "$1" > "$WORK/tree/src/Client.php"
}

# composer <raw-json> replaces the fixture's composer.json.
composer() {
	printf '%s\n' "$1" > "$WORK/tree/composer.json"
}

# git_fixture <branch> commits the current fixture to a new repo checked out on <branch>, for the
# guard's local paths, which ask git for the branch and, with --tag, for HEAD. A commit is required:
# `git rev-parse --abbrev-ref HEAD` has nothing to resolve in a repo with no commits.
git_fixture() {
	(
		cd "$WORK/tree" || exit 1
		export HOME="$WORK/home" GIT_CONFIG_NOSYSTEM=1
		git init -q .
		git symbolic-ref HEAD "refs/heads/$1"
		git add -A
		git -c user.email=test@example.test -c user.name=test -c commit.gpgsign=false commit -q -m fixture
	)
}

# check <description> <expected-rc> <expected-substring|-> <VAR=value>...
# Runs the guard in the fixture with only the given environment, plus $ARGS as its arguments.
# env -i also drops GITHUB_ACTIONS, so the output is the plain BLOCKING:/note: form these match.
ARGS=""
check() {
	desc=$1
	want_rc=$2
	want_text=$3
	shift 3

	# $ARGS is split on purpose: it holds a flag and a tag name, neither with spaces.
	out=$(cd "$WORK/tree" && env -i PATH="$PATH" HOME="$WORK/home" GIT_CONFIG_NOSYSTEM=1 "$@" "$GUARD" $ARGS 2>&1) && rc=0 || rc=$?

	if [ "$rc" != "$want_rc" ]; then
		printf 'FAIL  %s\n      expected rc=%s, got rc=%s\n' "$desc" "$want_rc" "$rc"
		printf '%s\n' "$out" | sed 's/^/        /'
		failed=$((failed + 1))
		return 0
	fi
	if [ "$want_text" != "-" ]; then
		case "$out" in
		*"$want_text"*) ;;
		*)
			printf 'FAIL  %s\n      rc=%s as expected, but the output never says %s\n' "$desc" "$rc" "\"$want_text\""
			printf '%s\n' "$out" | sed 's/^/        /'
			failed=$((failed + 1))
			return 0
			;;
		esac
	fi
	printf 'ok    %s\n' "$desc"
	passed=$((passed + 1))
}

# Two assignments each, expanded unquoted wherever they are used so that they split into both.
PR_MAIN="GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=main"
PR_COMPAT="GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=support/php7.4"

echo "--- ordinary pull requests and pushes ---"
fixture "$MODERN" 0.1.0 unreleased
# Asserts what the guard read, not only rc=0: a clean exit also happens when the guard misreads
# composer.json and checks nothing. php= is the constraint, not config.platform.php's 7.4.33.
check "an ordinary PR into main" 0 "php=>=8.2 version=0.1.0 branch=main head=feature/30-x" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=main GITHUB_HEAD_REF=feature/30-x
check "an ordinary PR into main says the tree fits the line" 0 "as the 2.x line (main, php >=8.2) must" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=main GITHUB_HEAD_REF=feature/30-x
check "the 0.1.0 placeholder is tolerated outside a release" 0 "a 0.x placeholder" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=main GITHUB_HEAD_REF=feature/30-x
check "a push to main" 0 "branch=main head=none" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
check "pull_request_target is read the same" 0 "branch=main head=feature/30-x" \
	GITHUB_EVENT_NAME=pull_request_target GITHUB_BASE_REF=main GITHUB_HEAD_REF=feature/30-x
check "BLOCK: a PR into the 1.x line carrying main's composer.json" 1 "1.x release from this tree would not install on PHP 7.4" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=support/php7.4 GITHUB_HEAD_REF=fix/31-x

fixture "$COMPAT" 0.1.0 unreleased
check "an ordinary PR into support/php7.4" 0 "as the 1.x line (support/php7.4, php >=7.4) must" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=support/php7.4 GITHUB_HEAD_REF=fix/31-x
check "a push to support/php7.4" 0 "branch=support/php7.4" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/support/php7.4
check "BLOCK: php >=7.4 on main (a PR)" 1 "branch main is the 2.x line (main, php >=8.2), but composer.json requires php \`>=7.4\`" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=main GITHUB_HEAD_REF=feature/30-x
check "BLOCK: php >=7.4 on main (a push)" 1 "would install on PHP 7.4" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main

echo "--- Client::VERSION against the tree, on every run ---"
fixture "$COMPAT" 2.0.0 2.0.0
check "BLOCK: a 2.x Client::VERSION on the 1.x line" 1 "Client::VERSION is 2.0.0, a 2.x version, but composer.json requires php \`>=7.4\`" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/support/php7.4
fixture "$MODERN" 1.0.0 1.0.0
check "BLOCK: a 1.x Client::VERSION on main" 1 "Client::VERSION is 1.0.0, a 1.x version" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=main GITHUB_HEAD_REF=feature/30-x
fixture "$MODERN" 2.3.1 2.3.1
check "a released 2.x Client::VERSION on main" 0 "release-line-guard: OK" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=main GITHUB_HEAD_REF=feature/40-x
fixture "$COMPAT" 1.4.2 1.4.2
check "a released 1.x Client::VERSION on support/php7.4" 0 "release-line-guard: OK" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=support/php7.4 GITHUB_HEAD_REF=fix/41-x
fixture "$MODERN" 2.0 unreleased
check "BLOCK: a Client::VERSION that is not MAJOR.MINOR.PATCH" 1 "Client::VERSION is \`2.0\`, which is not MAJOR.MINOR.PATCH" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main

echo "--- the tree must be one line's ---"
fixture '>=8.1' 0.1.0 unreleased
check "BLOCK: a constraint that is neither line's" 1 "requires php \`>=8.1\`, which is neither line's constraint" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
fixture '^8.2' 0.1.0 unreleased
check "BLOCK: an equivalent-looking constraint is still not the line's" 1 "neither line's constraint" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=main GITHUB_HEAD_REF=feature/30-x
fixture "$MODERN" 0.1.0 unreleased
composer '{"name": "octoverse-id/octonomy-php", "config": {"platform": {"php": "8.2.0"}}, "require": {"ext-json": "*"}}'
check "BLOCK: no require.php (config.platform.php is not one)" 1 "declares no php constraint" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
composer '{"require": {"php": [">=8.2"]}}'
check "BLOCK: require.php that is not a string" 1 "is not a string" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
composer '{"require": {"php": ">=8.2",}}'
check "BLOCK: composer.json that is not JSON" 1 "is not valid JSON" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
composer '{"require-dev": {"php": ">=8.2"}, "require": {"php": ">=7.4"}}'
check "require.php is read, not require-dev's" 0 "php=>=7.4" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/support/php7.4
composer '{"require": {"php": ">=8.2"}}'
check "a one-line composer.json is read" 0 "php=>=8.2" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
fixture - 0.1.0 unreleased
check "BLOCK: no composer.json at all" 1 "no composer.json in" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
fixture "$MODERN" - unreleased
check "BLOCK: no src/Client.php" 1 "no src/Client.php in" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
client "public const NAME = 'octonomy-php';"
check "BLOCK: a Client.php with no VERSION" 1 "declares no Client::VERSION the guard can read" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main

echo "--- reading Client::VERSION ---"
fixture "$MODERN" 2.0.0 2.0.0
client 'const VERSION = "2.0.0";'
check "double quotes and no visibility" 0 "version=2.0.0" GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v2.0.0
client "final public const VERSION = '2.0.0'; // the release"
check "final, and a trailing comment" 0 "version=2.0.0" GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v2.0.0
client "// public const VERSION = '1.0.0';
    public const VERSION = '2.0.0';"
check "a commented-out VERSION above the real one is not read" 0 "version=2.0.0" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v2.0.0
client "public const VERSION = '2.0.0
1.0.0';"
check "BLOCK: a VERSION literal spanning two lines" 1 "which is not MAJOR.MINOR.PATCH" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main

echo "--- release PRs: the gate a fix can still reach ---"
fixture "$MODERN" 2.0.0 2.0.0
check "a correct release PR on main" 0 "the base branch, composer.json, Client::VERSION and CHANGELOG.md all agree" \
	$PR_MAIN GITHUB_HEAD_REF=release/2.0.0
check "release/v2.0.0 names the same release" 0 "release PR for 2.0.0: the base branch" \
	$PR_MAIN GITHUB_HEAD_REF=release/v2.0.0
check "BLOCK: a 2.x release PR retargeted at support/php7.4" 1 "2.x releases are cut from \`main\`" \
	$PR_COMPAT GITHUB_HEAD_REF=release/2.0.0
check "BLOCK: a release PR into a branch that is no line" 1 "targets \`feature/30-x\`" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=feature/30-x GITHUB_HEAD_REF=release/2.0.0
check "a release PR with no base ref says what it skipped" 0 "target-branch check is skipped" \
	GITHUB_EVENT_NAME=pull_request GITHUB_HEAD_REF=release/2.0.0

fixture "$COMPAT" 1.0.0 1.0.0
check "a correct release PR on support/php7.4" 0 "the base branch, composer.json, Client::VERSION and CHANGELOG.md all agree" \
	$PR_COMPAT GITHUB_HEAD_REF=release/1.0.0
check "BLOCK: a 1.x release PR retargeted at main" 1 "1.x releases are cut from \`support/php7.4\`" \
	$PR_MAIN GITHUB_HEAD_REF=release/1.0.0
check "BLOCK: a 2.x release PR from the 1.x line's tree" 1 "release PR for 2.0.0, a 2.x version, but composer.json requires php \`>=7.4\`" \
	$PR_MAIN GITHUB_HEAD_REF=release/2.0.0

fixture "$COMPAT" 2.0.0 2.0.0
# The reverse mistag with every number made to agree: Client::VERSION and the CHANGELOG say 2.0.0
# and the PR targets main. Only the tree's constraint is left to give it away, and it does.
check "BLOCK: the reverse mistag with every version bumped to match" 1 "installs on PHP 7.4 and wins as the highest version" \
	$PR_MAIN GITHUB_HEAD_REF=release/2.0.0

fixture "$MODERN" 1.0.0 1.0.0
check "BLOCK: a 1.x release PR from main's tree" 1 "release PR for 1.0.0, a 1.x version, but composer.json requires php \`>=8.2\`" \
	$PR_COMPAT GITHUB_HEAD_REF=release/1.0.0

fixture "$MODERN" 0.1.0 unreleased
check "BLOCK: a release PR that did not bump Client::VERSION" 1 "release PR for 2.0.0, but Client::VERSION is 0.1.0" \
	$PR_MAIN GITHUB_HEAD_REF=release/2.0.0
check "BLOCK: ... and has no released CHANGELOG heading" 1 "CHANGELOG.md has no released-version heading" \
	$PR_MAIN GITHUB_HEAD_REF=release/2.0.0
check "BLOCK: ... even with no base ref in context" 1 "Client::VERSION is 0.1.0" \
	GITHUB_EVENT_NAME=pull_request GITHUB_HEAD_REF=release/2.0.0
fixture "$MODERN" 2.0.0 1.9.0
check "BLOCK: a stale CHANGELOG heading" 1 "the latest CHANGELOG.md heading is [1.9.0]" \
	$PR_MAIN GITHUB_HEAD_REF=release/2.0.0
fixture "$MODERN" 2.0.0 -
check "BLOCK: a release PR that deleted the CHANGELOG" 1 "there is no CHANGELOG.md" \
	$PR_MAIN GITHUB_HEAD_REF=release/2.0.0

fixture "$MODERN" 2.0.0 2.0.0
for bad in release/2.0 release/foo release/30-guard release/2.0.0-rc.1 release/v02.0.0 release/vintage; do
	check "BLOCK: release branch $bad names no version" 1 "release branch $bad does not name a version" \
		$PR_MAIN GITHUB_HEAD_REF="$bad"
done
fixture "$MODERN" 0.2.0 0.2.0
check "BLOCK: a 0.x release PR" 1 "no line publishes 0.x" \
	$PR_MAIN GITHUB_HEAD_REF=release/0.2.0

fixture "$MODERN" 2.1.0 2.1.0
check "a 2.x minor release PR" 0 "all agree" $PR_MAIN GITHUB_HEAD_REF=release/2.1.0
fixture "$MODERN" 3.0.0 3.0.0
check "a later major is main's too" 0 "all agree" $PR_MAIN GITHUB_HEAD_REF=release/3.0.0
fixture "$COMPAT" 1.3.0 1.3.0
check "a 1.x minor release PR" 0 "all agree" $PR_COMPAT GITHUB_HEAD_REF=release/1.3.0
fixture "$COMPAT" 99999999999999999999.0.0 99999999999999999999.0.0
check "BLOCK: a major too long for shell arithmetic is still not 1.x" 1 "2.x releases are cut from \`main\`" \
	$PR_COMPAT GITHUB_HEAD_REF=release/99999999999999999999.0.0

echo "--- local runs: the checked-out branch is the head, not the base ---"
# Runbook step `make release-check` runs on the release branch, where there is no base. Reading the
# checked-out branch as the base made octonomy-go's guard report that the release "targets
# release/v1.0.1", so that step could not pass.
fixture "$MODERN" 2.0.0 2.0.0
git_fixture release/2.0.0
check "a local run on release/2.0.0" 0 "target-branch check is skipped" \
	GITHUB_EVENT_NAME= GITHUB_REF=
fixture "$MODERN" 0.1.0 unreleased
git_fixture release/2.0.0
check "BLOCK: a local run on a release branch still checks Client::VERSION" 1 "Client::VERSION is 0.1.0" \
	GITHUB_EVENT_NAME= GITHUB_REF=
fixture "$COMPAT" 0.1.0 unreleased
git_fixture main
check "BLOCK: a local run on main sees the 1.x line's composer.json" 1 "branch main is the 2.x line" \
	GITHUB_EVENT_NAME= GITHUB_REF=
fixture "$MODERN" 0.1.0 unreleased
git_fixture feature/30-x
check "a local run on a feature branch checks the tree alone" 0 "branch=feature/30-x head=none" \
	GITHUB_EVENT_NAME= GITHUB_REF=
check "no git repo at all" 0 "release-line-guard: OK" GITHUB_EVENT_NAME= GITHUB_REF= GIT_DIR=/nonexistent

echo "--- tag pushes: detection once the ref is public ---"
fixture "$MODERN" 2.0.0 2.0.0
check "tag v2.0.0 on main's tree" 0 "tag v2.0.0 matches Client::VERSION (2.0.0) and the 2.x line" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v2.0.0
check "BLOCK: tag v2.0.1 against Client::VERSION 2.0.0" 1 "tag v2.0.1 does not match Client::VERSION (2.0.0)" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v2.0.1
check "BLOCK: a 1.x tag on main" 1 "tag v1.0.0 is a 1.x version, but the tree it points at requires php \`>=8.2\`" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v1.0.0
check "BLOCK: a 0.x tag" 1 "publishes a 0.x version" GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v0.1.0

fixture "$COMPAT" 1.0.0 1.0.0
check "tag v1.0.0 on support/php7.4's tree" 0 "and the 1.x line (support/php7.4, php >=7.4)" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v1.0.0
check "BLOCK: a 2.x tag on support/php7.4" 1 "tag v2.0.0 is a 2.x version, but the tree it points at requires php \`>=7.4\`" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v2.0.0
check "BLOCK: ... which also does not match Client::VERSION" 1 "tag v2.0.0 does not match Client::VERSION (1.0.0)" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v2.0.0
fixture "$COMPAT" 2.0.0 2.0.0
check "BLOCK: a 2.x tag on support/php7.4 with Client::VERSION bumped to match" 1 "every PHP 8.2+ consumer, who would get the 7.4 line's mutable models" \
	GITHUB_EVENT_NAME=push GITHUB_REF=refs/tags/v2.0.0

fixture "$MODERN" 2.0.0 2.0.0
# Composer reads all of these as versions (it accepts no v, a capital V, drops `release-`, strips
# +metadata, and takes two- and four-part numbers and dates), so Packagist would publish each one.
for bad in 2.0.0 V2.0.0 release-2.0.0 release-v2.0.0 v2.0 v2.0.0.0 v2.0.0-rc.1 v2.0.0-RC1 v2.0.0+build.1 v02.0.0 v2.0.0-dev 2026.11.17; do
	check "BLOCK: tag $bad, which Composer would publish" 1 "tag $bad: Composer reads it as a version" \
		GITHUB_EVENT_NAME=push GITHUB_REF="refs/tags/$bad"
done
for skip in docs-snapshot latest vendor-drop; do
	check "tag $skip is no version to Composer" 0 "tag $skip is not a version to Composer" \
		GITHUB_EVENT_NAME=push GITHUB_REF="refs/tags/$skip"
done

echo "--- --tag: the same checks before the push ---"
fixture "$MODERN" 2.0.0 2.0.0
git_fixture main
ARGS="--tag v2.0.0"
check "--tag v2.0.0 on main's merge commit" 0 "tag v2.0.0 matches Client::VERSION" \
	GITHUB_EVENT_NAME= GITHUB_REF=
check "--tag ignores a GitHub context it was not asked about" 0 "branch=main head=none tag=v2.0.0" \
	GITHUB_EVENT_NAME=pull_request GITHUB_BASE_REF=support/php7.4 GITHUB_HEAD_REF=release/1.0.0
ARGS="--tag=v2.0.0"
check "--tag=v2.0.0 is the same" 0 "tag=v2.0.0" GITHUB_EVENT_NAME= GITHUB_REF=
ARGS="--tag v1.0.0"
check "BLOCK: --tag v1.0.0 on main" 1 "tag v1.0.0 is a 1.x version" GITHUB_EVENT_NAME= GITHUB_REF=

fixture "$COMPAT" 1.0.0 1.0.0
git_fixture support/php7.4
ARGS="--tag v2.0.0"
check "BLOCK: --tag v2.0.0 on support/php7.4" 1 "tag v2.0.0 is a 2.x version, but the tree it points at requires php \`>=7.4\`" \
	GITHUB_EVENT_NAME= GITHUB_REF=
ARGS="--tag v1.0.0"
check "--tag v1.0.0 on support/php7.4" 0 "tag v1.0.0 matches Client::VERSION" GITHUB_EVENT_NAME= GITHUB_REF=
# The tag goes on HEAD, so the working tree is not what it publishes.
client "public const VERSION = '1.0.1';"
check "BLOCK: --tag with Client.php changed since HEAD" 1 "src/Client.php differs from HEAD" \
	GITHUB_EVENT_NAME= GITHUB_REF=
fixture "$COMPAT" 1.0.0 1.0.0
git_fixture support/php7.4
printf '{"require": {"php": ">=7.4"}}\n' > "$WORK/tree/composer.json"
check "BLOCK: --tag with composer.json changed since HEAD" 1 "composer.json differs from HEAD" \
	GITHUB_EVENT_NAME= GITHUB_REF=
fixture "$COMPAT" 1.0.0 1.0.0
check "BLOCK: --tag outside a git checkout" 1 "is not a git checkout with a commit" \
	GITHUB_EVENT_NAME= GITHUB_REF= GIT_DIR=/nonexistent
ARGS=""

echo "--- usage ---"
fixture "$MODERN" 0.1.0 unreleased
ARGS="--tag"
check "--tag with no value is a usage error" 2 "--tag needs the tag you are about to push" GITHUB_EVENT_NAME= GITHUB_REF=
ARGS="--tag="
check "--tag= is a usage error" 2 "--tag needs the tag you are about to push" GITHUB_EVENT_NAME= GITHUB_REF=
ARGS="--release"
check "an unknown argument is a usage error" 2 "unknown argument: --release" GITHUB_EVENT_NAME= GITHUB_REF=
ARGS=""

echo "--- is_version grammar, exercised directly ---"
# Sourced from the guard rather than re-declared, so this cannot drift from the implementation. It
# also reaches the multi-line case, which no git ref can carry but Client::VERSION could.
_NL='
'
eval "$(sed -n '/^is_version()/,/^}/p' "$GUARD")"

version_case() {
	want=$1
	value=$2
	if is_version "$value"; then got=accept; else got=reject; fi
	if [ "$got" = "$want" ]; then
		printf 'ok    is_version %-22s -> %s\n' "$(printf '%s' "$value" | tr '\n' '~')" "$got"
		passed=$((passed + 1))
	else
		printf 'FAIL  is_version %-22s -> %s, wanted %s\n' "$(printf '%s' "$value" | tr '\n' '~')" "$got" "$want"
		failed=$((failed + 1))
	fi
}

for good in 0.0.0 0.1.0 1.0.0 2.0.0 10.20.30 2.0.100 100.0.0; do
	version_case accept "$good"
done
for bad in 01.0.0 1.02.3 1.0.03 1.0 1 1.0.0.0 1.a.3 1.0.0- 1.0.0-rc.1 1.0.0+meta v1.0.0 "" " 1.0.0" "1.0.0 "; do
	version_case reject "$bad"
done
version_case reject "1.0.0${_NL}junk"
version_case reject "junk${_NL}1.0.0"

printf '\n%s passed, %s failed\n' "$passed" "$failed"
[ "$failed" = "0" ] || exit 1
