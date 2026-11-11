#!/bin/bash
# SPDX-License-Identifier: EUPL-1.2
#
# regen-sbom.sh — regenerate, or check, the committed root sbom.cdx.json of an app.
#
#   ./scripts/regen-sbom.sh [--check] [APP_DIR]
#
# Builds the SBOM with the same recipe as the `sbom` job of
# ConductionNL/.github quality.yml:
#
#   composer CycloneDX:make-sbom --output-format=JSON --spec-version=1.5 --omit=dev --omit=plugin
#   npx @cyclonedx/cyclonedx-npm --package-lock-only --ignore-npm-errors --spec-version 1.5 --omit dev
#   jq -s '.[0] * {components: ([.[].components[]?] | unique_by(.purl // .name))}'
#
# and the PHP document alone when the app has no package-lock.json. The npm
# document alone is used when the app has no composer.json.
#
# The output is deterministic: serialNumber and metadata.timestamp are removed,
# and composer's root version is pinned through COMPOSER_ROOT_VERSION (the
# package-lock.json version, else 0.0.0) so it does not record the current git
# branch and commit. An unchanged lockfile gives an unchanged file. The fingerprint that decides
# "unchanged" also ignores metadata.tools, because tool versions (composer
# itself, for one) depend on the machine and not on the lockfiles. When the
# fingerprint of the committed file matches and that file is already
# deterministic, the file is left untouched.
#
# Modes:
#   (default)  write APP_DIR/sbom.cdx.json
#   --check    write nothing; exit 1 when the committed file is missing or stale
#
# Tools:
#   composer   cyclonedx/cyclonedx-php-composer at the version pinned in the app's
#              composer.lock (else v6.2.0), installed once into an isolated
#              COMPOSER_HOME under $REGEN_SBOM_CACHE (default ~/.cache/regen-sbom).
#              The generators run on a copy of the manifests and lockfiles
#              only: with a vendor/ present the plugin reads the installed
#              packages instead of composer.lock, and vendor/ can lag the lock.
#   npm        @cyclonedx/cyclonedx-npm@$REGEN_SBOM_NPM_VERSION (default 6.0.1)
#   jq
#
# Exit codes: 0 = written, unchanged, or (--check) current
#             1 = (--check) missing or stale; the fix command is printed
#             3 = could not run (tool missing or failed): NOT a pass

set -uo pipefail

NPM_VERSION="${REGEN_SBOM_NPM_VERSION:-6.0.1}"
CACHE="${REGEN_SBOM_CACHE:-$HOME/.cache/regen-sbom}"
SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
CHECK=0
APP="."

while [ $# -gt 0 ]; do
    case "$1" in
        --check) CHECK=1 ;;
        -h|--help) sed -n '3,40p' "$SELF" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) echo "[regen-sbom] unknown option: $1" >&2; exit 3 ;;
        *) APP="$1" ;;
    esac
    shift
done

die() { echo "[regen-sbom] ERROR: $*" >&2; exit 3; }

APP="$(cd "$APP" 2>/dev/null && pwd)" || die "no such directory"
command -v jq >/dev/null || die "jq is not installed"
[ -f "$APP/composer.json" ] || [ -f "$APP/package-lock.json" ] || die "$APP has neither composer.json nor package-lock.json"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/regen-sbom.XXXXXX")" || die "mktemp failed"
trap 'rm -rf "$TMP"' EXIT

# Prints the COMPOSER_HOME that holds the pinned CycloneDX composer plugin.
composer_home() {
    local ver=""
    if [ -f "$APP/composer.lock" ]; then
        ver="$(jq -r '[.packages[]?, ."packages-dev"[]?] | map(select(.name == "cyclonedx/cyclonedx-php-composer")) | .[0].version // empty' "$APP/composer.lock")"
    fi
    ver="${ver:-v6.2.0}"
    local home="$CACHE/composer-${ver}"
    if [ ! -d "$home/vendor/cyclonedx/cyclonedx-php-composer" ]; then
        mkdir -p "$home" || return 1
        COMPOSER_HOME="$home" composer global config --no-interaction allow-plugins.cyclonedx/cyclonedx-php-composer true >/dev/null 2>&1 || return 1
        COMPOSER_HOME="$home" composer global require --quiet --no-interaction "cyclonedx/cyclonedx-php-composer:${ver#v}" >&2 \
            || COMPOSER_HOME="$home" composer global require --quiet --no-interaction --prefer-source "cyclonedx/cyclonedx-php-composer:${ver#v}" >&2 \
            || return 1
    fi
    echo "$home"
}

