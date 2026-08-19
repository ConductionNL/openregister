#!/usr/bin/env bash
#
# ShellCheck: 7 x SC2001 (`sed` where `${var//a/b}` would do), all inside the
# pattern-matching itself. Scoped to this file rather than a repo-level
# .shellcheckrc, for the reason spelled out at the top of run-hydra-gates.sh.
# shellcheck disable=SC2001
#
# lint-or-abstraction-anti-patterns.sh — single grep gate backing the seven
# "consume-or-*-fleet-wide" umbrella specs.
#
# Mode: WARN-only for the first 90 days post-acceptance (configured below).
# Switches to BLOCK after BLOCK_AFTER_EPOCH. Returns exit 0 always in WARN
# mode; returns exit 1 when any pattern matches in BLOCK mode.
#
# Patterns covered:
#   - shared-pdok-via-openconnector  → direct api.pdok.nl fetches outside openconnector
#   - consume-or-audit-trail-fleet-wide → app-local *Audit*Listener / *Audit*Validator / *audit*schema
#   - consume-or-approval-workflow-fleet-wide → app-local *ApprovalChain* / *Parafeer* / *SignRequest* schemas
#   - consume-or-tenant-fleet-wide   → app-local Tenant* schemas/services/middleware
#   - consume-or-workflow-engine-fleet-wide → app-local *StatusTransition*Service / *WorkflowEngine*
#   - consume-or-rbac-fleet-wide     → app-local *Permission*Service / *Authorization*Service for OR objects
#   - optional-integration-pattern   → manifest entries without an optionalIntegrations clause where applicable
#
# Plus (ADR-051 §4, exclusivity strengthening of ADR-022): a DATA-DRIVEN
# capability rule table (OR_CAPABILITY_RULES below) — one row per ADR-022
# abstraction-table capability. Detects app-local stacks duplicating an
# OR-owned capability (e.g. lib/Service/Avg/*, *SyncQueue*, Archival*Service,
# Tenant*Middleware, Postgres search_path tenancy). New OR capabilities
# extend the gate by adding a row, not code. Capability rules have their own
# bake-in epoch (HYDRA_OR_CAPABILITY_GATE_BLOCK_AFTER_EPOCH) and honour the
# ADR-022 exception clause: an app-local ADR under openspec/architecture/
# that references ADR-022 and names the affected path suppresses the finding
# for exactly that path.
#
# Run from a Conduction app repo root:
#   bash hydra/scripts/lint-or-abstraction-anti-patterns.sh
#
# License: EUPL-1.2.
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>

set -uo pipefail

# Mode: 0 = WARN, 1 = BLOCK. Switches automatically once BLOCK_AFTER_EPOCH is reached.
#
# THIS CONSTANT NEVER MATCHED ITS OWN COMMENT. It was introduced as "90 days
# after the umbrella's acceptance date (2026-05-11 + 90d)", which is
# 2026-08-09 00:00 UTC = 1786233600. The value actually committed, 1786636800,
# is 2026-08-13 16:00 UTC — four days and sixteen hours later, and not even a
# midnight boundary, which is the tell that it was arrived at by hand rather
# than computed. So the switch-over date could be read off neither the code
# nor the comment, and the two answers differed by four days.
#
# The INTENT was the comment's: acceptance + 90 days = 2026-08-09. Taking the
# comment literally would have flipped this gate to BLOCK on the morning the
# discrepancy was found. That was measured before it was decided, and the
# measurement said not to (see below).
#
# RECONCILED 2026-08-09 in favour of neither, DELIBERATELY, for three reasons:
#
#   1. The debt is real and is not four days of work. Measured across all 18
#      Conduction app repositories at origin/development on 2026-08-09,
#      ELEVEN would have started hard-failing: procest carries an entire
#      multi-tenant SaaS stack (26 Tenant* classes, 5 workflow-engine classes)
#      and hermiq a 6-class tenant control plane. Migrating those onto the
#      OpenRegister tenant boundary and lifecycle is an architecture
#      programme, not a deadline.
#
#   2. Blocking on evidence this noisy would have been wrong regardless of
#      time. On that same measurement the MAJORITY of findings were false
#      positives of the rules' own matching (see the rule-1 rewrite below, and
#      the note on rules 2-7): openregister was flagged for a geocoder that
#      routes through OpenConnector exactly as ADR-022 asks, procest for a
#      frontend shim whose docblock CITES this rule, and docudesk for a
#      listener that subscribes to OpenRegister's own ApprovalStep events. A
#      gate must be believable before it is made blocking.
#
#   3. One date instead of two. ADR-022 enforcement previously had two
#      unrelated cliff edges in this one file — the umbrella epoch here and
#      CAP_BLOCK_AFTER_EPOCH for the ADR-051 capability table. Aligning them
#      gives the fleet a single ADR-022 enforcement date to plan against.
#
# The deadline moved on purpose and says so. It was not waived per file and no
# rule was weakened to meet it.
BLOCK_AFTER_EPOCH="${HYDRA_OR_GATE_BLOCK_AFTER_EPOCH:-1790985600}"  # 2026-10-03 00:00 UTC
NOW_EPOCH="$(date -u +%s)"
MODE=0
if [ "${NOW_EPOCH}" -ge "${BLOCK_AFTER_EPOCH}" ]; then
    MODE=1
