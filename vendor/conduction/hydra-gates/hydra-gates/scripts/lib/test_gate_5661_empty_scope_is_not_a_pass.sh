#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_5661_empty_scope_is_not_a_pass.sh — gates 56-61 must not report
# PASS over a scope they never opened.
#
# WHAT THIS GUARDS (.github#276)
# ------------------------------
# #242/#240 established that an unopened diff scope is not a PASS, and #268
# established that it is `na` rather than `structural`. Both were applied to
# gates 19, 25, 62 and 63 — and to nothing else.
#
# Measured 2026-08-08 on shillinq, one docs-only commit, `--scope-to-diff`:
#
#   [gate-56] register-handler-resolution: PASS      <- 153 registers, 0 opened
#   [gate-57] orphaned-write-capability:   PASS      <- 316 services,  0 opened
#   [gate-58] e2e-networkidle:             PASS      <-  60 e2e files, 0 opened
#   [gate-59] unclosable-gate:             PASS      <- lib/ untouched
#   [gate-60] icon-vocabulary:             PASS      <- no manifest in the diff
#   [gate-61] listener-work-placement:     PASS      <- all 15 out of scope
#   [gate-62] store-plane:                 NOT APPLICABLE   (fixed by #268)
#   [gate-63] settings-surface:            NOT APPLICABLE   (fixed by #268)
#
# Six gates asserting a verdict about code the run had not looked at, sitting
# next to two that had already learned not to. `--require-full-coverage` cannot
# see a PASS, so nothing anywhere reported that six gates had gone quiet.
#
# FOUR ARMS, and the order matters:
#   ARM 1  each gate PASSes over a genuinely clean, genuinely OPENED full tree.
#          Without this, every other arm is satisfiable by a permanent skip.
#   ARM 2  a docs-only diff yields NOT APPLICABLE, with a reason naming
#          ADR-020, and does NOT fail --require-full-coverage.
#   ARM 3  ANTI-WIDENING / the inverse invariant. The SAME repo with a real
#          violation planted in each subject, in a diff that touches it, must
#          FAIL every one of the six. A gate that had been skipped into
#          uselessness passes ARM 2 perfectly and dies here.
#   ARM 4  the `na` verdicts must not be counted as a coverage gap.

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_5661_empty_scope_is_not_a_pass.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-5661.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

_app="${_tmp}/app"
mkdir -p "${_app}/src" "${_app}/appinfo" "${_app}/tests/e2e" \
         "${_app}/lib/AppInfo" "${_app}/lib/Service" "${_app}/lib/Listener" \
         "${_app}/lib/Lifecycle" "${_app}/lib/Settings/register.d" \
         "${_app}/node_modules/vue-material-design-icons"

# gate-60 needs the icon package present, or it reports SKIPPED (wiring) —
# a real coverage gap that would mask ARM 2's exit-code assertion.
for _icon in ViewDashboardOutline CogOutline StoreOutline Bookshelf \
             FileReplaceOutline ViewGridOutline BookOpenPageVariantOutline \
             MapMarkerPathOutline; do
    printf '<template></template>\n' \
        > "${_app}/node_modules/vue-material-design-icons/${_icon}.vue"
done

printf '<?xml version="1.0"?>\n<info>\n <id>leaf</id>\n</info>\n' \
    > "${_app}/appinfo/info.xml"
printf '{"name":"leaf","menu":[{"label":"Dashboard","icon":"ViewDashboardOutline","route":"home"}]}\n' \
    > "${_app}/src/manifest.json"

# gate-56 — a handler reference that RESOLVES.
cat > "${_app}/lib/Settings/register.d/10-thing.json" <<'JSON'
{
  "components": {
    "schemas": {
      "Thing": {
        "slug": "Thing",
        "x-openregister-lifecycle": {
          "states": {
            "closed": { "guard": "OCA\\Leaf\\Lifecycle\\ThingGuard::mayClose" }
          }
        }
      }
    }
  }
}
JSON
cat > "${_app}/lib/Lifecycle/ThingGuard.php" <<'PHP'
<?php
namespace OCA\Leaf\Lifecycle;
class ThingGuard { public function mayClose(array $o): bool { return true; } }
PHP

