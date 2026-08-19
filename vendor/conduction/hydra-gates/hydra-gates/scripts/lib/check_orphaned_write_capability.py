#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Gate 52 helper — orphaned-write-capability.

Every PUBLIC method on ``lib/Service/**`` whose NAME shapes like a side
effect (posts/creates a GL transaction or journal entry, saves an
OpenRegister object, dispatches an event, emits a notification, writes a
file/export — see ``WRITE_VERB_PREFIXES`` below) MUST have at least one
non-test production caller, OR be invoked through a recognised INDIRECT
seam: an OpenRegister ``handler``/``guard``/``requires``/``save``/
``fallbackGuard``/``preconditions`` entry in a register.d fragment, an
event listener registered in ``lib/AppInfo/Application.php``, a
background job registered in ``appinfo/info.xml``, an ``#[McpTool]``
method on a class OpenRegister's ``AttributeToolScanner`` reflects
(ADR-063 — see ``_build_mcp_attribute_seam``), or a documented
``Log*Adapter`` intentional log-only seam.

TWO REMEDIES, NOT ONE. A zero-caller write capability can be dead OR
redundant, and the finding text only suggests wiring. Before adding a
route, establish whether the capability is ALREADY LIVE under a different
method or class — measured on softwarecatalog, where
``FederationService::publishEntryForFederation()`` had zero callers while
``PublicationService::publish()`` served the same capability through a
live route. Wiring the orphan would have duplicated a live endpoint and
widened the auth surface (it had no per-object guard); deleting it was
correct (softwarecatalog#447).

Observed 2026-07-13 on shillinq (orphan-capability-sweep, 13 filed
issues): ``DisposalJournalEmitter::emit()``, ``IntercompanyJournalService``,
``inventory-cogs-posting.json``'s ``postCOGS``/``postReceipt``/
``postVariance``, five ``Payroll*HandoffService`` classes, and
``OssInvoiceRouter::route()`` were all fully implemented, unit-tested by
calling the class directly, and spec'd "done" — with ZERO production
callers. The capability was 100%% dead while every existing gate and the
test suite stayed green.

**False positives are the primary risk of this gate** — the seam
recognition (register.d handler, event listener, background job, log-only
adapter, explicit exclude annotation) below MUST be checked before a
zero-caller method is reported, or the gate is noise fleet-wide. Worse
than noise: a gate that names LIVE code dead gets someone to delete it.
Observed 2026-07-14 (hydra#106) — ``ObjectService::clearCurrents()`` was
reported orphaned while openconnector's ``EndpointService`` called it from
six live sites; acting on the verdict would have broken endpoint
rendering. Every verdict this gate prints must be PROVABLE from the code
on disk; when it is not, the gate stays silent (see ``main``).

Usage:
    check_orphaned_write_capability.py <service.php> [<service.php> ...]

Prints one finding per line:
    <file>:<line> method=<name> rule=orphaned-write-capability class=<ClassShortName>
Always exits 0 — the calling gate (bash) counts the printed lines.

Suppression: a docblock ``@orphaned-write-capability exclude <reason>``
(reason >= 10 chars) immediately preceding the method declaration
suppresses that one method. Mirrors the fleet's
``@spec exclude <reason>`` / ``@e2e exclude <reason>`` convention.
"""

from __future__ import annotations

import os
import re
import subprocess
import sys

# Foundation repos: the abstractions every leaf app consumes (ADR-022,
# apps-consume-or-abstractions). A PUBLIC method here is frequently invoked
# ONLY from a sibling repo, so a caller index built from this repo alone
# cannot prove deadness. Observed 2026-07-14 (hydra#106):
# ``ObjectService::clearCurrents()`` was reported dead while openconnector's
# EndpointService called it from six live sites — deleting it on the gate's
# word would have broken endpoint rendering.
FOUNDATION_APPS = {"openregister", "openconnector"}

# Directory names that must never be descended into when falling back to a
# filesystem walk: a nested `custom_apps/` (a full Nextcloud server tree
# mounted inside a working dir) masks the repo's own files and can pollute
# the index with a VENDORED copy of another app.
_PRUNE_DIRS = {"custom_apps", "vendor", "node_modules", "tests", ".git"}

# Method names starting with one of these verbs are treated as
# side-effecting ("write capability") candidates. Deliberately excludes
# is*/has*/get*/find*/list*/validate*/check*/ensure*/require*/authorize* —
# those read-or-guard shapes are gate-6's (orphan-auth) territory, not
# this gate's.
WRITE_VERB_PREFIXES = (
    "post",
    "create",
    "save",
    "emit",
    "dispatch",
    "notify",
    "write",
    "export",
    "generate",
    "submit",
    "record",
    "settle",
    "clear",
    "reconcile",
    "seed",
    "publish",
    "issue",
)