fi

EXIT_CODE=0
FOUND_ANY=0
# Number of RULES that matched. Reported on the last line so the caller can
# state the size of the evidence even in WARN mode, where the exit status is 0
# either way.
FINDING_COUNT=0
SEARCH_ROOT="${1:-lib}"

if [ ! -d "${SEARCH_ROOT}" ]; then
    echo "lint-or-abstraction-anti-patterns: search root '${SEARCH_ROOT}' not found; skipping."
    exit 0
fi

# ---------------------------------------------------------------------------
# WHICH APP IS THIS?  (and why `basename $(pwd)` is not the answer)
#
# Rule 1 already needed to exempt one app from its own rule — openconnector
# owns the PDOK adapter, so "do not call api.pdok.nl" cannot apply to it — and
# it asked `basename $(pwd)`. That is the checkout DIRECTORY, which in CI is
# whatever `actions/checkout` was told to call it, and in a git worktree is a
# branch-shaped name. `appinfo/info.xml`'s `<id>` is the app's actual identity
# and is what Nextcloud itself uses.
#
# THIS MATTERS BEYOND TIDINESS. Every rule below is an ADR-022 "consume the
# OpenRegister abstraction instead of growing your own" rule, and each one
# tries to exclude OpenRegister's own implementation with `grep -v -i
# openregister`. That filter reads the FILE PATH — and when the linter runs
# inside the openregister repository the paths are `lib/Db/AuditTrail.php`,
# `lib/Db/ApprovalChain.php`, `lib/Service/Geo/PdokGeocoder.php`: not one of
# them contains the string "openregister", so not one is excluded. Measured
# 2026-08-08 on openregister at 28c5d19: 33 findings, every single one a
# canonical OpenRegister implementation being told to consume itself. The gate
# is in WARN mode until 2026-08-13; on that date it starts hard-failing the
# foundation repository over its own source.
#
# So the exemption is expressed once, by app id, for the provider of each
# abstraction — and it is PRINTED, never silent.
_app_id() {
    local _id=""
    if [ -f appinfo/info.xml ]; then
        _id=$(sed -n 's/.*<id>\([^<]*\)<\/id>.*/\1/p' appinfo/info.xml 2>/dev/null | head -1)
    fi
    [ -z "${_id}" ] && _id="$(basename "$(pwd)")"
    printf '%s' "${_id}"
}
APP_ID="$(_app_id)"

flag() {
    local rule="$1"
    local detail="$2"
    if [ "${FOUND_ANY}" -eq 0 ]; then
        if [ "${MODE}" -eq 1 ]; then
            echo "❌ OR-abstraction anti-pattern gate (BLOCK mode after $(date -u -d "@${BLOCK_AFTER_EPOCH}" +%Y-%m-%d)):"
        else
            echo "⚠️  OR-abstraction anti-pattern gate (WARN mode; switches to BLOCK on $(date -u -d "@${BLOCK_AFTER_EPOCH}" +%Y-%m-%d)):"
        fi
    fi
    FOUND_ANY=1
    FINDING_COUNT=$((FINDING_COUNT + 1))
    echo "  [${rule}] ${detail}"
    if [ "${MODE}" -eq 1 ]; then
        EXIT_CODE=1
    fi
}

