#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate — no-phantom-cross-app-rpc (ADR-041).

The gate enforces ADR-041 in two sub-cases:

  Sub-case 1 — phantom cross-app *command* RPC (Rules A–D).
    An app invoking a sibling Conduction NC app's business action through
    the OpenRegister integration registry, a non-existent integration
    service, or a server-side HTTP call to a sibling app's REST route. The
    canonical, sanctioned mechanism for a cross-app *command* is a typed
    ``IEventDispatcher`` event (the RequestedEvent + result-slot +
    ConcludedEvent recipe in ADR-041); the patterns flagged here are the
    nearest-looking-abstraction mistakes that fail closed and never reach
    the target app.

  Sub-case 2 — phantom *foundation* call (Rule E).
    A leaf app calling a public OpenRegister method that has been REMOVED
    (or is forbidden). This is the real ``ObjectService->publish()``
    incident: opencatalogi called an OR method the deprecate-published-
    metadata change deleted; every gate stayed green and the call only
    died at runtime. ADR-041's broader point — there is one foundation
    (OpenRegister) and leaves must speak to it through its *real* public
    surface — covers this. The check has two halves:
      * NOW (active, hard-fail): a curated DENYLIST of known-removed /
        forbidden OR public methods. Any hit is a genuine runtime bug.
      * FORWARD (pass-with-warning): once OpenRegister publishes a
        machine-readable public-API contract, ALL OR calls are checked
        against it. Until that artifact exists, the contract half emits
        only non-blocking warnings to stderr; the denylist is the active
        rule. (ADR-041's contract idea, folded in here.)

Mechanical rules, each emitting one line per HARD finding to stdout:

  Rule A — ``->getLeaf(`` / ``::getLeaf(`` / ``.getLeaf(``
    The OpenRegister integration registry (ADR-019) has **no** ``getLeaf``
    method. Any ``getLeaf`` call is a phantom cross-app command dispatch
    (``$integration->getLeaf('decidesk')->createDecision(...)``). There is
    no legitimate ``getLeaf`` anywhere in the fleet, so the match is
    unconditional.

  Rule B — ``$x->call('<fleetAppId>', ...)``
    A ``->call(`` whose FIRST positional argument is a *string literal*
    matching a known fleet app id is a phantom registry RPC
    (``$registry->call('decidesk', 'createDecision', [...])``). The real
    OpenRegister dispatchers take an **object** as the first argument
    (``CallService::call(ObjectEntity $source, ...)``,
    ``ExternalIntegrationRouter::call(IntegrationProvider $provider, ...)``)
    — OpenConnector external-source dispatch — so requiring a quoted
    app-id string as arg 1 excludes them with zero false positives.

  Rule C — ``OCA\OpenRegister\Service\IntegrationService``
    This class does not exist. Any reference to it (use-import or FQN)
    used as a call target is phantom. The real classes live under
    ``OCA\OpenRegister\Service\Integration\`` (``IntegrationRegistry``,
    ``ExternalIntegrationRouter``, ``IntegrationProvider``,
    ``AbstractIntegrationProvider``) and unrelated app-local
    ``*IntegrationService`` classes (e.g. ``StufRequestIntegrationService``,
    ``ShillinqIntegrationService``) are NOT matched — the rule keys on the
    exact non-existent FQN segment ``OpenRegister\Service\IntegrationService``.

  Rule D — server-side ``IClientService`` POST/GET to a SIBLING app's route
    A ``newClient()->post(`` / ``->get(`` whose URL is built from
    ``linkToRoute('<siblingApp>.…')`` used as RPC. A server-to-server HTTP
    call carries no user session, so it 401s against the sibling's
    ``#[NoAdminRequired]`` endpoint — the ADR-041 phantom shape.
    *Exclusion:* the legitimate in-cluster delegation pattern forwards the
    caller's session (``Cookie`` / ``OCS-APIRequest`` / ``requesttoken`` /
    ``allow_local_address``) so the sibling applies its own RBAC; those
    calls are RBAC-respecting and are NOT phantom RPC. The rule fires only
    when NO session-forwarding marker appears in the surrounding call.

  Rule E — phantom FOUNDATION call: a leaf invoking a REMOVED / forbidden
    OpenRegister public method.
    Active half = the ``OR_REMOVED_METHODS`` DENYLIST below — method names
    the OpenRegister public surface no longer exposes (seeded with the
    deprecate-published-metadata removals: ``ObjectService::publish`` /
    ``depublish`` and ``ObjectEntity::getPublished`` / ``getDepublished`` /
    ``setPublished`` / ``setDepublished``). A ``->publish(`` / ``->getPublished(``
    style call whose method name is on the denylist **and whose receiver is
    an OpenRegister handle** (``$objectService`` / ``$objectEntity`` /
    ``$object`` / ``$or``) is a phantom foundation call → the method is gone;
    use the RBAC ``publicatiedatum`` model. The receiver guard is essential:
    these removed accessors only ever fatal on the OR ObjectService /
    ObjectEntity surface, so a same-named method on any other receiver — NC's
    first-party ``$activityManager->publish()`` (Activity stream), an app's
    own ``$this->publish()`` helper, an app-local
    ``$publicationService->publish()`` — is NOT the removed OR method and is
    NOT flagged. (The receiver-blind match shipped after the acceptance run
    and false-flagged every method literally named ``publish`` across
    pipelinq/decidesk/shillinq/procest/softwarecatalog; the guard restores
    the design's full-tree zero-false-positive guarantee.) Each hit prints
    one stdout line (hard fail) — an OR-handle call to a removed method is a
    real runtime bug.
    Forward half = ``check_against_or_contract()``: once OpenRegister ships
    a public-API contract artifact (see ``_load_or_contract``), every OR
    call is validated against it; until the artifact exists this half emits
    only non-blocking stderr warnings, so it never breaks CI fleet-wide.

Print format (mirrors the other gates):
    <file>:<line> rule=<rule-id> detail=<short>

Only HARD findings go to stdout; the bash wrapper counts printed stdout
lines (and applies ADR-020 diff scoping). Non-blocking warnings go to
stderr. Exit code is always 0. Designed to finish well under 2s on the
largest app.
"""

from __future__ import annotations

import json
import os
import re
import sys

# Known fleet app ids. A ->call('<id>', ...) with one of these as the
# first string-literal argument is a phantom cross-app RPC (Rule B).
FLEET_APP_IDS = {
    "decidesk",
    "docudesk",
    "shillinq",
    "pipelinq",
    "procest",
    "opencatalogi",
    "openconnector",
    "softwarecatalog",
    "zaakafhandelapp",
    "larpingapp",
    "scholiq",
    "openregister",
}

# Rule A — any getLeaf( call. Matches ->getLeaf( / ::getLeaf( / .getLeaf(
# (PHP and JS/Vue call sites). The registry has no such method, so this is
# unconditional.
_GETLEAF_RE = re.compile(r"(?:->|::|\.)\s*getLeaf\s*\(")

# Rule B — ->call('<appId>', ...) with a quoted app id as the first arg.
# Captures the app id so we can check it against FLEET_APP_IDS. The legit
# dispatchers pass an object first ( ->call($source / ->call(source: /
# ->call($provider ), which never matches a quoted-string first arg.
_CALL_APPID_RE = re.compile(
    r"->\s*call\s*\(\s*(['\"])([A-Za-z][A-Za-z0-9_]*)\1\s*,"
)

# Rule C — reference to the non-existent OpenRegister IntegrationService.
# Keys on the exact FQN segment so app-local *IntegrationService classes
# (StufRequestIntegrationService, ShillinqIntegrationService, NC Mail's
# ContactIntegrationService) and the real Service\Integration\* classes are
# never matched.
_INTEGRATION_SERVICE_RE = re.compile(
    r"OpenRegister\\Service\\IntegrationService\b"
)

# Rule D — IClientService HTTP. We first detect a client POST/GET, then
# require the URL to derive from linkToRoute('<sibling>.…'), then require
# NO session-forwarding marker (which would make it a legit RBAC-respecting
# in-cluster call rather than phantom RPC).
_CLIENT_HTTP_RE = re.compile(r"(?:newClient\s*\(\s*\)|clientService)\s*->\s*(?:post|get)\s*\(")
_LINK_TO_ROUTE_RE = re.compile(r"linkToRoute\s*\(\s*(['\"])([A-Za-z][A-Za-z0-9_]*)\.")
# Session-forwarding markers — presence of any of these means the caller
# forwards the user's session so the sibling enforces its own RBAC; that is
# the sanctioned in-cluster delegation pattern, not phantom RPC.
_SESSION_FORWARD_RE = re.compile(
    r"(?i)(Cookie|OCS-APIRequest|requesttoken|allow_local_address)"
)

# ---------------------------------------------------------------------------
# Rule E — phantom FOUNDATION call (a leaf calling a REMOVED/forbidden OR
# public method). Two halves: an active DENYLIST (hard fail) + a forward
# contract check (pass-with-warning until the contract artifact exists).
# ---------------------------------------------------------------------------
#
# Active DENYLIST. Method names the OpenRegister public surface no longer
# exposes. Seeded with the deprecate-published-metadata removals — the real
# `ObjectService->publish()` incident (opencatalogi called an OR method that
# change deleted; passed every gate, died at runtime). Each maps to the
# guidance the gate prints. Add a name here the moment OR removes/forbids a
# public method, and any leaf still calling it fails immediately.
OR_REMOVED_METHODS = {
    # ObjectService — the publish/depublish actions were removed; anonymous
    # publication is now the RBAC model (publicatiedatum<=now + public group).
    "publish": "removed from OpenRegister ObjectService; use the RBAC publicatiedatum model (see ADR-041)",
    "depublish": "removed from OpenRegister ObjectService; use the RBAC publicatiedatum model (see ADR-041)",
    # ObjectEntity — the @self.published metadata accessors were removed.
    "getPublished": "removed from OpenRegister ObjectEntity (@self.published deprecated); use the RBAC publicatiedatum model (see ADR-041)",
    "getDepublished": "removed from OpenRegister ObjectEntity (@self.published deprecated); use the RBAC publicatiedatum model (see ADR-041)",
    "setPublished": "removed from OpenRegister ObjectEntity (@self.published deprecated); do NOT re-add setPublished — use the RBAC publicatiedatum model (see ADR-041)",
    "setDepublished": "removed from OpenRegister ObjectEntity (@self.published deprecated); use the RBAC publicatiedatum model (see ADR-041)",
}

# A method call (`->name(` or `::name(`) whose name is on the denylist. We
# match the call shape and check the captured name against OR_REMOVED_METHODS
# so the set is the single source of truth (no per-name regex to drift).
_METHOD_CALL_RE = re.compile(r"(?:->|::)\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(")

# A method call WITH its immediate receiver captured, so Rule E can require an
# OpenRegister-style handle before flagging a denylisted method name. Group 1
# is the receiver token (the variable/segment immediately left of the `->`),
# group 2 is the method name. We anchor on `->` (instance call) — the removed
# OR accessors (`$objectService->publish`, `$objectEntity->setPublished`) are
# all instance calls — and the receiver is the last `$var` or `->segment`
# chain element before the method (e.g. `$this->objectService` captures
# `objectService`; `$object` captures `object`).
_DENY_CALL_WITH_RECEIVER_RE = re.compile(
    r"(?:\$|->)\s*([A-Za-z_][A-Za-z0-9_]*)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\("
)

# Receiver tokens that denote an OpenRegister ObjectService / ObjectEntity
# handle — the ONLY receivers whose `publish` / `setPublished` / … is the
# removed OR public method. Mirrors `_OR_HANDLE_RE` (the forward half) so the
# active denylist and the forward contract check agree on what "an OR call"
# is. A denylisted method name on ANY OTHER receiver (`$this->activityManager
# ->publish()` = NC Activity stream, `$this->publish()` = the app's own
# method, `$this->publicationService->publish()` = an app-local service) is
# NOT the removed OR method and must NOT be flagged — that receiver-blind
# match was the Rule-E false positive across pipelinq/decidesk/shillinq/
# procest/softwarecatalog (every method literally named `publish`).
_OR_RECEIVER_TOKENS = {"objectservice", "objectentity", "object", "or"}

# Forward half — once OpenRegister publishes a machine-readable public-API
# contract, this env var (or a well-known path) points at it. The artifact is
# the JSON list of {class, methods:[...]} the OR public surface guarantees.
# Until it exists, the forward check is inert (warnings only) so CI is safe.
_OR_CONTRACT_ENV = "HYDRA_OR_PUBLIC_API_CONTRACT"


def _load_or_contract() -> dict | None:
    """Load OpenRegister's public-API contract if it has been published.

    Returns the parsed contract, or None when no artifact exists yet (the
    common case today). When None, the forward contract check is skipped —
    only the active denylist runs — so this never breaks CI fleet-wide.
    """
    path = os.environ.get(_OR_CONTRACT_ENV, "").strip()
    if not path or not os.path.isfile(path):
        return None
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return None


def scan_file(path: str) -> int:
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            text = fh.read()
    except OSError:
        return 0

    lines = text.split("\n")
    violations = 0

    for idx, line in enumerate(lines, start=1):
        # Skip obvious comment-only lines (PHP // / * / # and JS //) so a
        # documented example or a forbidden-pattern note never trips the
        # gate. We keep it simple: a line whose first non-space char starts
        # a comment is ignored for the literal-call rules.
        stripped = line.lstrip()
        is_comment = (
            stripped.startswith("//")
            or stripped.startswith("*")
            or stripped.startswith("/*")
            or stripped.startswith("#")
        )

        # Rule A — getLeaf(
        if not is_comment and _GETLEAF_RE.search(line):
            print(f"{path}:{idx} rule=phantom-getleaf detail=getLeaf()-on-integration-registry")
            violations += 1

        # Rule B — ->call('<fleetAppId>', ...)
        if not is_comment:
            m = _CALL_APPID_RE.search(line)
            if m and m.group(2) in FLEET_APP_IDS:
                print(
                    f"{path}:{idx} rule=phantom-registry-call "
                    f"detail=call('{m.group(2)}',...)-is-cross-app-RPC"
                )
                violations += 1

        # Rule C — IntegrationService FQN (matches even in use-imports;
        # those make the phantom reachable, so flagging them is correct).
        if _INTEGRATION_SERVICE_RE.search(line):
            print(
                f"{path}:{idx} rule=phantom-integration-service "
                f"detail=OCA\\OpenRegister\\Service\\IntegrationService-does-not-exist"
            )
            violations += 1

        # Rule E (active denylist half) — phantom FOUNDATION call: a method
        # call whose name is on OR_REMOVED_METHODS *and whose receiver is an
        # OpenRegister handle*. The removed accessors only ever fatal when
        # called on the OR ObjectService / ObjectEntity surface
        # (`$objectService->publish()`, `$objectEntity->setPublished()`).
        # Requiring an OR-style receiver keeps the original `ObjectService->
        # publish()` incident caught while excluding the receiver-blind false
        # positives — NC's `$activityManager->publish()` (Activity stream),
        # an app's own `$this->publish()`, an app-local
        # `$publicationService->publish()` — which merely share the method
        # name. (Restores the design's full-tree zero-false-positive
        # guarantee; Rule E shipped after the acceptance run and regressed it.)
        if not is_comment:
            for m in _DENY_CALL_WITH_RECEIVER_RE.finditer(line):
                receiver = m.group(1).lower()
                name = m.group(2)
                if name in OR_REMOVED_METHODS and receiver in _OR_RECEIVER_TOKENS:
                    print(
                        f"{path}:{idx} rule=phantom-foundation-call "
                        f"detail={name}()-{OR_REMOVED_METHODS[name]}"
                    )
                    violations += 1

    # Rule D — IClientService POST/GET to a sibling route used as RPC.
    # This rule needs a small window (the client call + the URL expression
    # are often on adjacent lines), so it is evaluated over a sliding
    # window rather than single lines.
    for idx, line in enumerate(lines, start=1):
        if not _CLIENT_HTTP_RE.search(line):
            continue
        # Look at the call site +/- 6 lines for the URL construction and
        # the session-forwarding markers.
        lo = max(0, idx - 1 - 6)
        hi = min(len(lines), idx - 1 + 7)
        window = "\n".join(lines[lo:hi])

        route = _LINK_TO_ROUTE_RE.search(window)
        if not route:
            continue
        route_app = route.group(2)
        # Only a SIBLING app's route is RPC; a self-route is just the app's
        # own URL generation, never cross-app. (We cannot know "self" from
        # the file alone reliably, but a linkToRoute to a fleet app id other
        # than the current dir is the signal. To stay zero-false-positive we
        # additionally require the absence of session forwarding.)
        if route_app not in FLEET_APP_IDS:
            continue
        if _SESSION_FORWARD_RE.search(window):
            # Forwards the caller's session → RBAC-respecting in-cluster
            # delegation, not phantom RPC. Excluded.
            continue
        print(
            f"{path}:{idx} rule=phantom-http-rpc "
            f"detail=IClientService-to-{route_app}-route-without-session-forward"
        )
        violations += 1

    return violations


# Identify a variable that is (very likely) an OpenRegister ObjectService /
# ObjectEntity handle, so the forward contract check only validates calls on
# the OR surface and never touches a leaf's own methods. Conservative on
# purpose — a miss only means we skip a warning, never a false hard fail.
_OR_HANDLE_RE = re.compile(
    r"\$(objectService|objectEntity|object|or)\b", re.IGNORECASE
)


def check_against_or_contract(path: str, contract: dict) -> None:
    """FORWARD half of Rule E — validate OR calls against the published
    public-API contract.

    NON-BLOCKING: every finding is written to stderr only, so it is never
    counted by the bash wrapper and can never break CI. This half activates
    only when an OR contract artifact has been published (``contract`` is
    non-None); until then ``main`` skips it entirely.
    """
    allowed: set[str] = set()
    for entry in contract.get("classes", []):
        for meth in entry.get("methods", []):
            allowed.add(meth)
    if not allowed:
        return

    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            lines = fh.read().split("\n")
    except OSError:
        return

    for idx, line in enumerate(lines, start=1):
        stripped = line.lstrip()
        if (
            stripped.startswith("//")
            or stripped.startswith("*")
            or stripped.startswith("/*")
            or stripped.startswith("#")
        ):
            continue
        # Only inspect lines that call a method on an OR-looking handle.
        if not _OR_HANDLE_RE.search(line):
            continue
        for m in _METHOD_CALL_RE.finditer(line):
            name = m.group(1)
            # Denylist hits are already hard-failed by the active half.
            if name in OR_REMOVED_METHODS:
                continue
            if name not in allowed:
                sys.stderr.write(
                    f"[gate-27][warn] {path}:{idx} rule=or-contract "
                    f"detail={name}()-not-in-OpenRegister-public-contract "
                    f"(non-blocking until verified against ADR-041 contract)\n"
                )


def main(argv: list[str]) -> int:
    contract = _load_or_contract()
    for path in argv[1:]:
        scan_file(path)
        # Forward half runs only when the OR contract artifact exists; it is
        # stderr-only (pass-with-warning), so the denylist stays the active
        # rule and CI is never broken fleet-wide before the contract ships.
        if contract is not None:
            check_against_or_contract(path, contract)
    return 0  # exit 0 always — caller counts printed stdout lines


if __name__ == "__main__":
    sys.exit(main(sys.argv))