# gate-57 — a write capability that HAS a production caller.
cat > "${_app}/lib/Service/ThingService.php" <<'PHP'
<?php
namespace OCA\Leaf\Service;
class ThingService { public function publishThing(string $id): void {} }
PHP
cat > "${_app}/lib/Service/ThingCoordinator.php" <<'PHP'
<?php
namespace OCA\Leaf\Service;
class ThingCoordinator {
    public function __construct(private ThingService $svc) {}
    public function run(string $id): void { $this->svc->publishThing($id); }
}
PHP

# gate-58 — an e2e file using the ADR-074 rule 4 remedy.
cat > "${_app}/tests/e2e/thing.spec.ts" <<'TS'
test('thing', async ({ page }) => {
  await page.goto('/apps/leaf/', { waitUntil: 'domcontentloaded' });
});
TS

# gate-59 — a config gate that CLOSES. gate-61 — a post-event listener that
# does no request-path work.
cat > "${_app}/lib/AppInfo/Application.php" <<'PHP'
<?php
namespace OCA\Leaf\AppInfo;
class Application {
    public function register($context): void {
        $context->registerEventListener(
            \OCA\OpenRegister\Event\ObjectCreatedEvent::class,
            \OCA\Leaf\Listener\ThingListener::class
        );
    }
    public function seed($cfg): void {
        $seen = $cfg->getValueString('leaf', 'configuration_version', '');
        if ($seen === '1.0.0') { return; }
        $cfg->setValueString('leaf', 'configuration_version', '1.0.0');
    }
}
PHP
cat > "${_app}/lib/Listener/ThingListener.php" <<'PHP'
<?php
namespace OCA\Leaf\Listener;
class ThingListener {
    public function handle($event): void { $this->counter++; }
}
PHP