# Rules 2-7 all say "consume the OpenRegister abstraction". OpenRegister IS the
# abstraction; running them against it asks the provider to consume itself, and
# every one of its canonical classes matches (see the _app_id block above).
# The exemption is announced, so a reader can never mistake this run's silence
# for a clean leaf app.
IS_OR=0
if [ "${APP_ID}" = "openregister" ]; then
    IS_OR=1
    echo "i lint-or-abstraction-anti-patterns: app id is 'openregister' — the ADR-022"
    echo "  'consume the OR abstraction' rules (audit-trail, approval-chain, tenant,"
    echo "  workflow-engine, rbac, and the ADR-051 capability table) do NOT apply to the"
    echo "  repository that PROVIDES those abstractions and are not evaluated here."
    echo "  They remain in force for every leaf app."
fi

# ---------------------------------------------------------------------------
# 1. shared-pdok-via-openconnector — direct PDOK API calls outside openconnector.
# Scope: lib/ + src/ + frontend js/vue files; skip docs, scripts, and openspec.
#
# WHY THIS IS NOT `grep -l api.pdok.nl` ANY MORE.
#
# It was, and on 2026-08-09 that spelling produced three findings fleet-wide of
# which TWO were the opposite of a violation:
#
#   * procest src/services/pdokService.js — the file is the openconnector-routed
#     shim itself (`generateUrl('/apps/openconnector/api/pdok')`); it never
#     contacts PDOK. Its only match was a docblock line reading "Direct browser
#     calls to api.pdok.nl are NOT permitted from this app — see Hydra umbrella
#     `shared-pdok-via-openconnector` (ADR-022)". The gate reported a file as
#     violating the rule because it contains a sentence CITING the rule.
#
#   * openregister lib/Service/Geo/PdokGeocoder.php — matched on a `const`
#     holding the Locatieserver base URL. That URL is the argument handed to
#     OpenConnector's CallService; the class has no HTTP client of its own and
#     returns null when OpenConnector is absent. It is the pattern ADR-022
#     prescribes, reported as the thing ADR-022 forbids.
#
# Only procest's PdokLocatieserverService was real: it fopen()s the endpoint
# directly whenever the `pdok_locatieserver_source` config key is empty, which
# is its default.
#
# A hostname in a comment cannot make an HTTP request, and a hostname handed to
# the shared adapter is the fix, not the defect. So the rule now asks the two
# questions that actually distinguish them:
#   (a) does the host appear on a line of CODE (comments stripped)?  and
#   (b) does the file carry its own HTTP transport, or does it dispatch through
#       OpenConnector?
# Routed files are PRINTED as info, never silently dropped.
#
# This is strictly narrower on prose and NOT narrower on code: a bare URL with
# no OpenConnector reference anywhere in the file still fires, so a file that
# gains a direct fetch cannot slip through by omitting a known transport name.
# ---------------------------------------------------------------------------

