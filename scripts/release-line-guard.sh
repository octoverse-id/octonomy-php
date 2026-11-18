#!/bin/sh
# Release-line guard: assert that a tree belongs to the release line it is about to be published on.
#
# One Packagist package, two lines (docs/versioning.md, docs/release.md):
#
#   2.x   branch main             composer.json requires php >=8.2
#   1.x   branch support/php7.4   composer.json requires php >=7.4   (generated from main by Rector)
#
# Composer picks a line for a consumer by the `php` constraint in the composer.json each tag points
# at, and by nothing else. So the dangerous mistag is the reverse of the obvious one: a 2.x tag cut
# from support/php7.4 declares php >=7.4, installs on every platform, wins as the highest version,
# and ships the 7.4 line's readonly-stripped, mutable models to every 8.2+ consumer. That branch is
# the machine-written one, which makes it the likelier mistake. Packagist publishes a tag as soon as
# it is pushed, and a published version cannot be recalled.
#
# Ported from octonomy-go's scripts/compat-guard.sh, with one difference that removes its advisory
# half: Go itself refuses a module whose path does not fit its tag's major, so there some checks are
# early signals. Nothing downstream refuses anything here. Composer and Packagist accept any
# composer.json at any tag, so every check below is blocking.
#
# What it reads, from the current directory (run it from the repository root):
#   composer.json    require.php, the signal Composer routes by, so the line the tree belongs to
#   src/Client.php   Client::VERSION, which the client reports in its User-Agent forever
#   CHANGELOG.md     the latest release heading (release PRs only)
# and what it compares them with, which is every signal the context offers:
#   the branch       a push's branch, or a pull request's BASE branch
#   the release      a release PR's head branch, release/X.Y.Z (or release/vX.Y.Z)
#   the tag          a tag push, or --tag before one
#
# It is one of three layers (docs/release.md, "The release-line guard"). On the release PR it is a
# gate: a red run there is fixed by pushing a commit. On a tag push it can only detect, because the
# ref is already public by then. And the tag rulesets in .github/rulesets/ leave creating and
# deleting a tag to an admin, and moving one to nobody.
#
# Usage:
#   scripts/release-line-guard.sh               # infers the context from $GITHUB_* or from git
#   scripts/release-line-guard.sh --tag v2.0.0  # the tag-time checks, against the checked-out
#                                               # commit, before `git push origin v2.0.0`
#
# Exit status: 0 when every check passes, 1 when any fails, 2 on a usage error.
#
# POSIX sh, plus git, sed, grep -E and head, and the php CLI to read composer.json: a sed reading
# JSON could take config.platform.php, which compat/composer.json sets to 7.4.33, for the
# constraint. The php code runs on any PHP from 7.0, so the 7.4 line can run it as written.

set -eu

# A literal newline, for the multi-line guard in is_version.
_NL='
'

MODERN_BRANCH="main"
MODERN_PHP=">=8.2"
COMPAT_BRANCH="support/php7.4"
COMPAT_PHP=">=7.4"

failed=0

note() {
	if [ "${GITHUB_ACTIONS:-}" = "true" ]; then printf '::notice::%s\n' "$1"; else printf 'note: %s\n' "$1"; fi
}
fail() {
	if [ "${GITHUB_ACTIONS:-}" = "true" ]; then printf '::error::%s\n' "$1"; else printf 'BLOCKING: %s\n' "$1"; fi
	failed=1
}
usage() {
	printf 'release-line-guard: %s\nusage: scripts/release-line-guard.sh [--tag vX.Y.Z]\n' "$1" >&2
	exit 2
}

# --- Version grammar -----------------------------------------------------------------------------
#
# MAJOR.MINOR.PATCH, no leading zeros, and nothing else. The obvious glob, `[0-9]*.[0-9]*.[0-9]*`,
# also accepts 1.2.3foo, 1.02.3 and 1.2.3.4.
#
# No prerelease and no build metadata, on purpose. The runbook publishes vX.Y.Z only, and every
# version Packagist sees is spent for good, an RC included; Composer even strips +metadata, so
# v2.0.0+build would publish as 2.0.0. Publishing a prerelease is a decision to make in
# docs/release.md and here together, not something this grammar lets through by default.
is_version() {
	# grep succeeds when ANY line matches, so a multi-line value could smuggle junk past a valid
	# line. A git ref cannot hold a newline, but Client::VERSION is read from a file.
	case "$1" in
	*"$_NL"*) return 1 ;;
	esac
	printf '%s' "$1" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
}

