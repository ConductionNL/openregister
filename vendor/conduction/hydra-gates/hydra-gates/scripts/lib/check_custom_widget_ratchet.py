#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Gate 29 helper — custom-widget-ratchet.

Governs the growth of custom ``kind: "widget"`` component-registry entries
(the ADR-036 five-kind registry passed to ``CnAppRoot``) per ADR-049
Decision 1 (built-in-first rule for widgets):

1. **Justification** — any ``kind:"widget"`` registry entry ADDED or MODIFIED
   in the PR diff without a ``_note`` field fails the gate with the canonical
   message from the ``hydra-gate-custom-widget-ratchet`` spec.
2. **Ratchet** — the app's total custom-widget count on the PR head MUST NOT
   exceed the count on the base ref. Growth fails even when every new entry
   carries a ``_note``, unless the increase is accompanied by a documented
   exception (see below). The counts (base, head, delta) are always reported
   so migrations can demonstrate the count shrinking.

Library built-in widget keys (``object-table``, ``card-grid``,
``form-renderer``, ``map-viewer``, ``chart``, ``stats-block``,
``wiki-renderer``) are NOT custom registry entries: they are excluded from
both the count and the justification check.

Documented exception (matching the gate-16/19 ``exclude``-reason convention):
a comment carrying ``@custom-widget-ratchet exclude <reason>`` inside the
entry object — or on one of the three lines directly above it — removes that
ADDED/MODIFIED entry from the head count, so a justified one-off can grow the
total. A bare marker without a reason does not count. The ``_note`` is still
required either way.

Usage:
    check_custom_widget_ratchet.py <src-file> [<src-file> ...]

The caller passes ALL registry-candidate files (``src/**/*.{js,ts,vue}``),
not just changed ones — the ratchet count is app-wide, so unchanged files
contribute to both sides of the comparison.

