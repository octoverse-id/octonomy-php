#!/bin/sh
# Generates the PHP 7.4 tree of octonomy-php into a directory of your choosing.
#
# Copies src/, tests/, examples/, the harness and the vendored OpenAPI specs there,
# downgrades the PHP with Rector (tools/rector), lays the 7.4 line's config over
# the top (compat/), and formats the result at @PSR12. The working tree is never
# touched, and nothing is committed: CI runs this into a throwaway directory on
# every push and proves the output on a real PHP 7.4 (scripts/php74-check.sh).
# support/php7.4 does not exist until P5 of epic #4, which generates it once with
# this same script.
#
# Usage:
#   scripts/php74-downgrade.sh <dir>    # <dir> must be missing or empty
#
# Runs on the PHP that main's own tools run on (8.2+), and needs both
#   composer install                              # vendor/bin/php-cs-fixer
#   composer install --working-dir=tools/rector   # tools/rector/vendor/bin/rector

set -eu

die() {
    echo "php74-downgrade: $*" >&2
    exit 1
}

[ $# -eq 1 ] || die "usage: scripts/php74-downgrade.sh <dir>"
out=$1

root=$(cd "$(dirname "$0")/.." && pwd)
rector="$root/tools/rector/vendor/bin/rector"
fixer="$root/vendor/bin/php-cs-fixer"
[ -x "$rector" ] || die "no $rector: run composer install --working-dir=tools/rector"
[ -x "$fixer" ] || die "no $fixer: run composer install"

# An existing tree would be downgraded a second time or mixed with stale files.
if [ -e "$out" ] && [ -n "$(ls -A "$out")" ]; then
    die "$out is not empty"
fi
mkdir -p "$out"
out=$(cd "$out" && pwd)
case "$out" in
    "$root" | "$root"/*) die "$out is inside the repository; generate outside it" ;;
esac

cp -R "$root/src" "$root/tests" "$root/examples" "$out/"
# The harness is POSIX sh and serves both lines; CapturedCorpusTest reads its pin.
mkdir "$out/scripts"
cp "$root/scripts/octonomy-harness.sh" "$out/scripts/"
# So are the vendored specs; VendoredSpecTest reads them and the tag versioning.md names.
mkdir "$out/docs"
cp "$root/docs/openapi.yaml" "$root/docs/openapi-v2.yaml" "$root/docs/versioning.md" "$out/docs/"
cp "$root/compat/composer.json" "$root/compat/phpunit.xml.dist" "$root/compat/phpstan.neon.dist" \
    "$root/compat/.php-cs-fixer.dist.php" "$out/"

# set -e is what fails the job on a Rector crash. Rector exits non-zero on any
# error: the issue #5 spike saw it crash (ShouldNotHappenException) and exit 1
# with the file still in 8.2 syntax. Never pipe this command: a pipeline's status
# is its last command's.
"$rector" process "$out/src" "$out/tests" "$out/examples" \
    --config "$root/tools/rector/rector.php" --clear-cache --no-progress-bar --no-diffs

# From inside the tree, so php-cs-fixer reads the 7.4 line's composer.json for the
# PHP version it targets rather than main's.
(cd "$out" && "$fixer" fix --using-cache=no --show-progress=none)

echo "php74-downgrade: wrote $out"
