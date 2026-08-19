#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Gate-53 helper — compute the ADR-020 diff scope of a manifest change.

WHY THIS EXISTS (measured 2026-08-03)
-------------------------------------
gate-53 (effective-manifest-crossref) was diff-scoped at FILE granularity: if a
PR touched ``src/manifest.json``, any ``src/manifest.d/*.json`` fragment, or
``src/menu-layout.json``, it re-judged the ENTIRE assembled manifest. Because an
app's whole navigation surface lives in that one input set, file granularity is
indistinguishable from no scoping at all. Measured:

    pipelinq  one-line ``title`` change on one index page
              → full-repo 24 findings, diff-scoped 24 findings
    shillinq  one-line ``title`` change in ONE of 80 fragments
              → full-repo 246 findings, diff-scoped 246 findings

Every one of those findings sat on a page the PR had never touched. Enabling the
gate would have blocked every manifest-touching PR in those repos on inherited
debt, however small the change — which is how a gate gets switched off.

The cross-reference joins themselves genuinely need the WHOLE assembled manifest
to be ANSWERED (you cannot resolve ``menu[].route`` → page id from a diff). That
is not the same as needing the whole manifest to be BLOCKING. This helper draws
the second line: the answer is computed repo-wide, and the finding blocks only
when the PR touched the entry the finding is ABOUT. It is the same model gate-55
(detail-page-discipline) already uses, and gate-55 measurably scopes correctly.

WHAT IT EMITS
-------------
One token per line on stdout, for consumption by
``manifest_scope_filter.js``::

    page:<pageId>       a pages[] entry whose JSON object span intersects the diff
    menu:<menuId>       a menu[] entry (or nested child) whose span intersects
    key:<topLevelKey>   a non-pages/non-menu top-level key whose span intersects
                        (e.g. ``key:observability``, ``key:deepLinks``)
    ALLMENU             menu-layout.json changed — relocations/removals restructure
                        the merged menu wholesale, so every /menu finding is in scope
    ALL                 scope could not be determined; EVERYTHING blocks

``ALL`` is emitted whenever the answer is unknowable rather than empty — a file
untracked at base (brand-new manifest/fragment), a JSON parse failure, a git
invocation that fails, or a changed register JSON (a register edit can orphan a
schema reference anywhere in the manifest). Fail TOWARD enforcement: an
unverifiable scope must never be reported as a narrow one. That is the same
rule the runner applies to an unresolvable base ref.

Usage::

    HYDRA_GATE_BASE_REF=origin/development \\
        manifest_diff_scope.py <changed-file> [<changed-file> ...]

Only paths that are manifest INPUTS are interpreted; anything else is ignored.
With no base ref set the helper prints ``ALL`` (a full-repo run scopes nothing).
"""

import importlib.util
import os
import sys

_HERE = os.path.dirname(os.path.abspath(__file__))


def _load_line_parser():
    """Borrow the line-tracking JSON parser from check_detail_page_discipline.

    That module already carries a tokenizer that records the start/end line of
    every object and array — the machinery gate-55 uses for its page-span
    scoping. Importing it keeps ONE parser in the package: a second copy would
    be a second thing to keep in sync, and a scope computation that disagrees
    with gate-55's about where a page begins is worse than no scoping at all.
    """
    path = os.path.join(_HERE, "check_detail_page_discipline.py")
    spec = importlib.util.spec_from_file_location("_hydra_dpd", path)
    if spec is None or spec.loader is None:
        return None
    mod = importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(mod)
    except Exception:  # noqa: BLE001 — any import failure means "cannot scope"
        return None
    return mod


_DPD = _load_line_parser()


def _is_manifest_page_input(rel):
    return rel == "src/manifest.json" or rel.startswith("src/manifest.d/")


def _spans_of(node):
    """(start_line, end_line) for a parsed node, or None when untracked."""
    start = getattr(node, "start_line", 0)
    end = getattr(node, "end_line", 0)
    if not start or not end:
        return None
    return (start, end)


def _intersects(span, changed):
    if span is None:
        return True  # unknown span → assume touched (fail toward enforcement)
    lo, hi = span
    for ln in changed:
        if lo <= ln <= hi:
            return True
    return False


def _collect_menu_ids(entry, changed, out):
    """Emit menu ids for `entry` and any nested children the diff touches.

    A parent whose own span is touched puts the parent in scope; a touched CHILD
    also puts the child in scope on its own, so editing one leaf of a 30-item
    menu tree does not drag the whole tree in.
    """
    if not isinstance(entry, dict):
        return
    span = _spans_of(entry)
    ident = entry.get("id") or entry.get("route")
    children = entry.get("children")
    child_hit = False
    if isinstance(children, list):
        for child in children:
            before = len(out)
            _collect_menu_ids(child, changed, out)
            if len(out) > before:
                child_hit = True
    if isinstance(ident, str) and ident and (child_hit or _intersects(span, changed)):
        out.add("menu:" + ident)


def _scope_one_file(path, base_ref, tokens):
    """Add tokens for one changed manifest input. Returns False on 'cannot scope'."""
    if _DPD is None:
        return False
    changed = _DPD._changed_lines(path, base_ref)
    if changed is None:
        # Untracked at base (new file) / git unavailable / bad base — unknowable.
        return False
    if not changed:
        return True  # tracked and byte-identical: contributes nothing
    try:
        with open(path, "r", encoding="utf-8") as fh:
            doc = _DPD._Parser(fh.read()).parse()
    except (OSError, ValueError):
        return False
    if not isinstance(doc, dict):
        return False

    for key, value in doc.items():
        if key == "pages" and isinstance(value, list):
            for page in value:
                if not isinstance(page, dict):
                    continue
                pid = page.get("id")
                if isinstance(pid, str) and pid and _intersects(_spans_of(page), changed):
                    tokens.add("page:" + pid)
        elif key == "menu" and isinstance(value, list):
            for entry in value:
                _collect_menu_ids(entry, changed, tokens)
        else:
            span = None
            if hasattr(value, "start_line"):
                span = _spans_of(value)
            elif key in doc.key_lines:
                line = doc.key_lines[key]
                span = (line, line)
            if _intersects(span, changed):
                tokens.add("key:" + key)
    return True


def main(argv):
    base_ref = os.environ.get("HYDRA_GATE_BASE_REF", "").strip()
    if not base_ref:
        print("ALL")
        return 0

    tokens = set()
    scoped_anything = False
    for raw in argv[1:]:
        rel = raw.lstrip("./")
        if rel == "src/menu-layout.json":
            # Relocations and removals rewrite the merged menu wholesale — a
            # single removal can orphan a route declared in a different file.
            # Do not pretend a per-entry answer exists.
            tokens.add("ALLMENU")
            scoped_anything = True
            continue
        if "register" in rel and rel.endswith(".json") and rel.startswith("lib/"):
            # A register edit can invalidate a register/schema slug referenced
            # from ANY page. There is no per-page answer to be had.
            print("ALL")
            return 0
        if not _is_manifest_page_input(rel):
            continue
        if not _scope_one_file(rel, base_ref, tokens):
            print("ALL")
            return 0
        scoped_anything = True

    if not scoped_anything:
        # No manifest input in the changed set at all. The caller only invokes
        # us when one WAS touched, so reaching here means our idea of "manifest
        # input" disagrees with the caller's — do not narrow on a disagreement.
        print("ALL")
        return 0

    for token in sorted(tokens):
        print(token)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