_METHOD_RE = re.compile(
    r"^\s*public\s+function\s+(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\("
)
# Anchored to the start of a source line (allowing leading whitespace and an
# optional `final`/`abstract` modifier) so prose inside a docblock comment
# that happens to contain the word "class" (e.g. "calling the class
# directly") is never mistaken for the class declaration — a docblock
# comment line always starts with `*` or `//` after the leading whitespace,
# never with the bare word `class`.
_CLASS_RE = re.compile(
    r"^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)", re.MULTILINE
)
# An `interface X` / `trait X` declaration. Methods declared in an interface
# have no body, so they cannot be "dead" — only a concrete implementation's
# deadness matters (hydra#106, FP class 3). Anchored like _CLASS_RE so
# docblock prose containing the word "interface" never matches.
_INTERFACE_RE = re.compile(
    r"^\s*(?:final\s+|abstract\s+)?(?:interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)",
    re.MULTILINE,
)
_LOG_ADAPTER_RE = re.compile(r"Log\w*Adapter", re.IGNORECASE)

# --- attribute-scanner seam (#200) -----------------------------------------
# The interface OpenRegister's AttributeToolScanner enumerates (ADR-063).
_MCP_SCANNABLE_INTERFACE = "IMcpScannableServices"
# `registerServiceAlias('OCA\OpenRegister\Mcp\IMcpScannableServices::<app>', Impl::class)`
# On disk the namespace separators are double-backslashed inside the PHP
# string literal, so the pattern must accept either spelling.
#
# ⚠️ THE LEADING BACKSLASH (.github#276). The implementation argument's
# namespace prefix was `(?:[A-Za-z_][A-Za-z0-9_]*\s*\\+\s*)*` — every segment
# had to START with a letter. So the FULLY-QUALIFIED spelling
#
#     registerServiceAlias('…IMcpScannableServices::app', \OCA\App\Mcp\Impl::class)
#
# did not match, and it is the ordinary way to write an FQCN inside a
# namespaced Application.php with no `use` for it. When it does not match,
# `bound_impls` is empty, `_build_mcp_attribute_seam` short-circuits to the
# empty set, and EVERY `#[McpTool]` write method in the app is reported dead —
# which is #200 exactly: the finding whose remedy is deleting a live, curated
# MCP write tool. Reproduced on shillinq with the two spellings side by side:
# leading `\` -> the seam vanished; no leading `\` -> the seam held.
#
# The seam's three-part evidence is unchanged. This only stops the alias being
# invisible because of how its author spelled a namespace.
_MCP_ALIAS_RE = re.compile(
    r"registerServiceAlias\s*\(\s*"
    r"(['\"])[^'\"]*" + _MCP_SCANNABLE_INTERFACE + r"::[A-Za-z0-9_-]+\1"
    r"\s*,\s*\\*(?:[A-Za-z_][A-Za-z0-9_]*\s*\\+\s*)*"
    r"([A-Za-z_][A-Za-z0-9_]*)\s*::\s*class",
    re.DOTALL,
)
# `Foo::class` inside a scannable-services implementation.
_CLASS_CONST_RE = re.compile(r"([A-Za-z_][A-Za-z0-9_]*)\s*::\s*class")
# `#[McpTool(...)]` — optionally namespace-qualified. NOT a comment: `#` opens
# a line comment in PHP but `#[` opens an attribute, which is exactly why the
# comment blanker below has to know the difference.
#
# ⚠️ SAME LEADING-BACKSLASH BLIND SPOT AS _MCP_ALIAS_RE ABOVE (.github#276),
# and this is why the honest regression mutant is BOTH sites at once: the seam
# needs the alias AND the attribute, so reverting either one alone still
# reproduces the false positive, and reverting one while testing the other
# looks like the fix did nothing.
#
#     #[McpTool(name: 'x')]                         matched before
#     #[OCA\OpenRegister\Mcp\McpTool(name: 'x')]    matched before
#     #[\OCA\OpenRegister\Mcp\McpTool(name: 'x')]   did NOT match
#
# The third is how the attribute is written with no `use` import for it — a
# style choice that silently removed the method's only proof of reachability
# and put a live MCP write tool back on the deletion list (#200).
_MCP_TOOL_ATTR_RE = re.compile(
    r"#\[\s*\\*(?:[A-Za-z_][A-Za-z0-9_]*\s*\\+\s*)*McpTool\b"
)
# Lines that cannot belong to the attribute block of the method below them.
# ONLY the statement/block terminators. A word-based boundary (`\bclass\b`)
# was tried first and is wrong: `#[McpTool(handler: Foo::class)]` contains the
# word `class` inside its own arguments, so the walk-up would stop before
# reading the attribute it exists to find. Everything that can legally sit
# directly above a method's attribute block — the previous method's `}`, a
# property or `use` `;`, the class's opening `{` — ends in one of these.
_ATTR_BOUNDARY_RE = re.compile(r"[{};]\s*$")
_EXCLUDE_RE = re.compile(
    r"@orphaned-write-capability\s+exclude\s+(?P<reason>.{10,})"
)


