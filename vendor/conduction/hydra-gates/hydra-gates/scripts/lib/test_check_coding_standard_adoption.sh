#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# Self-test for check_coding_standard_adoption.py (gate-65).
#
# Discovered and run by tests/run-helper-suites.sh — no workflow edit needed.
#
# Every assertion is paired: a NEGATIVE control (a compliant fixture must produce
# zero findings) and a POSITIVE control (a fixture carrying exactly one defect
# must produce exactly that finding). A checker that reports nothing on a broken
# repo and a checker that reports nothing on a clean one are the same program
# from the outside, which is the failure this suite exists to make impossible.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CHECKER="${HERE}/check_coding_standard_adoption.py"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

pass=0
fail=0

ok() { echo "  PASS  $1"; pass=$((pass + 1)); }
no() { echo "  FAIL  $1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; fail=$((fail + 1)); }

# Build a fully compliant app fixture in $1.
scaffold() {
    local d="$1"
    mkdir -p "$d/appinfo" "$d/lib"

    cat > "$d/.php-cs-fixer.dist.php" <<'PHP'
<?php
require_once __DIR__ . '/vendor/autoload.php';
$config = new Conduction\CodingStandard\Config();
$config->getFinder()->in(__DIR__ . '/lib');
return $config;
PHP

    cat > "$d/composer.json" <<'JSON'
{
    "name": "conductionnl/fixture",
    "require-dev": {
        "conduction/coding-standard": "^1.0",
        "nextcloud/ocp": "^34.0"
    },
    "scripts": {
        "cs:check": "php-cs-fixer fix --dry-run --diff",
        "cs:fix": "php-cs-fixer fix"
    }
}
JSON

    cat > "$d/phpcs.xml" <<'XML'
<?xml version="1.0"?>
<ruleset name="fixture">
    <file>lib</file>
    <rule ref="vendor/conduction/hydra-gates/quality-config/phpcs.xml"/>
</ruleset>
XML

    cat > "$d/.editorconfig" <<'EC'
root = true

[*]
charset = utf-8
indent_size = 4
indent_style = tab

[*.yml]
indent_size = 2
indent_style = space
EC

    cat > "$d/package.json" <<'JSON'
{
    "name": "fixture",
    "scripts": {
        "stylelint": "stylelint \"src/**/*.{vue,scss,css}\""
    }
}
JSON

    mkdir -p "$d/.github/workflows"
    cat > "$d/.github/workflows/code-quality.yml" <<'YML'
name: Code Quality
on: [push]
jobs:
  quality:
    uses: ConductionNL/.github/.github/workflows/quality.yml@main
    with:
      app-name: fixture
      enable-hydra-gates: true
YML

    cat > "$d/appinfo/info.xml" <<'XML'
<?xml version="1.0"?>
<info>
    <id>fixture</id>
    <version>1.0.0</version>
    <dependencies>
        <nextcloud min-version="32" max-version="34"/>
    </dependencies>
</info>
XML
}

run() { python3 "$CHECKER" "$1" 2>&1; }

echo "check_coding_standard_adoption — self-test"
echo

# ── NEGATIVE CONTROL ──────────────────────────────────────────────────────
CLEAN="$WORK/clean"
scaffold "$CLEAN"
out="$(run "$CLEAN")"; rc=$?
if [ "$rc" -eq 0 ]; then
    ok "compliant fixture reports nothing (exit 0)"
else
    no "compliant fixture should be clean" "$out"
fi

if printf '%s' "$out" | grep -q 'checked [0-9]* rule'; then
    ok "prints its terminal summary line"
else
    no "no 'checked N rule(s)' summary — the runner reads its absence as a wiring failure" "$out"
fi

# ── POSITIVE CONTROLS: one defect at a time ───────────────────────────────
# Each names the defect it plants, so a broadened rule that starts matching
# everything is caught by the negative control above rather than hidden here.
probe() {
    local name="$1" expect="$2"; shift 2
    local d="$WORK/$name"
    rm -rf "$d"; scaffold "$d"
    ( cd "$d" && eval "$@" )
    local o; o="$(run "$d")"
    if printf '%s' "$o" | grep -q "FAIL ${expect}:"; then
        ok "detects ${name} (${expect})"
    else
        no "did not detect ${name}; expected 'FAIL ${expect}:'" "$o"
    fi
}

probe no-fixer-config          no-php-cs-fixer-config              'rm .php-cs-fixer.dist.php'
probe missing-autoloader       fixer-config-missing-autoloader     "sed -i '/autoload.php/d' .php-cs-fixer.dist.php"
probe cs-wired-to-phpcs        cs-script-wired-to-phpcs            "sed -i 's|php-cs-fixer fix --dry-run --diff|./vendor/bin/phpcs --standard=phpcs.xml|' composer.json"
probe nc-standard-direct       nextcloud-coding-standard-declared-directly \
                               "sed -i 's|\"conduction/coding-standard\": \"^1.0\",|\"conduction/coding-standard\": \"^1.0\", \"nextcloud/coding-standard\": \"^1.4\",|' composer.json"
probe phpcs-not-central        phpcs-not-centralised               "sed -i 's|vendor/conduction/hydra-gates/quality-config/phpcs.xml|PEAR|' phpcs.xml"
probe local-formatting-sniff   phpcs-declares-formatting-sniffs \
                               "sed -i 's|</ruleset>|<rule ref=\"Generic.WhiteSpace.ScopeIndent\"/></ruleset>|' phpcs.xml"
probe local-sniff-dir          local-custom-sniffs                 'mkdir -p phpcs-custom-sniffs/CustomSniffs'
probe no-editorconfig          no-editorconfig                     'rm .editorconfig'
probe editorconfig-spaces      editorconfig-not-tab                "sed -i 's|indent_style = tab|indent_style = space|' .editorconfig"
probe unquoted-glob            stylelint-glob-unquoted             "sed -i 's|stylelint \\\\\"src/\\*\\*/\\*.{vue,scss,css}\\\\\"|stylelint src/**/*.vue src/**/*.css|' package.json"
probe ocp-below-min            ocp-below-declared-minimum          "sed -i 's|\"nextcloud/ocp\": \"^34.0\"|\"nextcloud/ocp\": \"^31.0\"|' composer.json"

probe gates-ref-pinned         gates-ref-pinned                    "printf '      hydra-gates-ref: v1.3.0\\n' >> .github/workflows/code-quality.yml"
probe workflow-pinned          shared-workflow-pinned              "sed -i 's|quality.yml@main|quality.yml@v2.0.0|' .github/workflows/code-quality.yml"
probe package-pinned-exact     shared-package-pinned               "sed -i 's|\"conduction/coding-standard\": \"^1.0\"|\"conduction/coding-standard\": \"1.0.0\"|' composer.json"
probe package-pinned-branch    shared-package-pinned               "sed -i 's|\"conduction/coding-standard\": \"^1.0\"|\"conduction/coding-standard\": \"dev-some-branch\"|' composer.json"

# ── ^1.0 IS NOT A PIN ─────────────────────────────────────────────────────
# The rule must distinguish "floats within a major" from "frozen". If it cannot,
# it fires on every compliant app and gets switched off.
D="$WORK/caret"
rm -rf "$D"; scaffold "$D"
if run "$D" | grep -q 'FAIL shared-package-pinned:'; then
    no "^1.0 was reported as a pin — the rule cannot tell floating from frozen"
else
    ok "treats ^1.0 as floating, not pinned"
fi

# ── hydra-gates-ref: main is the correct value, not a pin ─────────────────
D="$WORK/refmain"
rm -rf "$D"; scaffold "$D"
printf '      hydra-gates-ref: main\n' >> "$D/.github/workflows/code-quality.yml"
if run "$D" | grep -q 'FAIL gates-ref-pinned:'; then
    no "hydra-gates-ref: main was reported as a pin"
else
    ok "accepts an explicit hydra-gates-ref: main"
fi

# ── a COMMENTED-OUT pin is not a pin ──────────────────────────────────────
D="$WORK/commentedpin"
rm -rf "$D"; scaffold "$D"
printf '      # hydra-gates-ref: v1.3.0\n' >> "$D/.github/workflows/code-quality.yml"
if run "$D" | grep -q 'FAIL gates-ref-pinned:'; then
    no "a commented-out hydra-gates-ref was reported as a live pin"
else
    ok "ignores a commented-out hydra-gates-ref"
fi

# ── A COMMENTED-OUT RULE IS NOT A RULE ────────────────────────────────────
# gate-64's defect: grepping for a string matches it inside every comment.
D="$WORK/commented"
rm -rf "$D"; scaffold "$D"
sed -i 's|</ruleset>|<!-- <rule ref="Generic.WhiteSpace.ScopeIndent"/> --></ruleset>|' "$D/phpcs.xml"
if run "$D" | grep -q 'FAIL phpcs-declares-formatting-sniffs:'; then
    no "a COMMENTED-OUT formatting sniff was reported as live"
else
    ok "ignores a commented-out formatting sniff"
fi

# ── dev-master ocp is not below anything ──────────────────────────────────
D="$WORK/devmaster"
rm -rf "$D"; scaffold "$D"
sed -i 's|"nextcloud/ocp": "\^34.0"|"nextcloud/ocp": "dev-master"|' "$D/composer.json"
if run "$D" | grep -q 'FAIL ocp-below-declared-minimum:'; then
    no "nextcloud/ocp:dev-master reported as below the declared minimum"
else
    ok "treats nextcloud/ocp:dev-master as tracking the tip, not as stale"
fi

# ── a crash must not read as clean ────────────────────────────────────────
if python3 "$CHECKER" "$WORK/does-not-exist" >/dev/null 2>&1; then
    no "a missing app root exited 0 — an unrunnable checker read as a clean repo"
else
    ok "a missing app root exits non-zero rather than reporting clean"
fi

echo
echo "----------------------------------------------------------------"
printf '%d passed, %d failed\n' "$pass" "$fail"
echo "----------------------------------------------------------------"
[ "$fail" -eq 0 ]