# Echo a file with comment-only lines removed. `https://` must survive, so a
# `//` is treated as a comment ONLY when it opens the line; `#` likewise, and
# never for a PHP `#[Attribute]`. Trailing comments are deliberately left in
# place — keeping them can only cause the gate to fire, never to stay silent.
_code_lines() {
    awk '
        BEGIN { inblk = 0 }
        {
            t = $0
            sub(/^[ \t]+/, "", t)
            if (inblk == 1) { if (t ~ /\*\//) { inblk = 0 } ; next }
            if (t ~ /^\/\*/) { if (t !~ /\*\//) { inblk = 1 } ; next }
            if (t ~ /^\/\//) { next }
            if (t ~ /^\*/)   { next }
            if (t ~ /^#/ && t !~ /^#\[/) { next }
            print $0
        }
    ' "$1" 2>/dev/null
}

# Tokens that mean "this file performs its own HTTP call".
_PDOK_DIRECT_TRANSPORT='file_get_contents|fopen[[:space:]]*\(|stream_context_create|curl_init|curl_exec|curl_setopt|GuzzleHttp|HttpClient|XMLHttpRequest|fetch[[:space:]]*\(|axios\.(get|post|put|request)|\$\.ajax'

if [ "${APP_ID}" != "openconnector" ]; then
    _pdok_candidates="$(grep -rl --include='*.php' --include='*.js' --include='*.ts' --include='*.vue' "api\\.pdok\\.nl" "${SEARCH_ROOT}" src 2>/dev/null || true)"
    _pdok_direct=""
    _pdok_routed=""
    _pdok_prose=""
    while IFS= read -r _pf; do
        [ -z "${_pf}" ] && continue
        _pf_code="$(_code_lines "${_pf}")"
        # (a) host mentioned only in prose → not a call site.
        if ! printf '%s\n' "${_pf_code}" | grep -q "api\\.pdok\\.nl"; then
            _pdok_prose="${_pdok_prose}${_pf}"$'\n'
            continue
        fi
        # (b) own transport alongside the host → direct call.
        if printf '%s\n' "${_pf_code}" | grep -qE "${_PDOK_DIRECT_TRANSPORT}"; then
            _pdok_direct="${_pdok_direct}${_pf}"$'\n'
            continue
        fi
        # No transport of its own AND it names OpenConnector → routed.
        if printf '%s\n' "${_pf_code}" | grep -qi 'openconnector'; then
            _pdok_routed="${_pdok_routed}${_pf}"$'\n'
            continue
        fi
        # Host on a code line, no transport named, no OpenConnector anywhere:
        # routing cannot be demonstrated, so this counts against the app.
        _pdok_direct="${_pdok_direct}${_pf}"$'\n'
    done <<< "${_pdok_candidates}"

    _pdok_direct="$(printf '%s' "${_pdok_direct}")"
    _pdok_routed="$(printf '%s' "${_pdok_routed}")"
    _pdok_prose="$(printf '%s' "${_pdok_prose}")"

    if [ -n "${_pdok_direct}" ]; then
        flag "shared-pdok-via-openconnector" "api.pdok.nl contacted with the file's own HTTP transport — route via the openconnector PDOK adapter instead"
        echo "${_pdok_direct}" | sed 's/^/    /'
    fi
    if [ -n "${_pdok_routed}" ]; then
        echo "  ℹ️  [shared-pdok-via-openconnector] references api.pdok.nl but dispatches through OpenConnector — compliant, not counted:"
        echo "${_pdok_routed}" | sed 's/^/      /'
    fi
    if [ -n "${_pdok_prose}" ]; then
        echo "  ℹ️  [shared-pdok-via-openconnector] names api.pdok.nl in comments only — no call site, not counted:"
        echo "${_pdok_prose}" | sed 's/^/      /'
    fi
fi

if [ "${IS_OR}" -eq 0 ]; then
# 2. consume-or-audit-trail-fleet-wide — app-local audit listeners/validators/schemas.
matches="$(find "${SEARCH_ROOT}" -type f \( -iname "*Audit*Listener.php" -o -iname "*Audit*Validator.php" -o -iname "*AuditTrail*.php" \) 2>/dev/null | grep -v -i "openregister" || true)"
if [ -n "${matches}" ]; then
    flag "consume-or-audit-trail-fleet-wide" "app-local audit listener/validator found — emit via OR AuditTrailMapper"
    echo "${matches}" | sed 's/^/    /'
fi

# 3. consume-or-approval-workflow-fleet-wide — app-local approval-chain schemas/services.
matches="$(find "${SEARCH_ROOT}" -type f \( -iname "*ApprovalChain*.php" -o -iname "*ApprovalStep*.php" \) 2>/dev/null | grep -v -i "openregister" || true)"
if [ -n "${matches}" ]; then
    flag "consume-or-approval-workflow-fleet-wide" "app-local ApprovalChain/Step class found — consume OR ApprovalService instead"
    echo "${matches}" | sed 's/^/    /'
fi

# 4. consume-or-tenant-fleet-wide — app-local Tenant schemas/services/middleware.
matches="$(find "${SEARCH_ROOT}" -type f -iname "Tenant*.php" 2>/dev/null | grep -v -i "openregister" || true)"
if [ -n "${matches}" ]; then
    flag "consume-or-tenant-fleet-wide" "app-local Tenant class found — consume OR Organisation + TenantLifecycleService"
    echo "${matches}" | sed 's/^/    /'
fi

# 5. consume-or-workflow-engine-fleet-wide — app-local state-machine / workflow-engine services.
matches="$(find "${SEARCH_ROOT}" -type f \( -iname "*StatusTransition*Service.php" -o -iname "*WorkflowEngine*.php" -o -iname "*StateMachine*.php" \) 2>/dev/null | grep -v -i "openregister" || true)"
if [ -n "${matches}" ]; then
    flag "consume-or-workflow-engine-fleet-wide" "app-local state-machine/workflow-engine class found — use x-openregister-lifecycle + WorkflowEngineInterface"
    echo "${matches}" | sed 's/^/    /'
fi

# 6. consume-or-rbac-fleet-wide — app-local permission/authorization services.
matches="$(find "${SEARCH_ROOT}" -type f \( -iname "*Permission*Service.php" -o -iname "*Authorization*Service.php" \) 2>/dev/null | grep -v -i "openregister" | grep -v -i "AuthenticationService" || true)"
if [ -n "${matches}" ]; then
    flag "consume-or-rbac-fleet-wide" "app-local permission/authorization service found — enforce via OR rbac-scopes"
    echo "${matches}" | sed 's/^/    /'
fi
fi  # IS_OR == 0

# ---------------------------------------------------------------------------
# 7. ADR-051 §4 — OR-owned capability duplication (data-driven).
#
# One row per ADR-022 abstraction-table capability; extend the gate by adding
# a row, NOT code. Seed corpus = the four HEAD violations named in ADR-051 §4
# (pipelinq lib/Service/Avg/*, pipelinq *SyncQueue*, procest Archival*Service,
# procest Tenant*Middleware + search_path tenancy).
#
# WARN-first on the capability rules' own bake-in epoch (they were seeded
# 2026-07-05; ADR-051 acceptance + 90d ≈ 2026-10-03), independent from the
# older umbrella epoch above.
#
# Exception path (ADR-022 exception clause): an app-local ADR under
# openspec/architecture/ that references ADR-022 and literally names the
# affected file path (or its directory) suppresses the finding for exactly
# those paths. Suppressions are printed as info lines so reviewers see them.
# ---------------------------------------------------------------------------
CAP_BLOCK_AFTER_EPOCH="${HYDRA_OR_CAPABILITY_GATE_BLOCK_AFTER_EPOCH:-1790985600}"  # 2026-10-03 00:00 UTC
CAP_MODE=0
if [ "${NOW_EPOCH}" -ge "${CAP_BLOCK_AFTER_EPOCH}" ]; then
    CAP_MODE=1
fi

# Format: <capability-key>|<match-kind>|<pattern>|<guidance>
#   match-kind: path → find -path glob under SEARCH_ROOT
#               name → find -iname glob under SEARCH_ROOT
#               grep → content grep over *.php under SEARCH_ROOT
OR_CAPABILITY_RULES=(
    'avg-dsar-workflow (ADR-047)|path|*/Service/Avg/*.php|app-local AVG/DSAR stack — consume OR lib/Service/Gdpr (DataSubjectRequestService et al.)'
    'mdm-surface (ADR-045)|name|*SyncQueue*.php|app-local MDM sync-queue — consume the OR MDM surface'
    'archival-destruction-workflow|name|Archival*Service.php|app-local archival/e-Depot chain — consume OR archival + destruction workflow'
    'tenant-boundary|name|Tenant*Middleware*.php|app-local tenant middleware — consume the OR tenant boundary'
    'tenant-boundary|grep|search_path|Postgres search_path tenant isolation — consume the OR tenant boundary'
    'semantic-references (ADR-048)|name|*SemanticTypeResolver*.php|app-local semantic-type resolver — consume OR SemanticTypeResolver'
    'semantic-handoffs (ADR-051)|name|*HandoffService*.php|app-local handoff/conversion engine — consume OR HandoffService + the x-openregister-handoff dialect'
)

# Build the exception path-token list once: every path-like token (a token
# containing at least one `/`) mentioned in an app-local ADR
# (openspec/architecture/*.md) that references ADR-022.
CAP_EXCEPTION_PATHS=""
if [ -d openspec/architecture ]; then
    _exception_adrs="$(grep -rl 'ADR-022' openspec/architecture --include='*.md' 2>/dev/null || true)"
    if [ -n "${_exception_adrs}" ]; then
        while IFS= read -r _adr; do
            [ -f "${_adr}" ] || continue
            _adr_paths="$(grep -oE '[A-Za-z0-9_.-]+(/[A-Za-z0-9_.*-]+)+/?' "${_adr}" 2>/dev/null || true)"
            [ -n "${_adr_paths}" ] && CAP_EXCEPTION_PATHS="${CAP_EXCEPTION_PATHS}${_adr_paths}"$'\n'
        done <<< "${_exception_adrs}"
        CAP_EXCEPTION_PATHS="$(printf '%s' "${CAP_EXCEPTION_PATHS}" | sort -u)"
    fi
fi

# Return 0 (suppressed) when the exception ADRs name the finding's exact
# file path, or a directory the finding lives under (true prefix match on
# whole path segments — naming lib/Service/Avg/ never suppresses a sibling
# like lib/Service/Mdm/, and a bare word in prose never suppresses).
_cap_suppressed() {
    _p="$1"
    [ -z "${CAP_EXCEPTION_PATHS}" ] && return 1
    while IFS= read -r _tok; do
        [ -z "${_tok}" ] && continue
        _tok="${_tok%/\*}"    # lib/Service/Avg/* → lib/Service/Avg
        _tok="${_tok%/}"      # lib/Service/Avg/ → lib/Service/Avg
        [ -z "${_tok}" ] && continue
        [ "${_p}" = "${_tok}" ] && return 0
        case "${_p}" in
            "${_tok}"/*) return 0 ;;
        esac
    done <<< "${CAP_EXCEPTION_PATHS}"
    return 1
}

CAP_FOUND_ANY=0
flag_capability() {
    _cap_rule="$1"
    _cap_detail="$2"
    if [ "${CAP_FOUND_ANY}" -eq 0 ]; then
        if [ "${CAP_MODE}" -eq 1 ]; then
            echo "❌ OR-owned capability duplication (ADR-051 §4; BLOCK mode after $(date -u -d "@${CAP_BLOCK_AFTER_EPOCH}" +%Y-%m-%d)):"
        else
            echo "⚠️  OR-owned capability duplication (ADR-051 §4; WARN mode; switches to BLOCK on $(date -u -d "@${CAP_BLOCK_AFTER_EPOCH}" +%Y-%m-%d)):"
        fi
    fi
    CAP_FOUND_ANY=1
    FOUND_ANY=1
    FINDING_COUNT=$((FINDING_COUNT + 1))
    echo "  [or-capability:${_cap_rule}] ${_cap_detail}"
    if [ "${CAP_MODE}" -eq 1 ]; then
        EXIT_CODE=1
    fi
}

# The OpenRegister engine app IS the owner of these capabilities — skip
# entirely (mirrors the openconnector skip on the PDOK rule above).
if [ "${IS_OR}" -eq 0 ]; then
    for _rule_row in "${OR_CAPABILITY_RULES[@]}"; do
        IFS='|' read -r _cap_key _cap_kind _cap_pattern _cap_msg <<< "${_rule_row}"
        case "${_cap_kind}" in
            path) _cap_matches="$(find "${SEARCH_ROOT}" -type f -path "${_cap_pattern}" 2>/dev/null || true)" ;;
            name) _cap_matches="$(find "${SEARCH_ROOT}" -type f -iname "${_cap_pattern}" 2>/dev/null || true)" ;;
            grep) _cap_matches="$(grep -rln --include='*.php' -e "${_cap_pattern}" "${SEARCH_ROOT}" 2>/dev/null || true)" ;;
            *)    _cap_matches="" ;;
        esac
        [ -z "${_cap_matches}" ] && continue
        # OR's own classes vendored/mirrored into an app tree are not
        # app-local duplication.
        _cap_matches="$(echo "${_cap_matches}" | grep -v -i "openregister" || true)"
        [ -z "${_cap_matches}" ] && continue
        _cap_hits=""
        while IFS= read -r _cap_file; do
            [ -z "${_cap_file}" ] && continue
            if _cap_suppressed "${_cap_file}"; then
                echo "  ℹ️  [or-capability:${_cap_key}] suppressed by app-local exception ADR (ADR-022 exception clause): ${_cap_file}"
                continue
            fi
            _cap_hits="${_cap_hits}${_cap_file}"$'\n'
        done <<< "${_cap_matches}"
        _cap_hits="$(printf '%s' "${_cap_hits}")"
        [ -z "${_cap_hits}" ] && continue
        flag_capability "${_cap_key}" "${_cap_msg}"
        echo "${_cap_hits}" | sed 's/^/    /'
    done
fi

if [ "${FOUND_ANY}" -eq 0 ]; then
    echo "✓ OR-abstraction anti-pattern gate clean."
fi

# A MACHINE-READABLE TALLY, because in WARN mode the exit status is 0 whether
# or not anything was found — so the caller cannot tell "clean" from "found
# things and chose not to block" by the byte, which is exactly what gate-23 was
# doing (it printed PASS over 33 findings on openregister and 1 on doriath).
# The caller greps this line and states the number in its verdict.
echo "or_abstraction_findings=${FINDING_COUNT} app_id=${APP_ID} mode=$([ "${MODE}" -eq 1 ] && echo BLOCK || echo WARN)"

exit "${EXIT_CODE}"