# line_of_version <version> echoes the line that publishes it: compat for 1.x, modern for 2.x and
# every later major, and nothing for 0.x, which no line publishes. The major is matched as a
# string: is_version has already run, so it is digits with no leading zero, and a numeric test
# would overflow on a 20-digit major.
line_of_version() {
	case "${1%%.*}" in
	0) ;;
	1) printf 'compat' ;;
	*) printf 'modern' ;;
	esac
}

line_of_branch() {
	case "$1" in
	"$MODERN_BRANCH") printf 'modern' ;;
	"$COMPAT_BRANCH") printf 'compat' ;;
	esac
}

line_of_php() {
	case "$1" in
	"$MODERN_PHP") printf 'modern' ;;
	"$COMPAT_PHP") printf 'compat' ;;
	esac
}

line_name() {
	case "$1" in
	modern) printf '2.x line (%s, php %s)' "$MODERN_BRANCH" "$MODERN_PHP" ;;
	compat) printf '1.x line (%s, php %s)' "$COMPAT_BRANCH" "$COMPAT_PHP" ;;
	esac
}

line_branch() {
	case "$1" in
	modern) printf '%s' "$MODERN_BRANCH" ;;
	compat) printf '%s' "$COMPAT_BRANCH" ;;
	esac
}

line_major() {
	case "$1" in
	modern) printf '2.x' ;;
	compat) printf '1.x' ;;
	esac
}

# may_be_version <tag> succeeds when Composer may read the tag as a version, which Packagist then
# publishes. Composer drops every `release-` from a tag name and reads what is left as a version when
# it is a number after an optional v or V: 2.0.0, V2.0.0, v2.0, v2.0.0.0, v2.0.0-rc.1, v2.0.0+build
# (published as 2.0.0), and date-shaped names like 2026.11.17. This test is a superset on purpose:
# any tag that starts with a digit after those prefixes. It also takes tags Composer skips, such as
# 2fa-docs or v2.0.0-dev, because Composer's grammar is not this script's to mirror (it has changed
# between Composer releases, and Packagist runs its own copy), and the costs are lopsided: a tag
# refused here needs renaming, while a tag let through could spend a version for good. A tag that
# fails this test is no version to Composer, and nothing below applies to it.
may_be_version() {
	case "$1" in
	*"$_NL"*) return 1 ;;
	esac
	printf '%s' "$1" | sed 's/release-//g' | grep -Eq '^[vV]?[0-9]'
}

# --- Arguments -----------------------------------------------------------------------------------

opt_tag=""
while [ $# -gt 0 ]; do
	case "$1" in
	--tag)
		[ $# -ge 2 ] || usage "--tag needs the tag you are about to push, such as v2.0.0"
		[ -n "$2" ] || usage "--tag needs the tag you are about to push, such as v2.0.0"
		opt_tag=$2
		shift 2
		;;
	--tag=*)
		opt_tag=${1#--tag=}
		[ -n "$opt_tag" ] || usage "--tag needs the tag you are about to push, such as v2.0.0"
		shift
		;;
	*) usage "unknown argument: $1" ;;
	esac
done

# --- Read the tree -------------------------------------------------------------------------------

test -f composer.json || { fail "no composer.json in $(pwd). Run the guard from the repository root."; exit 1; }
command -v php > /dev/null 2>&1 || { fail "the guard reads composer.json with the php CLI, and there is no php on PATH."; exit 1; }