Diff-scoping (ADR-020): when the ``HYDRA_GATE_BASE_REF`` env var is set
(the gate wiring passes ``BASE_REF``, default ``origin/development``), the
helper self-scopes: only entries whose lines are ADDED or MODIFIED vs the
base ref are checked for a ``_note``; untouched legacy entries never block a
PR. When no changed file declares a ``kind:"widget"`` entry at head or base,
the gate is a no-op pass — nothing is printed and the ratchet is not
computed. When the env var is unset/empty (the builder's full-repo mode)
every custom entry is checked for a ``_note`` and the ratchet is not
computed (there is no base to compare against).

Output: one location-prefixed finding block per violation plus one
``[custom-widget-ratchet] base=N head=M delta=±K`` report line whenever the
ratchet is computed, and — ALWAYS, on every successful run — one

    [custom-widget-ratchet] findings=N

line. THAT line is the answer, not the exit status.

WHY THE COUNT IS ON STDOUT AND NOT IN THE EXIT CODE (#209)
----------------------------------------------------------
This helper used to return its finding count as its exit status and the gate
read it as the failure count. An exit status is ONE BYTE, and it is also how
the interpreter reports that the helper never finished. Both meanings arrived
on the same channel, so they were not distinguishable:

  * a Python traceback exits 1, and the gate reported it as
    ``FAIL — 1 custom-widget finding(s)``. Measured 2026-08-08 by injecting a
    ``raise`` into ``main()``: the gate produced a plausible, actionable,
    entirely fictional finding, and a reader chasing it would have gone
    looking for a widget that does not exist.
  * the count was clamped to 99 to stay inside the byte, so any run with 100+
    findings under-reported — the same lossy-channel shape that made gate-19
    report 266 findings as 10.

Exit status is now a plain boolean (0 = clean, 1 = findings present). A gate
that sees a non-zero exit WITHOUT a ``findings=`` line knows the helper died
and must report WIRING, never a finding.
"""

import os
import re
import subprocess
import sys
from bisect import bisect_right

BUILTIN_WIDGET_KEYS = {
    "object-table",
    "card-grid",
    "form-renderer",
    "map-viewer",
    "chart",
    "stats-block",
    "wiki-renderer",
}

JUSTIFICATION_MSG = (
    'registry["{key}"] is kind:"widget" without a _note — custom widgets '
    "require a documented justification (ADR-049 built-in-first rule).\n"
    "If this surface is a list/table/stat, declare a built-in "
    "object-table/stats-block widget entry in the manifest instead.\n"
    "If it is a genuine one-off (chat, bespoke analytics canvas), add: "
    '_note: "<why no built-in widget fits>".'
)

RATCHET_MSG = (
    "custom-widget count grew: base={base} head={head} delta={delta} — the "
    "ratchet forbids growth (ADR-049 built-in-first rule).\n"
    "Express the surface as a built-in object-table/stats-block widget entry "
    "in the manifest, or remove an existing custom widget in the same PR."
)

_KIND_WIDGET_RE = re.compile(r"\bkind\s*:\s*(['\"])widget\1")

# The <reason> must be on the same line as the marker — a bare `exclude`
# followed by a newline (i.e. no reason) does not count.
_EXCLUDE_RE = re.compile(r"@custom-widget-ratchet[ \t]+exclude[ \t]+\S+")

# Registry key preceding the entry's `{`, comment-stripped tail match:
#   registry['key'] =   |  registry["key"] =  |  'key':  |  "key":
#   registry.key =      |  key:              |  KEY =
_KEY_TAIL_RE = re.compile(
    r"(?:"
    r"\[\s*'(?P<sqb>[^']*)'\s*\]\s*="
    r"|\[\s*\"(?P<dqb>[^\"]*)\"\s*\]\s*="
    r"|'(?P<sqk>[^']*)'\s*:"
    r"|\"(?P<dqk>[^\"]*)\"\s*:"
    r"|\.(?P<dot>[A-Za-z_$][\w$]*)\s*="
    r"|(?P<bare>[A-Za-z_$][\w$]*)\s*[:=]"
    r")\s*$"
)


# --------------------------------------------------------------------------
# JS/TS/Vue lexical masks — mark which characters are code (not inside a
# string literal or comment) so brace matching and `kind:` detection ignore
# string/comment content. Template-literal interpolation is treated as
# string content (registry entries never rely on it).
# --------------------------------------------------------------------------
def _scan_masks(text):
    """Return (code, comment) boolean lists, one flag per character."""
    n = len(text)
    code = [True] * n
    comment = [False] * n
    i = 0
    while i < n:
        c = text[i]
        nxt = text[i + 1] if i + 1 < n else ""
        if c == "/" and nxt == "/":
            j = i
            while j < n and text[j] != "\n":
                code[j] = False
                comment[j] = True
                j += 1
            i = j
        elif c == "/" and nxt == "*":
            end = text.find("*/", i + 2)
            stop = n if end == -1 else end + 2
            for j in range(i, stop):
                code[j] = False
                comment[j] = True
            i = stop
        elif c in "\"'`":
            quote = c
            code[i] = False
            j = i + 1
            while j < n:
                if text[j] == "\\":
                    code[j] = False
                    if j + 1 < n:
                        code[j + 1] = False
                    j += 2
                    continue
                code[j] = False
                if text[j] == quote:
                    j += 1
                    break
                j += 1
            i = j
        else:
            i += 1
    return code, comment


def _enclosing_open_brace(text, code, pos):
    """Innermost unmatched `{` before pos, honouring the code mask."""
    depth = 0
    j = pos - 1
    while j >= 0:
        if code[j]:
            ch = text[j]
            if ch == "}":
                depth += 1
            elif ch == "{":
                if depth == 0:
                    return j
                depth -= 1
        j -= 1
    return None


def _matching_close_brace(text, code, open_pos):
    depth = 0
    k = open_pos
    n = len(text)
    while k < n:
        if code[k]:
            if text[k] == "{":
                depth += 1
            elif text[k] == "}":
                depth -= 1
                if depth == 0:
                    return k
        k += 1
    return None


def _key_before(text, comment, open_pos):
    """Registry key of the entry whose object literal opens at open_pos."""
    start = max(0, open_pos - 400)
    prefix = "".join(
        " " if comment[i] else text[i] for i in range(start, open_pos)
    )
    m = _KEY_TAIL_RE.search(prefix)
    if not m:
        return None
    for group in ("sqb", "dqb", "sqk", "dqk", "dot", "bare"):
        if m.group(group) is not None:
            return m.group(group)
    return None


def _has_note(span):
    """True when the entry object literally declares a `_note` key."""
    scode, scomment = _scan_masks(span)
    for m in re.finditer(r"(['\"]?)_note\1\s*:", span):
        if scomment[m.start()]:
            continue
        if scode[m.start()] or m.group(1):
            return True
    return False


class Entry:
    """One kind:"widget" registry entry found in a source file."""

    __slots__ = ("key", "line", "end_line", "has_note", "has_exclude")

    def __init__(self, key, line, end_line, has_note, has_exclude):
        self.key = key
        self.line = line
        self.end_line = end_line
        self.has_note = has_note
        self.has_exclude = has_exclude


def parse_entries(text):
    """Extract every kind:"widget" registry entry from JS/TS/Vue source."""
    if "widget" not in text:
        return []
    code, comment = _scan_masks(text)
    line_starts = [0] + [m.end() for m in re.finditer("\n", text)]

    def line_of(pos):
        return bisect_right(line_starts, pos)

    entries = []
    seen_spans = set()
    for m in _KIND_WIDGET_RE.finditer(text):
        if not code[m.start()]:
            continue
        open_pos = _enclosing_open_brace(text, code, m.start())
        if open_pos is None or open_pos in seen_spans:
            continue
        seen_spans.add(open_pos)
        close_pos = _matching_close_brace(text, code, open_pos)
        if close_pos is None:
            continue
        key = _key_before(text, comment, open_pos)
        if key is None:
            continue
        # The documented exception marker may sit inside the entry object or
        # on one of the three lines directly above it.
        ext_start = line_starts[max(0, line_of(open_pos) - 1 - 3)]
        has_exclude = bool(_EXCLUDE_RE.search(text[ext_start:close_pos + 1]))
        entries.append(
            Entry(
                key=key,
                line=line_of(open_pos),
                end_line=line_of(close_pos),
                has_note=_has_note(text[open_pos:close_pos + 1]),
                has_exclude=has_exclude,
            )
        )
    return entries


# --------------------------------------------------------------------------
# Git helpers (same shape as the gate-28 helper).
# --------------------------------------------------------------------------
def _git(args):
    try:
        return subprocess.run(
            ["git", *args], capture_output=True, text=True, check=False
        )
    except (OSError, ValueError):
        return None


def _is_tracked_at(file_path, base_ref):
    proc = _git(["cat-file", "-e", f"{base_ref}:./{file_path}"])
    return proc is not None and proc.returncode == 0


def _base_content(file_path, base_ref):
    """File content at the base ref, or None when absent there."""
    proc = _git(["show", f"{base_ref}:./{file_path}"])
    if proc is None or proc.returncode != 0:
        return None
    return proc.stdout


def _changed_lines(file_path, base_ref):
    """NEW-side line numbers changed vs base_ref (``git diff -U0``), or None
    to signal "all lines in scope" (untracked/new file, or git unavailable —
    fail toward enforcement rather than silently disabling the gate)."""
    proc = _git(["diff", "-U0", "--no-color", base_ref, "--", file_path])
    if proc is None or proc.returncode != 0:
        return None
    changed = set()
    saw_hunk = False
    for line in proc.stdout.splitlines():
        if not line.startswith("@@"):
            continue
        saw_hunk = True
        m = re.search(r"\+(\d+)(?:,(\d+))?", line)
        if not m:
            continue
        start = int(m.group(1))
        count = int(m.group(2)) if m.group(2) is not None else 1
        changed.update(range(start, start + count))
    if not saw_hunk:
        if _is_tracked_at(file_path, base_ref):
            return set()  # tracked + identical → nothing in scope
        return None       # untracked/new → everything in scope
    return changed


def _changed_and_deleted(base_ref, candidate_paths):
    """(changed_head, base_only) file sets vs base_ref, or None on a bad
    base ref. base_only holds deleted paths + rename sources — files whose
    entries exist only on the base side of the ratchet."""
    proc = _git(["diff", "--name-status", "-M", base_ref])
    if proc is None or proc.returncode != 0:
        return None
    changed_head = set()
    base_only = set()

    def _is_candidate_shape(path):
        return path.rsplit(".", 1)[-1] in ("js", "ts", "vue")

    for line in proc.stdout.splitlines():
        parts = line.split("\t")
        if len(parts) < 2:
            continue
        status = parts[0]
        if status.startswith("R") or status.startswith("C"):
            old, new = parts[1], parts[-1]
            if new in candidate_paths:
                changed_head.add(new)
            if status.startswith("R") and _is_candidate_shape(old):
                base_only.add(old)
        elif status == "D":
            if _is_candidate_shape(parts[1]):
                base_only.add(parts[1])
        else:  # A / M / T
            if parts[1] in candidate_paths:
                changed_head.add(parts[1])
    return changed_head, base_only


# --------------------------------------------------------------------------
# Main.
# --------------------------------------------------------------------------
FINDINGS_LINE = "[custom-widget-ratchet] findings={n}"


def _emit(findings, counts_line=None):
    if counts_line is not None:
        print(counts_line)
    for finding in findings:
        print(finding)
    # The count, on stdout, unclamped — see the module docstring (#209).
    # Printed LAST so it cannot be confused with a finding block's own text,
    # and printed on EVERY successful run including the clean one, so its
    # absence means "the helper did not finish".
    print(FINDINGS_LINE.format(n=len(findings)))
    return 1 if findings else 0


def _format_delta(delta):
    return f"+{delta}" if delta > 0 else str(delta)


def main(argv):
    paths = [p for p in argv[1:] if os.path.isfile(p)]
    base_ref = os.environ.get("HYDRA_GATE_BASE_REF", "").strip()

    head_customs = {}
    for path in paths:
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as fh:
                text = fh.read()
        except OSError:
            continue
        entries = [
            e for e in parse_entries(text)
            if e.key not in BUILTIN_WIDGET_KEYS
        ]
        head_customs[path] = entries

    diff_sets = _changed_and_deleted(base_ref, set(paths)) if base_ref else None

    if not base_ref or diff_sets is None:
        # Full-repo mode (builder), or bad/unreachable base ref (fail toward
        # enforcement, like gate-28): every custom entry needs a _note; the
        # ratchet needs a base to compare against and is not computed.
        head_count = sum(len(es) for es in head_customs.values())
        if head_count == 0:
            # Still emit `findings=0` — the caller uses the PRESENCE of that
            # line to tell a clean run from a helper that died (#209).
            return _emit([])
        findings = [
            f"{path}:{e.line}: " + JUSTIFICATION_MSG.format(key=e.key)
            for path, es in sorted(head_customs.items())
            for e in es
            if not e.has_note
        ]
        counts_line = (
            f"[custom-widget-ratchet] head={head_count} "
            "(full-repo mode — no base ref, ratchet not computed)"
        )
        return _emit(findings, counts_line)

    changed_head, base_only = diff_sets

    # Brand-new files invisible to `git diff --name-status` (untracked) are
    # still head-side changes: pull any that declare custom widgets in scope.
    for path, entries in head_customs.items():
        if entries and path not in changed_head \
                and not _is_tracked_at(path, base_ref):
            changed_head.add(path)

    base_customs_cache = {}

    def base_customs(path):
        if path not in base_customs_cache:
            content = _base_content(path, base_ref)
            base_customs_cache[path] = [] if content is None else [
                e for e in parse_entries(content)
                if e.key not in BUILTIN_WIDGET_KEYS
            ]
        return base_customs_cache[path]

    # Activation (ADR-020 no-op pass): only run when a changed file declares
    # a kind:"widget" entry at head or base. Otherwise print nothing and do
    # not compute the ratchet.
    active = any(
        head_customs.get(path) or base_customs(path)
        for path in sorted(changed_head | base_only)
    )
    if not active:
        return _emit([])

    # Per-app counts: unchanged files contribute identically to both sides.
    head_count = sum(len(es) for es in head_customs.values())
    base_count = sum(len(base_customs(p)) for p in sorted(base_only))
    for path in paths:
        if path in changed_head:
            base_count += len(base_customs(path))
        else:
            base_count += len(head_customs.get(path, []))

    # Diff-scope the justification check to ADDED/MODIFIED entries.
    in_scope = []
    for path in sorted(changed_head):
        entries = head_customs.get(path) or []
        if not entries:
            continue
        changed = _changed_lines(path, base_ref)
        for e in entries:
            if changed is None or any(
                ln in changed for ln in range(e.line, e.end_line + 1)
            ):
                in_scope.append((path, e))

    findings = [
        f"{path}:{e.line}: " + JUSTIFICATION_MSG.format(key=e.key)
        for path, e in in_scope
        if not e.has_note
    ]

    # Ratchet: documented exceptions on in-scope entries lift them out of
    # the head count; everything else must hold steady or shrink.
    excluded = sum(1 for _, e in in_scope if e.has_exclude)
    delta = _format_delta(head_count - base_count)
    counts_line = (
        f"[custom-widget-ratchet] base={base_count} "
        f"head={head_count} delta={delta}"
    )
    if excluded:
        counts_line += f" ({excluded} ratchet-excluded)"
    if head_count - excluded > base_count:
        findings.append(
            RATCHET_MSG.format(base=base_count, head=head_count, delta=delta)
        )
    return _emit(findings, counts_line)


if __name__ == "__main__":
    sys.exit(main(sys.argv))
