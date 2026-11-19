#!/bin/sh
# Proves the opt-in Rector rule (rector/src, #33) the way a project meets it: the SDK is installed
# from a tree into a copy of rector/tests/project beside Rector 2, and the project's rector-octonomy.php,
# the configuration docs/upgrading-1-to-2.md tells a project to write, runs over its code.
#
#   install <tree> <dir>   copies <tree>/rector/tests/project into <dir> (missing or empty), then
#                          installs the SDK from <tree> and rector/rector ^2.0 there with Composer.
#                          PLATFORM_PHP, when set, resolves the install for that PHP instead of the
#                          one running Composer, so make php74 can install on the host for a 7.4
#                          container.
#   check <dir>            on the PHP running it, and once per install, since it applies the rule to
#                          <dir>/src, asserts that
#                            * a dry run reports the rule's changes and exits non-zero, which is
#                              what lets a project's CI fail on a write it finds;
#                            * applying it turns src/ into expected/ exactly: every write in
#                              Writes::run() flagged, and nothing else touched;
#                            * a second dry run finds nothing, so a flagged statement is never
#                              flagged twice;
#                            * the rule as installed is PHPStan-clean at level 8, against the
#                              Rector and PHPStan the project installed.
#
# The tree is main's checkout for the 2.x line, where the models are natively readonly, or
# the PHP 7.4 tree from scripts/php74-downgrade.sh for the 1.x line, where the same writes are found
# through the @readonly docblocks the downgrade leaves. Either way the project and its expected
# output are the tree's own copies. Needs Composer and network access for install; POSIX sh, so check
# runs in the stock php:7.4-cli image.

set -eu

die() {
    echo "rector-rule-check: $*" >&2
    exit 1
}

usage() {
    die "usage: scripts/rector-rule-check.sh install <tree> <dir> | check <dir>"
}

install() {
    tree=$(cd "$1" && pwd)
    dir=$2
    [ -f "$tree/rector/tests/project/rector-octonomy.php" ] || die "no $tree/rector/tests/project: is $tree an SDK tree?"
    if [ -e "$dir" ] && [ -n "$(ls -A "$dir")" ]; then
        die "$dir is not empty"
    fi
    mkdir -p "$dir"
    cp -R "$tree/rector/tests/project/." "$dir/"
    cd "$dir"

    # A path repository symlinks the tree, so the rule and the models under test are the tree's
    # own files. `versions` pins the package to dev-main whatever ref the tree is on, as CI's
    # dev-server job does.
    platform=
    if [ -n "${PLATFORM_PHP:-}" ]; then
        platform=$(printf ', "config": {"platform": {"php": "%s"}}' "$PLATFORM_PHP")
    fi
    printf '{"repositories": [{"type": "path", "url": "%s", "options": {"versions": {"octoverse-id/octonomy-php": "dev-main"}}}]%s}\n' \
        "$tree" "$platform" > composer.json
    composer require --no-interaction --no-progress --quiet \
        octoverse-id/octonomy-php:dev-main rector/rector:^2.0
    echo "rector-rule-check: installed the SDK from $tree and $(rector_version) into $dir"
}

rector_version() {
    php vendor/bin/rector --version 2>/dev/null | head -n 1
}

check() {
    cd "$1"
    [ -x vendor/bin/rector ] || die "no $1/vendor/bin/rector: run install first"
    rector="php vendor/bin/rector process --config rector-octonomy.php --clear-cache --no-progress-bar"
    out=$(mktemp)
    trap 'rm -f "$out"' EXIT

    # Rector exits non-zero on a crash too, so the rule's name in the report is what says the
    # status came from a change it would make.
    if $rector --dry-run > "$out" 2>&1; then
        cat "$out"
        die "the dry run found no write to flag in src/, so it exits 0"
    fi
    grep -q 'FlagModelPropertyWriteRector' "$out" || { cat "$out"; die "the dry run failed without applying the rule"; }

    $rector --no-diffs > "$out" 2>&1 || { cat "$out"; die "applying the rule failed"; }
    diff -ru expected src || die "src/ after the rule differs from expected/ (the diff above runs expected -> actual)"

    $rector --dry-run > "$out" 2>&1 || { cat "$out"; die "a second dry run would change src/ again"; }

    php vendor/bin/phpstan analyse --no-progress --level 8 vendor/octoverse-id/octonomy-php/rector/src > "$out" 2>&1 ||
        { cat "$out"; die "PHPStan level 8 reports the installed rule"; }

    echo "rector-rule-check: $(rector_version) on PHP $(php -r 'echo PHP_VERSION;') flags every write in expected/, and only those"
}

[ $# -ge 1 ] || usage
case $1 in
    install) [ $# -eq 3 ] || usage; install "$2" "$3" ;;
    check) [ $# -eq 2 ] || usage; check "$2" ;;
    *) usage ;;
esac