# display_errors goes to stderr so that no warning can become part of the value read. Each exit
# status names one way the read can fail, so the message can say which.
php_status=0
# The $ in the single-quoted php code is PHP's, not the shell's.
# shellcheck disable=SC2016
php_constraint=$(php -d display_errors=stderr -r '
	$raw = @file_get_contents("composer.json");
	if (!is_string($raw)) { exit(3); }
	$json = json_decode($raw, true);
	if (!is_array($json)) { exit(4); }
	if (!isset($json["require"]) || !is_array($json["require"]) || !array_key_exists("php", $json["require"])) { exit(5); }
	if (!is_string($json["require"]["php"])) { exit(6); }
	echo $json["require"]["php"];
') || php_status=$?
case "$php_status" in
0) ;;
3) fail "composer.json could not be read."; exit 1 ;;
4) fail "composer.json is not valid JSON, so the guard cannot tell which line it belongs to. Packagist could not read it either; \`composer validate\` names the error."; exit 1 ;;
5) fail "composer.json declares no php constraint under \"require\". Composer routes between the two lines by that constraint alone, so a tree without one would install on any PHP. Restore \"php\": \"$MODERN_PHP\" on $MODERN_BRANCH or \"$COMPAT_PHP\" on $COMPAT_BRANCH."; exit 1 ;;
6) fail "composer.json's require.php is not a string, so it is no constraint Composer can read. Restore \"php\": \"$MODERN_PHP\" on $MODERN_BRANCH or \"$COMPAT_PHP\" on $COMPAT_BRANCH."; exit 1 ;;
*) fail "php exited $php_status while reading composer.json; its own output above says why."; exit 1 ;;
esac

# Client::VERSION, read with PHP's own tokenizer rather than a pattern over the text, so a comment
# (`/* const VERSION = '2.0.0'; */`), a docblock, or a string that only mentions it is never read in
# its place. It must be declared once, as a single string literal: a computed value such as
# '2.0' . '.0' cannot be read without running the code, and two declarations leave no telling which
# one the client reports. The name is the last plain identifier before `=`, so a typed constant
# (`const string VERSION`, PHP 8.3) reads too, and the loop never runs past the declaration's `;`, so
# `use const` is passed over.
test -f src/Client.php || { fail "no src/Client.php in $(pwd), so nothing says which version this tree is. Run the guard from the repository root."; exit 1; }
version_status=0
# The $ in the single-quoted php code is PHP's, not the shell's.
# shellcheck disable=SC2016
version=$(php -d display_errors=stderr -r '
	$raw = @file_get_contents("src/Client.php");
	if (!is_string($raw)) { exit(3); }
	if (!function_exists("token_get_all")) { exit(7); }
	$sig = array();
	foreach (token_get_all($raw) as $t) {
		if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) { continue; }
		$sig[] = $t;
	}
	$values = array();
	$n = count($sig);
	for ($i = 0; $i < $n; $i++) {
		if (!is_array($sig[$i]) || $sig[$i][0] !== T_CONST) { continue; }
		$name = null;
		for ($j = $i + 1; $j < $n && $sig[$j] !== "=" && $sig[$j] !== ";"; $j++) {
			if (is_array($sig[$j]) && $sig[$j][0] === T_STRING) { $name = $sig[$j][1]; }
		}
		if ($name !== "VERSION" || $j >= $n || $sig[$j] !== "=") { continue; }
		$value = isset($sig[$j + 1]) ? $sig[$j + 1] : null;
		$end = isset($sig[$j + 2]) ? $sig[$j + 2] : null;
		if (!is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING || ($end !== ";" && $end !== ",")) { exit(6); }
		$values[] = substr($value[1], 1, -1);
	}
	if (count($values) === 0) { exit(4); }
	if (count($values) > 1) { exit(5); }
	echo $values[0];
') || version_status=$?
case "$version_status" in
0) ;;
3) fail "src/Client.php could not be read."; exit 1 ;;
4) fail "src/Client.php declares no Client::VERSION the guard can read (expected \`public const VERSION = '2.0.0';\`). Nothing would tie a tag to the code it publishes."; exit 1 ;;
5) fail "src/Client.php declares VERSION more than once, so the guard cannot tell which one the client reports. Declare it once."; exit 1 ;;
6) fail "Client::VERSION in src/Client.php is not a single string literal, so the guard cannot read it without running the code. Write the version as one literal, such as \`public const VERSION = '2.0.0';\`."; exit 1 ;;
7) fail "the guard reads src/Client.php with PHP's tokenizer, and this php has no tokenizer extension (\`php -m\` lists the loaded ones)."; exit 1 ;;
*) fail "php exited $version_status while reading src/Client.php; its own output above says why."; exit 1 ;;
esac