def _is_write_method(name: str) -> bool:
    return any(name.startswith(v) and len(name) > len(v) and name[len(v)].isupper() for v in WRITE_VERB_PREFIXES) or name in WRITE_VERB_PREFIXES


def _class_short_name(src: str) -> str | None:
    m = _CLASS_RE.search(src)
    return m.group(1) if m else None


def _is_interface_or_trait(src: str) -> bool:
    """True when the file's first type declaration is an interface/trait."""
    cls = _CLASS_RE.search(src)
    iface = _INTERFACE_RE.search(src)
    if iface is None:
        return False
    return cls is None or iface.start() < cls.start()


def _declaration_has_body(lines: list[str], method_line_idx: int) -> bool:
    """True when the method declaration opens a `{` block rather than
    terminating in `;`.

    An abstract method inside an abstract class has no body, so there is
    nothing that can be dead. Scans forward across a multi-line signature
    for whichever of `{` / `;` appears first (hydra#106, FP class 3)."""
    for line in lines[method_line_idx : method_line_idx + 12]:
        code = re.sub(r"//.*$", "", line)
        # Drop quoted literals so a `;` or `{` inside a default string value
        # (e.g. `string $sep = ';'`) cannot be read as the terminator.
        code = re.sub(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"", "", code)
        for ch in code:
            if ch == "{":
                return True
            if ch == ";":
                return False
    # Ambiguous (unterminated within the lookahead) — assume a body so the
    # method is still checked. Fail-safe direction for THIS check is to
    # keep scanning: skipping would silently drop a true positive.
    return True


def _preceding_docblock_has_exclude(lines: list[str], method_line_idx: int) -> bool:
    """Look backward from the method declaration for a /** ... */ docblock
    and check it for the exclude annotation. method_line_idx is 0-based."""
    i = method_line_idx - 1
    # Skip attribute lines (#[...]) directly above the method.
    while i >= 0 and lines[i].strip().startswith("#["):
        i -= 1
    if i < 0 or "*/" not in lines[i]:
        return False
    # Walk upward collecting the docblock body until the opening /**.
    block = []
    while i >= 0:
        block.append(lines[i])
        if "/**" in lines[i]:
            break
        i -= 1
    text = "\n".join(reversed(block))
    return bool(_EXCLUDE_RE.search(text))


# ---------------------------------------------------------------------------
# Indirect-seam detection — built once per run from the repo tree.
# ---------------------------------------------------------------------------

_SEAM_HANDLER_KEYS = ("handler", "guard", "requires", "save", "fallbackGuard", "preconditions")
_FQCN_METHOD_RE = re.compile(r"^\\?(?:[A-Za-z_][A-Za-z0-9_]*\\)+([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)$")