SRC="$TMP/src"
mkdir -p "$SRC"
for f in composer.json composer.lock package.json package-lock.json; do
    if [ -f "$APP/$f" ]; then cp "$APP/$f" "$SRC/$f"; fi
done

parts=()
if [ -f "$APP/composer.json" ]; then
    command -v composer >/dev/null || die "composer is not installed"
    chome="$(composer_home)" || die "could not install cyclonedx/cyclonedx-php-composer into $CACHE"
    root_version="0.0.0"
    if [ -f "$APP/package-lock.json" ]; then
        root_version="$(jq -r '.version // "0.0.0"' "$APP/package-lock.json")"
    fi
    if ! COMPOSER_HOME="$chome" COMPOSER_ROOT_VERSION="$root_version" composer --working-dir="$SRC" --no-interaction CycloneDX:make-sbom \
            --output-format=JSON --output-file="$TMP/bom-php.cdx.json" \
            --spec-version=1.5 --omit=dev --omit=plugin >"$TMP/php.log" 2>&1; then
        tail -20 "$TMP/php.log" >&2
        die "composer CycloneDX:make-sbom failed"
    fi
    parts+=("$TMP/bom-php.cdx.json")
fi
if [ -f "$APP/package-lock.json" ]; then
    command -v npx >/dev/null || die "npx is not installed"
    if ! (cd "$SRC" && npx --yes "@cyclonedx/cyclonedx-npm@${NPM_VERSION}" --package-lock-only --ignore-npm-errors \
            --output-file "$TMP/bom-npm.cdx.json" --spec-version 1.5 --omit dev) >"$TMP/npm.log" 2>&1; then
        tail -20 "$TMP/npm.log" >&2
        die "cyclonedx-npm failed"
    fi
    parts+=("$TMP/bom-npm.cdx.json")
fi

if [ -f "$APP/composer.json" ] && [ ! -f "$APP/package-lock.json" ]; then
    cp "$TMP/bom-php.cdx.json" "$TMP/merged.json"
else
    jq -s '.[0] * {components: ([.[].components[]?] | unique_by(.purl // .name))}' "${parts[@]}" >"$TMP/merged.json" \
        || die "jq merge failed"
fi
jq 'del(.serialNumber) | del(.metadata.timestamp)' "$TMP/merged.json" >"$TMP/sbom.cdx.json" || die "jq normalise failed"

fingerprint() {
    jq -S 'del(.serialNumber) | del(.metadata.timestamp) | del(.metadata.tools)' "$1" 2>/dev/null | sha256sum | cut -d' ' -f1
}

TARGET="$APP/sbom.cdx.json"
same=0
if [ -f "$TARGET" ] && jq -e . "$TARGET" >/dev/null 2>&1 \
        && [ "$(fingerprint "$TARGET")" = "$(fingerprint "$TMP/sbom.cdx.json")" ]; then
    same=1
fi
count="$(jq '.components | length' "$TMP/sbom.cdx.json")"

if [ "$CHECK" = 1 ]; then
    if [ "$same" = 1 ]; then
        echo "[regen-sbom] sbom.cdx.json is current ($count components)"
        exit 0
    fi
    if [ -f "$TARGET" ]; then
        echo "[regen-sbom] STALE: sbom.cdx.json does not match composer.lock / package-lock.json"
        if [ -f "$TARGET" ] && jq -e . "$TARGET" >/dev/null 2>&1; then
            diff <(jq -r '.components[]? | .purl // "\(.group // "")/\(.name)@\(.version // "")"' "$TARGET" | sort) \
                 <(jq -r '.components[]? | .purl // "\(.group // "")/\(.name)@\(.version // "")"' "$TMP/sbom.cdx.json" | sort) \
                | grep '^[<>]' | head -20 | sed 's/^</  removed:/; s/^>/  added:  /'
        fi
    else
        echo "[regen-sbom] MISSING: no sbom.cdx.json at the repository root"
    fi
    echo "[regen-sbom] fix: $SELF $APP && git -C $APP add sbom.cdx.json"
    exit 1
fi

if [ "$same" = 1 ] && ! jq -e 'has("serialNumber") or (.metadata.timestamp != null)' "$TARGET" >/dev/null; then
    echo "[regen-sbom] sbom.cdx.json unchanged ($count components)"
    exit 0
fi
cp "$TMP/sbom.cdx.json" "$TARGET" || die "could not write $TARGET"
echo "[regen-sbom] wrote sbom.cdx.json ($count components)"