# --- Work out the context ------------------------------------------------------------------------
#
# A pull request is judged by its BASE branch, which is what puts this check on the release PR: a
# release/X.Y.Z PR targets the line it will be tagged on. A tag carries no branch at all, so a tag
# is judged by the tree it points at, which is exactly what Packagist publishes for it.

tag=""
branch=""
head_branch=""
local_branch=""
if [ -n "$opt_tag" ]; then
	tag=$opt_tag
	local_branch=$(git rev-parse --abbrev-ref HEAD 2> /dev/null || true)
else
	case "${GITHUB_EVENT_NAME:-}" in
	pull_request | pull_request_target)
		branch="${GITHUB_BASE_REF:-}"
		head_branch="${GITHUB_HEAD_REF:-}"
		;;
	*)
		case "${GITHUB_REF:-}" in
		refs/tags/*) tag="${GITHUB_REF#refs/tags/}" ;;
		refs/heads/*) branch="${GITHUB_REF#refs/heads/}" ;;
		*) local_branch=$(git rev-parse --abbrev-ref HEAD 2> /dev/null || true) ;;
		esac
		;;
	esac
fi
# A detached HEAD reads as "HEAD", which names no branch.
[ "$local_branch" = "HEAD" ] && local_branch=""

# A local run has no base branch, only the checked-out one. That is the HEAD: on release/2.0.0 it is
# the release under test, never the line it targets, so it must not stand in for the base. Assigning
# it to both made octonomy-go's guard report that a release "targets release/v1.0.1", so its runbook
# step (`make release-check` on the release branch) could not pass. Any other checked-out branch
# doubles as the line under test.
if [ -n "$local_branch" ]; then
	case "$local_branch" in
	release/*) head_branch="$local_branch" ;;
	*) branch="$local_branch" ;;
	esac
fi

release_version=""
is_release_pr=0
case "$head_branch" in
release/v[0-9]*) release_version=${head_branch#release/v}; is_release_pr=1 ;;
release/*) release_version=${head_branch#release/}; is_release_pr=1 ;;
esac

printf 'release-line-guard: php=%s version=%s branch=%s head=%s tag=%s\n' \
	"$php_constraint" "$version" "${branch:-none}" "${head_branch:-none}" "${tag:-none}"

# --- Every run: the tree is one line's, and agrees with its branch and its version ---------------

tree_line=$(line_of_php "$php_constraint")
branch_line=$(line_of_branch "$branch")

if [ -z "$tree_line" ]; then
	fail "composer.json requires php \`$php_constraint\`, which is neither line's constraint: $MODERN_BRANCH requires \`$MODERN_PHP\` and $COMPAT_BRANCH \`$COMPAT_PHP\`. Composer routes between the two lines by this constraint alone, so the guard cannot tell which line this tree publishes. If you are moving a line's floor, change MODERN_PHP or COMPAT_PHP in scripts/release-line-guard.sh in the same PR."
fi

if [ -n "$tree_line" ] && [ -n "$branch_line" ] && [ "$tree_line" != "$branch_line" ]; then
	if [ "$branch_line" = "modern" ]; then
		fail "branch $branch is the $(line_name modern), but composer.json requires php \`$php_constraint\`, the $COMPAT_BRANCH constraint. A 2.x release from this tree would install on PHP 7.4 and ship the 7.4 line's code to every consumer. Most likely a merge or cherry-pick from $COMPAT_BRANCH carried its composer.json here: restore \"php\": \"$MODERN_PHP\"."
	else
		fail "branch $branch is the $(line_name compat), but composer.json requires php \`$php_constraint\`, the $MODERN_BRANCH constraint, so a 1.x release from this tree would not install on PHP 7.4, the one platform this line exists for. Most likely a merge or cherry-pick from $MODERN_BRANCH carried its composer.json here: restore \"php\": \"$COMPAT_PHP\"."
	fi
fi

version_line=""
if ! is_version "$version"; then
	fail "Client::VERSION is \`$version\`, which is not MAJOR.MINOR.PATCH. The client reports it in its User-Agent, and the release checks compare it with the release branch and the tag; set it to a version like 2.0.0."
else
	version_line=$(line_of_version "$version")
	if [ -z "$version_line" ]; then
		# 0.x is tolerated outside a release: nothing has been released, and Client::VERSION keeps the
		# 0.1.0 placeholder until the first release PR (docs/versioning.md). A release PR or a tag
		# compares it with its own version below, and refuses 0.x there.
		note "Client::VERSION is $version, a 0.x placeholder; no line publishes 0.x, and the release PR sets the line's version"
	elif [ -n "$tree_line" ] && [ "$version_line" != "$tree_line" ]; then
		if [ "$version_line" = "modern" ]; then
			fail "Client::VERSION is $version, a 2.x version, but composer.json requires php \`$php_constraint\`, the $COMPAT_BRANCH constraint. Tagged, this tree would publish 2.x code that installs on PHP 7.4 and wins as the highest version for every PHP 8.2+ consumer. The 1.x line publishes 1.x only: set Client::VERSION to the 1.x release."
		else
			fail "Client::VERSION is $version, a 1.x version, but composer.json requires php \`$php_constraint\`, the $MODERN_BRANCH constraint. 1.x versions belong to $COMPAT_BRANCH, and $MODERN_BRANCH publishes 2.x: set Client::VERSION to the 2.x release, or to the 0.1.0 placeholder outside one."
		fi
	fi
fi

if [ "$failed" = "0" ] && [ -n "$branch_line" ]; then
	note "branch $branch: composer.json requires php $php_constraint, as the $(line_name "$branch_line") must"
fi

# --- A release PR: the last point where a fix is still a commit ----------------------------------
#
# The tag checks below run after the tag is public. Here the same assertions run on the release PR,
# where the answer is "push a commit" rather than "publish a corrected version and explain the gap".

if [ "$is_release_pr" = "1" ]; then
	if ! is_version "$release_version"; then
		fail "release branch $head_branch does not name a version. A release PR comes from release/MAJOR.MINOR.PATCH (docs/release.md), and every release/* branch is read as one; name a branch that is not a release with another type."
	else
		release_line=$(line_of_version "$release_version")
		if [ -z "$release_line" ]; then
			fail "release PR for $release_version: no line publishes 0.x. The 1.x line releases from $COMPAT_BRANCH and the 2.x line from $MODERN_BRANCH (docs/versioning.md)."
		else
			if [ -n "$tree_line" ] && [ "$release_line" != "$tree_line" ]; then
				if [ "$release_line" = "modern" ]; then
					fail "release PR for $release_version, a 2.x version, but composer.json requires php \`$php_constraint\`, the $COMPAT_BRANCH constraint. Tagged, it would publish 2.x code that installs on PHP 7.4 and wins as the highest version for every PHP 8.2+ consumer. 2.x releases come from $MODERN_BRANCH."
				else
					fail "release PR for $release_version, a 1.x version, but composer.json requires php \`$php_constraint\`, the $MODERN_BRANCH constraint. 1.x releases come from $COMPAT_BRANCH, whose tree requires php $COMPAT_PHP."
				fi
			fi
			if [ -z "$branch" ]; then
				note "no base branch in context (a local run), so the target-branch check is skipped; CI runs it on the pull request"
			elif [ "$branch_line" != "$release_line" ]; then
				fail "release PR for $release_version targets \`$branch\`, but $(line_major "$release_line") releases are cut from \`$(line_branch "$release_line")\` (docs/release.md). Retarget the pull request: the tag follows the merge commit, so merging it here puts the release on the wrong line."
			fi
		fi
		if [ "$version" != "$release_version" ]; then
			fail "release PR for $release_version, but Client::VERSION is $version. Set it to $release_version in src/Client.php (docs/release.md): once the tag is pushed this is no longer fixable."
		fi
		# Client::VERSION == the CHANGELOG heading is also `make version-check`'s, which CI runs on
		# main. It is asserted here too because this script is what runs on both lines.
		if [ ! -f CHANGELOG.md ]; then
			fail "release PR for $release_version, but there is no CHANGELOG.md. The release notes are part of the release (docs/release.md)."
		else
			log_version=$(sed -n 's/^## \[\([0-9][^]]*\)\].*/\1/p' CHANGELOG.md | head -n 1)
			if [ -z "$log_version" ]; then
				fail "release PR for $release_version, but CHANGELOG.md has no released-version heading. Move the [Unreleased] items under ## [$release_version] - <date> (docs/release.md)."
			elif [ "$log_version" != "$release_version" ]; then
				fail "release PR for $release_version, but the latest CHANGELOG.md heading is [$log_version]. The release branch, Client::VERSION and the CHANGELOG must agree."
			fi
		fi
		if [ "$failed" = "0" ]; then
			note "release PR for $release_version: the base branch, composer.json, Client::VERSION and CHANGELOG.md all agree"
		fi
	fi