def _find_register_files(app_root: str) -> list[str]:
    out = []
    settings_dir = os.path.join(app_root, "lib", "Settings")
    if not os.path.isdir(settings_dir):
        return out
    for dirpath, _dirnames, filenames in os.walk(settings_dir):
        for fn in filenames:
            if fn.endswith(".json") and ("register" in fn or "register.d" in dirpath):
                out.append(os.path.join(dirpath, fn))
    return out


def _walk_seam_refs(obj, refs: list[str]):
    if isinstance(obj, dict):
        for key, value in obj.items():
            if key in _SEAM_HANDLER_KEYS:
                if isinstance(value, str):
                    refs.append(value)
                elif isinstance(value, list):
                    refs.extend(v for v in value if isinstance(v, str))
            _walk_seam_refs(value, refs)
    elif isinstance(obj, list):
        for item in obj:
            _walk_seam_refs(item, refs)


def _build_register_handler_seam(app_root: str) -> set[tuple[str, str]]:
    """Return a set of (ClassShortName, methodName) pairs referenced as
    OR-invoked handlers/guards anywhere under lib/Settings/**. Uses a real
    JSON parse (not raw-text regex) so the fleet's double-backslash JSON
    escaping of namespace separators (`"OCA\\\\Foo\\\\Bar::baz"` on disk,
    a single backslash once json-decoded) is handled for free."""
    import json as _json

    seam: set[tuple[str, str]] = set()
    for fp in _find_register_files(app_root):
        try:
            with open(fp, encoding="utf-8", errors="replace") as fh:
                doc = _json.load(fh)
        except (OSError, ValueError):
            continue
        refs: list[str] = []
        _walk_seam_refs(doc, refs)
        for ref in refs:
            m = _FQCN_METHOD_RE.match(ref.strip())
            if m:
                seam.add((m.group(1), m.group(2)))
    return seam


