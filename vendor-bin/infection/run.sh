#!/bin/bash
#
# Mutation testing for openregister (composer test:mutation).
#
#   composer test:mutation -- lib/Service/Audit/PurposeGuard.php [more files]
#
# With files: runs Infection on those files against only the unit tests that
# name the class (a narrowed copy of phpunit.xml; loading all of tests/Unit
# costs ~40 s per mutant). Without files: the whole source of infection.json5
# against the full Unit suite (slow).
#
# Infection runs from this directory on purpose. Its bin also loads
# ./vendor/autoload.php of the cwd, and the project's autoloader carries
# nikic/php-parser v4 (Psalm), which shadows Infection's v5.
# OPENREGISTER_TEST_SKIP_NC=1 keeps tests/bootstrap.php silent on STDERR;
# Infection aborts the initial run on the first STDERR byte.
#
# Reports land in vendor-bin/infection/vendor/run/<tag>/ (git-ignored):
# infection-log.txt, infection-summary.txt, infection-log.json, per-mutator.md.
#
# @category Test
# @package  OCA\OpenRegister
# @author   Conduction Development Team <dev@conduction.nl>
# @copyright 2026 Conduction B.V.
# @license  EUPL-1.2
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
BIN="$HERE/vendor/infection/infection/bin/infection"
[ -x "$BIN" ] || { echo "Infection missing: run 'composer bin infection install'" >&2; exit 1; }

TAG=${INFECTION_TAG:-default}
OUT="$HERE/vendor/run/$TAG"
rm -rf "$OUT"; mkdir -p "$OUT"

ARGS=(--threads=max --only-covering-test-cases --no-progress)
CFG="$ROOT/infection.json5"

if [ "$#" -gt 0 ]; then
	: > "$OUT/tests.txt"
	abs=()
	for f in "$@"; do
		abs+=("$ROOT/$f")
		grep -rlw --include='*Test.php' "$(basename "$f" .php)" "$ROOT/tests/Unit" >> "$OUT/tests.txt" || true
	done
	sort -u "$OUT/tests.txt" -o "$OUT/tests.txt"
	echo "covering test files: $(wc -l < "$OUT/tests.txt")"
	python3 - "$ROOT" "$OUT" <<'PY'
import re, sys
root, out = sys.argv[1:]
x = open(f"{root}/phpunit.xml").read()
files = "\n".join(f"            <file>{l.strip()}</file>" for l in open(f"{out}/tests.txt") if l.strip())
x = x.replace("<directory>tests/Unit</directory>", files, 1)
x = x.replace('bootstrap="tests/bootstrap.php"', f'bootstrap="{root}/tests/bootstrap.php"')
x = x.replace('cacheDirectory=".phpunit.cache"', f'cacheDirectory="{out}/.phpunit.cache"')
x = re.sub(r"<(directory|file)( suffix=\"\.php\")?>lib/", lambda m: f"<{m.group(1)}{m.group(2) or ''}>{root}/lib/", x)
x = re.sub(r"<coverage>.*?</coverage>", "", x, flags=re.S)
open(f"{out}/phpunit.xml", "w").write(x)
c = open(f"{root}/infection.json5").read()
c = c.replace("vendor-bin/infection/vendor/run/", f"{out}/")
c = c.replace('"configDir": "."', f'"configDir": "{out}"')
c = c.replace('"customPath": "vendor/bin/phpunit"', f'"customPath": "{root}/vendor/bin/phpunit"')
c = c.replace('            "lib"\n', f'            "{root}/lib"\n')
c = c.replace('"$schema": "vendor-bin', f'"$schema": "{root}/vendor-bin')
open(f"{out}/infection.json5", "w").write(c)
PY
	CFG="$OUT/infection.json5"
	ARGS+=("${abs[@]}")
fi

cd "$HERE"
OPENREGISTER_TEST_SKIP_NC=1 php -d memory_limit=4G "$BIN" -c "$CFG" "${ARGS[@]}" \
	--logger-text="$OUT/infection-log.txt" --initial-tests-php-options="-d memory_limit=4G" \
	2>&1 | tee "$OUT/console.txt" | tail -20