(
    cd "${_app}" || exit 1
    git init -q .
    printf 'node_modules/\n' > .gitignore
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1

_run() {  # _run <outfile> [runner args...]
    local out="$1"; shift
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    (
        cd "${_app}" || exit 1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" "$@" . > "${out}" 2>&1
    )
    return $?
}
_verdict() { grep -oE "^\[gate-$2\] [^:]+: [A-Z]+( [A-Z]+)*( \([a-z]+\))?" "$1" | head -1 | sed 's/^[^:]*: //'; }

_GATES="56 57 58 59 60 61"

# ---------------------------------------------------------------------------
# ARM 1 — a genuinely clean subject, genuinely IN SCOPE, PASSes.
#
# Scoped rather than full-tree, because gate-61 is always diff-scoped BY
# DESIGN (it is about new debt; the fleet's 149-registration backlog is a
# work-list, not a reason to redden every repo). Asserting a full-tree PASS
# for it would be asserting a behaviour the gate deliberately does not have.
#
# This arm has to come first: every later assertion here is satisfiable by a
# gate that has been skipped into uselessness, and this is the one that is not.
# ---------------------------------------------------------------------------
(
    cd "${_app}" || exit 1
    # Touch every subject, changing nothing that any gate objects to.
    printf '\n' >> lib/Settings/register.d/10-thing.json
    printf '\n' >> lib/Service/ThingService.php
    printf '\n' >> tests/e2e/thing.spec.ts
    printf '\n' >> lib/AppInfo/Application.php
    printf '\n' >> lib/Listener/ThingListener.php
    printf '{"name":"leaf","menu":[{"label":"Dashboard","icon":"ViewDashboardOutline","route":"home"}],"version":"1.0.1"}\n' \
        > src/manifest.json
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm "touch every subject, cleanly"
) >/dev/null 2>&1

_clean="${_tmp}/clean.txt"
_run "${_clean}" --scope-to-diff --base HEAD~1
for _g in ${_GATES}; do
    _v="$(_verdict "${_clean}" "${_g}")"
    if [ "${_v}" = "PASS" ]; then
        _ok "gate-${_g} opened its in-scope subject and PASSed"
    else
        _bad "gate-${_g} verdict is '${_v}' for a clean subject THE DIFF TOUCHED — expected PASS (a SKIP here means the fixture gives this gate no subject, and every later arm is measuring nothing)"
    fi
done

# gate-59's own extra property: it must also run on an UNSCOPED audit.
# CHANGED_FILES is populated only under --scope-to-diff, so the condition
# `grep '^lib/.*\.php$'` was false for every full-tree run and the gate
# reported PASS having walked no PHP (.github#276). A full-tree audit was the
# one mode it could not reach — #240's sentence, in a gate #240 did not visit.
_full="${_tmp}/full.txt"
_run "${_full}"
_v="$(_verdict "${_full}" 59)"
if [ "${_v}" = "PASS" ]; then
    _ok "gate-59 runs on an UNSCOPED full-tree audit (PASS over a clean tree)"
else
    _bad "gate-59 full-tree verdict is '${_v}' — an unscoped run must audit lib/, not diff-scope itself to nothing"
fi

# ---------------------------------------------------------------------------
# ARM 2 — a docs-only diff is NOT APPLICABLE, never PASS, and never fatal.
#
# The commit is made HERE, after ARM 1's, so `--base HEAD~1` names exactly the
# docs-only change. (Written the other way round first, ARM 1's commit became
# HEAD~1 and ARM 2 measured an empty diff against HEAD itself — which the
# runner resolves through the push-before fallback and is a different test.)
# ---------------------------------------------------------------------------
(
    cd "${_app}" || exit 1
    printf 'docs only\n' > README.md
    git add README.md
    git -c user.email=t@t -c user.name=t commit -qm docs
) >/dev/null 2>&1

_scoped="${_tmp}/scoped.txt"
_run "${_scoped}" --scope-to-diff --base HEAD~1 --require-full-coverage
_scoped_rc=$?

for _g in ${_GATES}; do
    _v="$(_verdict "${_scoped}" "${_g}")"
    case "${_v}" in
        "NOT APPLICABLE")
            _ok "gate-${_g} reports NOT APPLICABLE over an empty diff scope"
            ;;
        PASS)
            _bad "gate-${_g} reported PASS over a scope it never opened — this is the #242 defect, unfixed in this gate"
            ;;
        "SKIPPED (structural)"|"SKIPPED (wiring)")
            _bad "gate-${_g} reported '${_v}' over an empty diff scope — #268: an empty ADR-020 scope must not count against coverage"
            ;;
        *)
            _bad "gate-${_g} empty-scope verdict is '${_v}' — expected NOT APPLICABLE"
            ;;
    esac
done

for _g in ${_GATES}; do
    if grep -qE "^\[gate-${_g}\][^:]*: NOT APPLICABLE — .+(ADR-020|no lib/|no tests/e2e|no lib/AppInfo)" "${_scoped}"; then
        _ok "gate-${_g} states WHY it was not applicable"
    else
        _bad "gate-${_g}'s NOT APPLICABLE line carries no reason — a bare declaration is how a gate disappears quietly"
    fi
done

if [ "${_scoped_rc}" -eq 98 ]; then
    _bad "--require-full-coverage exited 98 over an empty diff scope — the #268 regression"
elif [ "${_scoped_rc}" -eq 0 ]; then
    _ok "--require-full-coverage let an empty diff scope through (exit 0)"
else
    _bad "empty-scope run exited ${_scoped_rc}, expected 0 — see ${_scoped}"
fi

# ---------------------------------------------------------------------------
# ARM 4 — and the `na` verdicts are not counted as a coverage gap.
# ---------------------------------------------------------------------------
if grep -q 'GATES THAT DID NOT RUN' "${_scoped}"; then
    _bad "the empty-scope run reported a coverage gap: $(sed -n '/GATES THAT DID NOT RUN/,$p' "${_scoped}" | grep -oE 'gate-[0-9]+' | tr '\n' ' ')"