def _blank_php_comments(text: str) -> str:
    """Replace PHP comment CONTENT with spaces, preserving length and newlines.

    WHY THIS IS NOT OPTIONAL HERE
    -----------------------------
    Every seam this gate recognises is evidence that a method is REACHABLE, so
    a seam matched inside a comment EXEMPTS live-looking code that nothing
    actually calls — a false GREEN — and, for the attribute seam specifically,
    prose is exactly where the phrase turns up. ``LeadService.php`` opens with

        * Both public entry points are annotated `#[McpTool]` (OpenRegister…

    on line 7, seven lines above anything executable. A raw-text search for
    ``#[McpTool`` matches that sentence. This is the shape gate-64's
    ``has_prelude()`` had — a checker that greps text misses every constant
    AND matches every comment, failing in both directions at once.

    Offsets and line numbers are preserved so the result can be used
    line-for-line against the original.

    ``#`` opens a line comment in PHP, but ``#[`` opens an ATTRIBUTE. Blanking
    attributes would delete the very thing being looked for, so the two are
    distinguished.
    """
    out = list(text)
    i = 0
    n = len(text)
    while i < n:
        ch = text[i]
        if ch in ("'", '"'):
            quote = ch
            i += 1
            while i < n:
                if text[i] == "\\":
                    i += 2
                    continue
                if text[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        if ch == "/" and i + 1 < n and text[i + 1] == "*":
            j = text.find("*/", i + 2)
            j = n if j == -1 else j + 2
            for k in range(i, j):
                if out[k] != "\n":
                    out[k] = " "
            i = j
            continue
        if (ch == "/" and i + 1 < n and text[i + 1] == "/") or (
            ch == "#" and not (i + 1 < n and text[i + 1] == "[")
        ):
            j = text.find("\n", i)
            j = n if j == -1 else j
            for k in range(i, j):
                out[k] = " "
            i = j
            continue
        i += 1
    return "".join(out)


def _build_mcp_attribute_seam(app_root: str) -> set[str]:
    """Short class names OpenRegister's ``AttributeToolScanner`` may reflect.

    THE SEAM (#200, ADR-063). A method reached only by attribute reflection
    has no ``->method(`` call site anywhere, so the caller index cannot see it
    and the gate reported it dead. Measured on pipelinq:
    ``LeadService::createLead`` — a curated, spec'd MCP write tool — was
    flagged, and acting on that verdict would have deleted it. Same shape as
    hydra#106, which this module's docstring already records as the failure
    mode that matters.

    THREE-PART EVIDENCE, all of which must hold, mirroring what the
    register.d-handler and event-listener seams already demand:

      1. ``lib/AppInfo/Application.php`` binds an implementation under the
         ``…IMcpScannableServices::<app>`` DI alias, and
      2. that implementation lists class constants (``Foo::class``), and
      3. (checked per method in :func:`scan_file`) the method itself carries
         an ``#[McpTool(...)]`` attribute.

    So a bare ``#[McpTool]`` on a class nobody registers stays RED, a
    registered class's write methods that carry NO attribute stay RED, and a
    scannable-services implementation that is never aliased buys nothing.
    """
    bound_impls: set[str] = set()
    lib_dir = os.path.join(app_root, "lib")
    for dirpath, _dirnames, filenames in os.walk(lib_dir):
        for fn in filenames:
            if fn != "Application.php":
                continue
            try:
                with open(os.path.join(dirpath, fn), encoding="utf-8", errors="replace") as fh:
                    text = _blank_php_comments(fh.read())
            except OSError:
                continue
            for m in _MCP_ALIAS_RE.finditer(text):
                bound_impls.add(m.group(2))
    if not bound_impls:
        return set()

    scannable: set[str] = set()
    for dirpath, dirnames, filenames in os.walk(lib_dir):
        dirnames[:] = [d for d in dirnames if d not in _PRUNE_DIRS]
        for fn in filenames:
            if not fn.endswith(".php"):
                continue
            fp = os.path.join(dirpath, fn)
            try:
                with open(fp, encoding="utf-8", errors="replace") as fh:
                    raw = fh.read()
            except OSError:
                continue
            text = _blank_php_comments(raw)
            short = _class_short_name(text)
            if short is None or short not in bound_impls:
                continue
            if _MCP_SCANNABLE_INTERFACE not in text:
                # The alias named this class, but the class does not implement
                # the scanner's interface — OpenRegister would never reflect
                # it, so it grants nothing.
                continue
            for m in _CLASS_CONST_RE.finditer(text):
                scannable.add(m.group(1))
    scannable.discard("self")
    scannable.discard("static")
    scannable -= bound_impls
    return scannable


def _method_has_mcp_attribute(blanked_lines: list[str], method_line_idx: int) -> bool:
    """Does an ``#[McpTool(...)]`` attribute apply to the method at *idx*?

    Walks up over the attribute block only. *blanked_lines* must come from
    :func:`_blank_php_comments`, so a docblock that merely MENTIONS
    ``#[McpTool]`` in prose is already whitespace by the time it is read.
    """
    region: list[str] = []
    j = method_line_idx - 1
    while j >= 0:
        stripped = blanked_lines[j].strip()
        if stripped == "":
            j -= 1
            continue
        if _ATTR_BOUNDARY_RE.search(stripped):
            break
        region.append(stripped)
        j -= 1
    return _MCP_TOOL_ATTR_RE.search("\n".join(region)) is not None


def _build_listener_class_seam(app_root: str) -> set[str]:
    """Short class names registered via registerEventListener(...) in any
    AppInfo/Application.php — invoked by the event dispatcher, not by an
    explicit ->method( call site."""
    seam: set[str] = set()
    for dirpath, _dirnames, filenames in os.walk(os.path.join(app_root, "lib")):
        for fn in filenames:
            if fn != "Application.php":
                continue
            fp = os.path.join(dirpath, fn)
            try:
                with open(fp, encoding="utf-8", errors="replace") as fh:
                    # A COMMENTED-OUT REGISTRATION IS NOT A REGISTRATION.
                    # This seam EXEMPTS a whole class, so matching one inside a
                    # comment is a false GREEN: the listener is not wired, its
                    # write methods are dead, and the gate says nothing.
                    # Latent rather than observed — a sweep of the 8 repos
                    # currently under gate found zero comment-only matches, so
                    # this changes no verdict today — but it is the same shape
                    # as gate-64's has_prelude(), where a commented-out prelude
                    # counted as compliance.
                    text = _blank_php_comments(fh.read())
            except OSError:
                continue
            for m in re.finditer(
                r"registerEventListener\s*\([^;]*?([A-Za-z_][A-Za-z0-9_]*)::class\s*\)",
                text,
                re.DOTALL,
            ):
                seam.add(m.group(1))
            # Two-class-argument form: registerEventListener(Event::class, Listener::class)
            for m in re.finditer(
                r"registerEventListener\s*\(\s*[A-Za-z_\\]+::class\s*,\s*([A-Za-z_\\]*?)([A-Za-z_][A-Za-z0-9_]*)::class",
                text,
                re.DOTALL,
            ):
                seam.add(m.group(2))
    return seam


def _build_job_class_seam(app_root: str) -> set[str]:
    """Short class names registered as a <job> in appinfo/info.xml —
    invoked by the NC background-job scheduler."""
    seam: set[str] = set()
    info_xml = os.path.join(app_root, "appinfo", "info.xml")
    if not os.path.isfile(info_xml):
        return seam
    try:
        with open(info_xml, encoding="utf-8", errors="replace") as fh:
            # `<!-- <job>…</job> -->` is a job that is NOT scheduled. Same
            # false-GREEN shape as the listener seam above: this exempts the
            # whole class. XML comments, not PHP ones, so a separate strip.
            text = re.sub(r"<!--.*?-->", " ", fh.read(), flags=re.DOTALL)
    except OSError:
        return seam
    for m in re.finditer(r"<job>\s*([A-Za-z0-9_\\]+)\s*</job>", text):
        fqcn = m.group(1)
        short = fqcn.split("\\")[-1]
        seam.add(short)
    return seam


def _enumerate_lib_php(app_root: str, include_untracked: bool = False) -> list[str]:
    """PHP files under <app_root>/lib, enumerated via ``git ls-files``.

    Enumerating with git rather than a bare filesystem walk means a nested
    ``custom_apps/`` (a whole NC server tree mounted inside a working dir)
    or any vendored copy of another app — none of which this repo tracks or
    un-ignores — can no longer mask the repo's own files or pollute the
    caller index (hydra#106, FP class 2). Falls back to a pruned
    ``os.walk`` when the tree is not a git checkout (e.g. a CI tarball).

    ``include_untracked`` adds present-but-uncommitted files (still minus
    gitignored ones). Use it ONLY for the caller index: an uncommitted
    local call site is a real caller, and missing it would let a dirty
    working tree manufacture a false "dead" verdict. Scan TARGETS stay
    tracked-only, so the gate judges committed code."""
    lib_dir = os.path.join(app_root, "lib")
    if not os.path.isdir(lib_dir):
        return []
    argv = ["git", "-C", app_root, "ls-files", "-z", "--cached"]
    if include_untracked:
        argv += ["--others", "--exclude-standard"]
    argv += ["lib/*.php"]
    try:
        out = subprocess.run(
            argv,
            capture_output=True,
            timeout=60,
            check=True,
        ).stdout.decode("utf-8", "replace")
        rel = [p for p in out.split("\0") if p]
        if rel:
            return [
                os.path.join(app_root, p)
                for p in rel
                if not any(seg in _PRUNE_DIRS for seg in p.split("/")[:-1])
            ]
    except (OSError, subprocess.SubprocessError):
        pass
    files: list[str] = []
    for dirpath, dirnames, filenames in os.walk(lib_dir):
        dirnames[:] = [d for d in dirnames if d not in _PRUNE_DIRS]
        files.extend(
            os.path.join(dirpath, fn) for fn in filenames if fn.endswith(".php")
        )
    return files


def _is_foundation(app_root: str) -> bool:
    """True when the repo under scan is one every leaf app consumes."""
    env = os.environ.get("HYDRA_OWC_FOUNDATION")
    if env is not None:
        return env.strip() not in ("", "0", "false", "no")
    return os.path.basename(os.path.abspath(app_root)) in FOUNDATION_APPS


def _find_sibling_app_roots(app_root: str) -> list[str]:
    """Sibling Nextcloud apps checked out next to app_root.

    A sibling qualifies when it has BOTH ``appinfo/info.xml`` (it is an NC
    app, not a stray directory) and a ``lib/`` tree. Returns [] when the
    repo is checked out alone — the caller MUST treat that as "cannot
    prove", never as "no callers exist"."""
    parent = os.path.dirname(os.path.abspath(app_root))
    if not os.path.isdir(parent):
        return []
    roots = []
    for name in sorted(os.listdir(parent)):
        cand = os.path.join(parent, name)
        if os.path.abspath(cand) == os.path.abspath(app_root):
            continue
        if not os.path.isdir(cand) or name in _PRUNE_DIRS:
            continue
        if os.path.isfile(os.path.join(cand, "appinfo", "info.xml")) and os.path.isdir(
            os.path.join(cand, "lib")
        ):
            roots.append(cand)
    return roots


def _build_caller_index(app_root: str, extra_roots: list[str] | None = None) -> dict[str, int]:
    """Count of `->methodName(` occurrences across lib/ (tests/ excluded).

    Same-file self-calls DO count as a caller — matches the fleet's gate-6
    (orphan-auth) convention: a public helper invoked from elsewhere in the
    same class is a legitimate call site, not an orphan.

    ``extra_roots`` adds sibling repos' lib/ trees to the index so that a
    FOUNDATION-repo method consumed only across the app boundary is not
    reported dead. The index is keyed on method NAME only (not FQCN), which
    is deliberately over-permissive: a name collision inflates the caller
    count and SUPPRESSES a finding. That is the fail-safe direction — this
    gate must never manufacture a "dead" verdict for live code."""
    counts: dict[str, int] = {}
    call_re = re.compile(r"->([A-Za-z_][A-Za-z0-9_]*)\s*\(")
    for root in [app_root, *(extra_roots or [])]:
        for fp in _enumerate_lib_php(root, include_untracked=True):
            try:
                with open(fp, encoding="utf-8", errors="replace") as fh:
                    text = fh.read()
            except OSError:
                continue
            for m in call_re.finditer(text):
                counts[m.group(1)] = counts.get(m.group(1), 0) + 1
    return counts


def scan_file(file_path: str, app_root: str, seams, caller_counts, findings: list[str]):
    register_seam, listener_seam, job_seam, mcp_seam = seams
    try:
        with open(file_path, encoding="utf-8", errors="replace") as fh:
            text = fh.read()
    except OSError:
        return
    lines = text.split("\n")
    blanked_lines = _blank_php_comments(text).split("\n")
    if _is_interface_or_trait(text):
        # Interface/trait method declarations have no body — there is
        # nothing to be dead. Only the concrete implementation's deadness
        # matters, and that file is scanned on its own merits (hydra#106).
        return
    class_short = _class_short_name(text)
    if class_short and _LOG_ADAPTER_RE.search(class_short):
        # Documented intentional log-only adapter seam — entire class exempt.
        return
    if class_short and class_short in listener_seam:
        # Registered event listener — invoked by the dispatcher, not by a
        # named call site. Entire class exempt.
        return
    if class_short and class_short in job_seam:
        # Registered background job — invoked by the NC job scheduler.
        return

    for idx, line in enumerate(lines):
        m = _METHOD_RE.match(line)
        if not m:
            continue
        name = m.group("name")
        if not _is_write_method(name):
            continue
        if not _declaration_has_body(lines, idx):
            # Abstract declaration inside an abstract class — no body.
            continue
        if _preceding_docblock_has_exclude(lines, idx):
            continue
        if class_short and (class_short, name) in register_seam:
            continue
        if (
            class_short
            and class_short in mcp_seam
            and _method_has_mcp_attribute(blanked_lines, idx)
        ):
            # Attribute-scanner seam (#200): OpenRegister's AttributeToolScanner
            # reflects this class and invokes this method. There is no
            # `->method(` call site anywhere and there never will be, so the
            # caller index cannot see it. PER METHOD, not per class — a write
            # method on a scannable class that carries no `#[McpTool]` is still
            # an orphan and still reported.
            continue
        if caller_counts.get(name, 0) > 0:
            continue
        findings.append(
            f"{file_path}:{idx + 1} method={name} rule=orphaned-write-capability class={class_short or '?'}"
        )


def _app_root_for(file_path: str) -> str:
    """Derive the owning app root for *file_path* from the file's OWN path.

    A scanned service always lives at ``<app_root>/lib/Service/**``, so the
    app root is discoverable from the file itself and must NEVER be taken
    from the process cwd. Taking it from ``os.getcwd()`` broke this gate in
    exactly the direction it exists to prevent: invoked from anywhere other
    than the app root (a fleet-wide sweep from ``apps-extra/`` passing
    ``openregister/lib/Service/ObjectService.php``, a reviewer's ad-hoc run),
    ``app_root`` resolved to the SWEEP dir, so ``_is_foundation`` saw
    basename ``apps-extra``, skipped the sibling scan entirely, and reported
    ``clearCurrents`` dead again — the live method (hydra#106) whose six
    openconnector call sites this whole gate change exists to see. The seam
    builders were equally blinded: ``<cwd>/lib/Settings`` did not exist, so
    every register.d handler / listener / job seam came back empty.

    This mirrors gate-56's ``app_root_for`` (hydra#108/#109), which fixed the
    same cwd bug after it produced 242 false positives from a foreign cwd.
    The two gates stay separate modules on purpose — each ``check_*.py`` is
    self-contained because the container layout flattens them side by side.

    Walks up from the file and returns the first ancestor holding
    ``appinfo/info.xml`` (the canonical NC app marker). Failing that, uses
    the parent of the nearest ``lib/`` component ON THE FILE'S OWN PATH.
    Matching a path component of the file rather than "any ancestor that
    contains a lib/ dir" matters: the latter matches ``/`` on any Linux box
    (``/lib`` exists), silently rooting the search at the filesystem root.

    Falls back to the cwd only when neither marker is found, so a caller that
    already chdir'd into the app root keeps working unchanged.
    """
    directory = os.path.dirname(os.path.abspath(file_path))
    fallback = None
    while True:
        if os.path.isfile(os.path.join(directory, "appinfo", "info.xml")):
            return directory
        if fallback is None and os.path.basename(directory) == "lib":
            fallback = os.path.dirname(directory)
        parent = os.path.dirname(directory)
        if parent == directory:
            break
        directory = parent
    if fallback is not None:
        return fallback
    return os.getcwd()


def _scan_app(app_root: str, files: list[str], findings: list[str]) -> None:
    """Scan *files*, all of which belong to the app at *app_root*."""
    register_seam = _build_register_handler_seam(app_root)
    listener_seam = _build_listener_class_seam(app_root)
    job_seam = _build_job_class_seam(app_root)
    mcp_seam = _build_mcp_attribute_seam(app_root)

    # --- Cross-app caller awareness (hydra#106, FP class 1) --------------
    # A leaf app's services are consumed only by that app (ADR-022: apps
    # consume OpenRegister's abstractions, not each other), so its own lib/
    # is the whole world and a zero-caller verdict is sound. A FOUNDATION
    # repo is different: its public methods exist to be called from sibling
    # apps, so proving deadness requires those siblings on disk.
    extra_roots: list[str] = []
    if _is_foundation(app_root):
        extra_roots = _find_sibling_app_roots(app_root)
        if not extra_roots:
            # FAIL SAFE: the consumers of this foundation repo are not on
            # disk (single-repo CI checkout), so "zero callers" cannot be
            # distinguished from "callers live in a repo we cannot see".
            # Reporting here is what made the gate dangerous — it named
            # live code dead. Report nothing and say why.
            print(
                f"[orphaned-write-capability] SKIP: {os.path.basename(app_root)} is a "
                "foundation repo and no sibling apps were found next to it — cross-app "
                "callers cannot be resolved, so deadness is unprovable. See hydra#106.",
                file=sys.stderr,
            )
            return

    caller_counts = _build_caller_index(app_root, extra_roots)

    for fp in files:
        scan_file(
            fp,
            app_root,
            (register_seam, listener_seam, job_seam, mcp_seam),
            caller_counts,
            findings,
        )


def main(argv):
    # Group the arguments by the app each file ACTUALLY belongs to, derived
    # from the file's own path (see _app_root_for). A fleet sweep can hand
    # this gate files from several repos in one invocation; each app must be
    # judged against its own seams and its own caller index, never against
    # whichever directory the process happened to start in.
    by_root: dict[str, list[str]] = {}
    for fp in argv[1:]:
        by_root.setdefault(_app_root_for(fp), []).append(fp)

    findings: list[str] = []
    for app_root, files in sorted(by_root.items()):
        _scan_app(app_root, files, findings)
    for line in findings:
        print(line)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
