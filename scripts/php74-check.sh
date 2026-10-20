#!/bin/sh
# Proves a tree from scripts/php74-downgrade.sh on a real PHP 7.4.
#
# Runs every check and reports all failures, not just the first:
#   * php -l on every file, so a file no test loads (examples/) is parsed by 7.4
#   * the tree's php-cs-fixer config (@PSR12) in dry-run mode
#   * PHPStan at the tree's config (level 8, phpVersion 70400)
#   * the full suite on PHPUnit 9.6
#   * that the suite ran exactly the tests main's suite did, data sets included.
#     A test that loses its data provider in the downgrade, or a test method that
#     stops being discovered, changes that list even when everything left passes.
#
# `php -l` alone is never the pass signal. The issue #5 spike saw it pass output
# that corrupted PATCH bodies or fataled at load.
#
# Usage:
#   scripts/php74-check.sh <tree> <main-junit.xml>
#
# <main-junit.xml> is `vendor/bin/phpunit --log-junit` from main's tree. Run this
# with PHP 7.4 as `php` and after `composer install` in <tree>; `make php74` does
# all of it in Docker. POSIX sh, so it runs in the stock php:7.4-cli image.

set -eu

die() {
    echo "php74-check: $*" >&2
    exit 1
}

[ $# -eq 2 ] || die "usage: scripts/php74-check.sh <tree> <main-junit.xml>"
tree=$1
main_junit=$2
[ -f "$main_junit" ] || die "no $main_junit: run vendor/bin/phpunit --log-junit on main first"
[ -x "$tree/vendor/bin/phpunit" ] || die "no $tree/vendor/bin/phpunit: run composer install in $tree"

php -r 'exit(PHP_MAJOR_VERSION === 7 && PHP_MINOR_VERSION === 4 ? 0 : 1);' ||
    die "needs PHP 7.4 as php, found $(php -r 'echo PHP_VERSION;')"

main_junit=$(cd "$(dirname "$main_junit")" && pwd)/$(basename "$main_junit")
cd "$tree"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

lint() {
    find src tests examples -name '*.php' | sort > "$work/files"
    while IFS= read -r file; do
        out=$(php -l "$file" 2>&1) || { echo "$out"; return 1; }
    done < "$work/files"
    echo "$(wc -l < "$work/files" | tr -d ' ') files parse"
}

# One `Class::method with data set "name"` per test. JUnit is the one format
# PHPUnit 9 and 11 write alike, though not with their attributes in the same
# order, so each one is matched by name. The entities are decoded only so a diff
# reads as the data set names do in the source.
test_ids() {
    grep -o '<testcase [^>]*>' "$1" | awk '{
        match($0, / class="[^"]*"/); class = substr($0, RSTART + 8, RLENGTH - 9)
        match($0, / name="[^"]*"/); name = substr($0, RSTART + 7, RLENGTH - 8)
        print class "::" name
    }' | sed -e 's/&quot;/"/g' -e "s/&apos;/'/g" -e 's/&lt;/</g' -e 's/&gt;/>/g' -e 's/&amp;/\&/g' |
        LC_ALL=C sort
}

same_tests() {
    test_ids "$main_junit" > "$work/main.ids"
    test_ids "$work/junit.xml" > "$work/php74.ids"
    [ -s "$work/main.ids" ] || { echo "$main_junit lists no tests"; return 1; }
    if ! diff -u "$work/main.ids" "$work/php74.ids"; then
        echo "the downgraded suite ran different tests from main's (- main, + PHP 7.4)"
        return 1
    fi
    echo "$(wc -l < "$work/main.ids" | tr -d ' ') tests, the same as main"
}

failed=''
step() {
    label=$1
    shift
    echo
    echo "php74-check: $label"
    if ! "$@"; then
        failed="$failed $label;"
    fi
}

step 'lint' lint
step '@PSR12' vendor/bin/php-cs-fixer fix --dry-run --diff --show-progress=none
step 'phpstan' vendor/bin/phpstan analyse --memory-limit=512M --no-progress
step 'phpunit' vendor/bin/phpunit --log-junit "$work/junit.xml"
if [ -f "$work/junit.xml" ]; then
    step 'same tests as main' same_tests
else
    failed="$failed same tests as main (PHPUnit wrote no JUnit log);"
fi

echo
[ -z "$failed" ] || die "FAILED:$failed"
echo "php74-check: PHP $(php -r 'echo PHP_VERSION;') passed every check"
