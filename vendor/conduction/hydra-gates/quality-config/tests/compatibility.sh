#!/usr/bin/env bash
#
# Does the Conduction PHPCS ruleset argue with the Conduction php-cs-fixer
# standard?
#
# The two tools have overlapping jurisdiction by default, and when they disagree
# the app is unfixable: `composer cs:fix` and `composer phpcs` demand opposite
# things and neither can be satisfied. Before the 2026-08 split that was the
# fleet's actual state — php-cs-fixer's output produced 111,932 PHPCS findings,
# 111,747 of them auto-fixable formatting.
#
# So: format a fixture with php-cs-fixer, then run PHPCS over the result. Any
# finding from a whitespace, brace, indentation or alignment sniff is a
# regression — this ruleset has taken back an opinion that belongs to the fixer.
#
# Exit code is the number of formatting findings. Semantic findings (@spec,
# line length, SPDX end-char) are expected and are NOT failures.
#
# POSITIVE CONTROL: the run also asserts that php-cs-fixer CHANGED the fixture.
# tests/fixtures/Messy.php is deliberately written in the old PEAR dialect —
# four spaces, next-line braces, `(int) $a`, `'a'.'b'`, `else if`. If the fixer
# reports nothing to fix, it did not run (a missing `require vendor/autoload.php`
# in .php-cs-fixer.dist.php fatals and reports ZERO FILES in --format=json), and
# a clean PHPCS pass afterwards would prove nothing at all.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/lib"
cp "$HERE"/fixtures/*.php "$WORK/lib/"

cat > "$WORK/.php-cs-fixer.dist.php" <<'PHP'
<?php
require_once __DIR__ . '/vendor/autoload.php';
$config = new Conduction\CodingStandard\Config();
$config->getFinder()->in(__DIR__ . '/lib');
return $config;
PHP

cat > "$WORK/phpcs.xml" <<PHP
<?xml version="1.0"?>
<ruleset name="compat"><file>lib</file><rule ref="$ROOT/phpcs.xml"/></ruleset>
PHP

cd "$WORK" || exit 2

# `composer require` has no --repository flag; the source has to be registered
# in composer.json first, and `composer config` needs that file to already
# exist. Drop this block once the package is on Packagist.
echo '{"name":"conduction/compat-fixture","description":"throwaway","license":"EUPL-1.2"}' > composer.json

composer config --no-interaction repositories.conduction-coding-standard \
  vcs https://github.com/ConductionNL/coding-standard.git \
  || { echo "FAIL: could not register the VCS repository"; exit 2; }

composer require --quiet --no-interaction --dev conduction/coding-standard:^1.0 \
  || { echo "FAIL: could not install conduction/coding-standard"; exit 2; }

# ── positive control ──────────────────────────────────────────────────────
BEFORE=$(vendor/bin/php-cs-fixer fix --dry-run --format=json 2>/dev/null \
         | php -r 'echo count((json_decode(stream_get_contents(STDIN),true)["files"]??[]));')
if [ "${BEFORE:-0}" -eq 0 ]; then
  echo "FAIL (positive control): php-cs-fixer found nothing to fix in a fixture"
  echo "  written specifically to violate it. The fixer did not run — most often"
  echo "  a missing 'require vendor/autoload.php' in .php-cs-fixer.dist.php, which"
  echo "  fatals and is reported as zero files."
  exit 2
fi
echo "positive control: php-cs-fixer reformats $BEFORE fixture file(s)  OK"

vendor/bin/php-cs-fixer fix -q 2>/dev/null

# ── the assertion ─────────────────────────────────────────────────────────
SRC=$(phpcs --standard=phpcs.xml --report=source -q --no-colors 2>/dev/null || true)
echo "$SRC"

FORMATTING=$(printf '%s\n' "$SRC" | grep -ciE \
  'white ?space|indent|brace|alignment|spacing|blank line|else if declaration' || true)

if [ "$FORMATTING" -gt 0 ]; then
  echo
  echo "FAIL: $FORMATTING formatting sniff(s) fired on php-cs-fixer-formatted code."
  echo "  PHPCS has taken back an opinion that belongs to the fixer. Remove the"
  echo "  sniff from quality-config/phpcs.xml — formatting is php-cs-fixer's job."
  exit "$FORMATTING"
fi

echo
echo "PASS: no formatting sniff fired on php-cs-fixer-formatted code."
exit 0