else
    _ok "the empty-scope run reports NO coverage gap at all"
fi

# ---------------------------------------------------------------------------
# ARM 3 — ANTI-WIDENING. One violation per gate, in a diff that touches it.
#
# This is the arm that a gate skipped into uselessness cannot survive. ARM 2
# alone is satisfied by making every gate permanently `na`.
# ---------------------------------------------------------------------------
(
    cd "${_app}" || exit 1
    # 56 — a guard class that does not exist.
    sed -i 's#ThingGuard::mayClose#MissingGuard::mayClose#' \
        lib/Settings/register.d/10-thing.json
    # 57 — a NEW write capability on the service, with no caller anywhere.
    #      Deleting ThingCoordinator.php instead does NOT work and the reason
    #      is ADR-020 working correctly: the service file itself would be
    #      unchanged, so it stays out of scope. The defect a PR introduces is
    #      the one this gate answers for.
    cat > lib/Service/ThingService.php <<'PHP'
<?php
namespace OCA\Leaf\Service;
class ThingService {
    public function publishThing(string $id): void {}
    public function publishScore(string $id, float $score): void {}
}
PHP
    # 58 — a wait that never settles.
    printf "test('b', async ({ page }) => { await page.waitForLoadState('networkidle'); });\n" \
        >> tests/e2e/thing.spec.ts
    # 59 — the write that closed the gate, removed.
    sed -i "/setValueString('leaf', 'configuration_version'/d" lib/AppInfo/Application.php
    # 60 — an MDI name that exists nowhere.
    printf '{"name":"leaf","menu":[{"label":"Dashboard","icon":"LedgerOutline","route":"home"}]}\n' \
        > src/manifest.json
    # 61 — outbound I/O on the write path, no deferral, no annotation.
    cat > lib/Listener/ThingListener.php <<'PHP'
<?php
namespace OCA\Leaf\Listener;
use OCP\Http\Client\IClientService;
class ThingListener {
    public function __construct(private IClientService $clientService) {}
    public function handle($event): void {
        $this->clientService->newClient()->post('https://example.invalid/hook');
    }
}
PHP
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm "plant one violation per gate"
) >/dev/null 2>&1

_planted="${_tmp}/planted.txt"
_run "${_planted}" --scope-to-diff --base HEAD~1
for _g in ${_GATES}; do
    _v="$(_verdict "${_planted}" "${_g}")"
    if [ "${_v}" = "FAIL" ]; then
        _ok "gate-${_g} FAILs its planted true positive when the diff touches it"
    else
        _bad "gate-${_g} returned '${_v}' for a planted true positive IN SCOPE — the gate has been skipped into uselessness"
    fi
done

# gate-59, UNSCOPED, with the violation present. This is the arm that
# discriminates: on the pre-fix runner an unscoped run left CHANGED_FILES empty,
# the `grep '^lib/.*\.php$'` guard was false, and the gate printed PASS over an
# unclosable config gate sitting in the tree. A clean-tree PASS cannot tell the
# two apart, because PASS is what the broken version prints either way.
_full_planted="${_tmp}/full-planted.txt"
_run "${_full_planted}"
_v="$(_verdict "${_full_planted}" 59)"
if [ "${_v}" = "FAIL" ]; then
    _ok "gate-59 CATCHES the violation on an unscoped full-tree audit"
else
    _bad "gate-59 returned '${_v}' on an unscoped run over a tree containing an unclosable gate — the full-tree audit is the one mode this gate could not reach (.github#276)"
fi

echo
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_5661_empty_scope_is_not_a_pass.sh: ALL PASS"
    exit 0
fi
echo "test_gate_5661_empty_scope_is_not_a_pass.sh: ${_failures} FAILURE(S)"
exit 1