fi

# --- A tag: detection, and before the push, prevention -------------------------------------------
#
# On a tag push GitHub has already accepted the ref, and Packagist publishes on the push itself, so
# this catches a tag pushed without a release PR rather than preventing anything. Run with --tag
# before the push (docs/release.md), the same checks are the last prevention there is.

if [ -n "$tag" ]; then
	tag_version=""
	if ! may_be_version "$tag"; then
		note "tag $tag is not a version to Composer, so Packagist does not publish it; skipping the tag checks"
	else
		case "$tag" in
		v*) tag_version=${tag#v} ;;
		esac
		if [ -z "$tag_version" ] || ! is_version "$tag_version"; then
			# No further comparison: a malformed tag has no line and nothing to match Client::VERSION.
			fail "tag $tag starts with a number (after any v, V or release-), so Composer may read it as a version, and Packagist publishes every tag Composer reads as one. This repository tags vMAJOR.MINOR.PATCH only, with no prerelease or build metadata, and names any other tag so it does not start with a number (docs/release.md). Delete the ref before anything fetches it, then push it again under a name that fits."
		else
			tag_line=$(line_of_version "$tag_version")
			if [ -z "$tag_line" ]; then
				fail "tag $tag publishes a 0.x version, and no line does: 1.x comes from $COMPAT_BRANCH and 2.x from $MODERN_BRANCH (docs/versioning.md). Delete the ref before anything fetches it."
			elif [ -n "$tree_line" ] && [ "$tag_line" != "$tree_line" ]; then
				if [ "$tag_line" = "modern" ]; then
					fail "tag $tag is a 2.x version, but the tree it points at requires php \`$php_constraint\`: that is $COMPAT_BRANCH's composer.json. Packagist would publish $tag_version for PHP 7.4 and up, and it wins as the highest version for every PHP 8.2+ consumer, who would get the 7.4 line's mutable models. Delete the ref before anything fetches it; 2.x is tagged on $MODERN_BRANCH."
				else
					fail "tag $tag is a 1.x version, but the tree it points at requires php \`$php_constraint\`: that is $MODERN_BRANCH's composer.json. Packagist would publish $tag_version for PHP 8.2 and up, spending a 1.x number on 2.x code that the PHP 7.4 consumers this line exists for cannot install. Delete the ref before anything fetches it; 1.x is tagged on $COMPAT_BRANCH."
				fi
			fi
			if [ "$version" != "$tag_version" ]; then
				fail "tag $tag does not match Client::VERSION ($version). The published package would report a version it is not in its User-Agent. The release PR sets Client::VERSION (docs/release.md); tag the merge commit of that PR."
			fi
		fi
	fi
	# Before the push the tag does not exist yet, and `git tag` will point it at HEAD. So what was
	# read above must be what HEAD holds, or this run vouched for a tree the tag will not carry.
	if [ -n "$opt_tag" ]; then
		if ! git rev-parse --verify --quiet HEAD > /dev/null 2>&1; then
			fail "--tag checks the commit a tag will point at, and $(pwd) is not a git checkout with a commit."
		else
			for path in composer.json src/Client.php; do
				if ! git cat-file -e "HEAD:$path" 2> /dev/null || ! git diff --quiet HEAD -- "$path" 2> /dev/null; then
					fail "$path differs from HEAD, but \`git tag\` points the tag at HEAD, not at the working tree. Commit or stash the change, or check out the commit you mean to tag, and run the guard again."
				fi
			done
		fi
	fi
	if [ "$failed" = "0" ] && [ -n "$tag_version" ]; then
		note "tag $tag matches Client::VERSION ($version) and the $(line_name "$tree_line")"
	fi
fi

if [ "$failed" != "0" ]; then
	printf 'release-line-guard: FAILED\n'
	exit 1
fi
printf 'release-line-guard: OK\n'
