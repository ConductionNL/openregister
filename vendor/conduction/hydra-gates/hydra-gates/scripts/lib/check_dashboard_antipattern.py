#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-15 dashboard-antipattern — static-grep detector for nested dashboards.

Closes hydra#316. Observed 2026-05-23 on pipelinq where the Dashboard page
rendered with three nested "Dashboard" h2/h3/h2 headings because the page's
host component (`Dashboard.vue`) rendered ``<CnDashboardPage>``, but the
manifest also registered the same page as a widget surface where each
``#widget-<id>`` slot template again rendered ``<CnDashboardPage>`` —
producing dashboard-in-widget-in-dashboard nesting that's mechanically
detectable from the source files.

The check
=========

For each app whose ``src/manifest.json`` declares one or more pages with
``type: "dashboard"``:

1. Build the set of custom-widget ids declared on dashboard pages
   (``pages[].config.widgets[] | select(.type == "custom") | .id``).
2. Walk every ``.vue`` file under ``src/`` (excluding ``src/modals/`` /
   ``src/dialogs/`` — modals own their own page wrappers).
3. For every ``<template #widget-<id>>`` (or ``#widget-<id>="..."``)
   slot template whose ``<id>`` is in the custom-widget set, slice out
   the slot body (the markup between the opening
   ``<template #widget-<id>...>`` and the matching closing
   ``</template>``) and look for any ``<CnDashboardPage`` tag.
4. Every match is reported as one anti-pattern: dashboard-in-widget-of-
   dashboard. The fix is to render a non-dashboard component (e.g. a
   plain ``CnStatsBlock`` or ``CnIndexPage`` body) inside the slot.

Also catches the simpler shape: any page with ``type: "custom"`` whose
``component`` field resolves to a ``.vue`` file that uses
``<CnDashboardPage>``, **when that same page id is referenced as a custom
widget on another dashboard page**. (Pipelinq's exact bug.)

Print format mirrors the existing gates so ``run-hydra-gates.sh`` can
consume it unchanged::

    <file>:<line> widget=<id> rule=dashboard-in-dashboard-slot
    <file>:<line> page=<id> rule=dashboard-component-used-as-widget

Usage::

    python3 scripts/lib/check_dashboard_antipattern.py [app-dir]

Exit code is the number of violations printed; 0 when clean. Designed to
finish in <2s on the largest app (procest's manifest has ~12 dashboard
widgets, ~80 .vue files).
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

# Match an opening <template #widget-<id>> or <template #widget-<id>="...">
# or the long-form <template v-slot:widget-<id>>. Group 1 captures the id.
# THE TAG ENDS AT A `>`, NOT AT THE END OF THE SLOT BINDING (.github#271/#274).
#
# This used to be `(?:="[^"]*")?\s*>` — the slot name, an OPTIONAL double-quoted
# binding, then immediately `>`. Anything else on the tag made the whole element
# invisible:
#
#     <template #widget-kpis>                        matched
#     <template #widget-kpis="{ item }">             matched
#     <template #widget-kpis class="x">              NOT matched
#     <template v-slot:widget-kpis="{ i }" class="x"> NOT matched
#     <template #widget-kpis='a'>                    NOT matched  (single quotes)
#
# Same shape as gate-44's "judged on the FIRST of name/id/v-model and stopped"
# (#272): a matcher that assumes an attribute is the LAST thing on the tag has a
# blind spot shaped like attribute order, and one extra class attribute is
# enough to hide the finding. The element now ends at a `>` that is not inside a
# quoted attribute value — the same rule gate-12's helper and check_form_labels
# already use.
SLOT_OPEN_RE = re.compile(
    r"<template\s+(?:#|v-slot:)widget-([A-Za-z][A-Za-z0-9_\-]*)"
    r"(?:[^>\"']|\"[^\"]*\"|'[^']*')*>",
)
TEMPLATE_CLOSE_RE = re.compile(r"</template\s*>")
TEMPLATE_OPEN_RE = re.compile(r"<template[\s>]")
DASHBOARD_TAG_RE = re.compile(r"<CnDashboardPage[\s>/]")


def _load_manifest(app_dir: Path) -> dict | None:
    manifest = app_dir / "src" / "manifest.json"
    if not manifest.is_file():
        return None
    try:
        return json.loads(manifest.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None


def _custom_widget_ids(manifest: dict) -> set[str]:
    """Return the set of widget ids declared as type=custom on dashboard pages."""
    ids: set[str] = set()
    for page in manifest.get("pages") or []:
        if not isinstance(page, dict):
            continue
        if page.get("type") != "dashboard":
            continue
        config = page.get("config") or {}
        for widget in config.get("widgets") or []:
            if not isinstance(widget, dict):
                continue
            if widget.get("type") == "custom" and isinstance(widget.get("id"), str):
                ids.add(widget["id"])
    return ids


def _custom_page_components(manifest: dict) -> dict[str, str]:
    """Map manifest page id -> component name for type=custom pages."""
    result: dict[str, str] = {}
    for page in manifest.get("pages") or []:
        if not isinstance(page, dict):
            continue
        if page.get("type") != "custom":
            continue
        component = page.get("component")
        page_id = page.get("id")
        if isinstance(component, str) and isinstance(page_id, str):
            result[page_id] = component
    return result


def _widget_refs_to_custom_pages(manifest: dict) -> set[str]:
    """Return page ids referenced as widget bodies anywhere in the manifest.

    A page is "referenced as a widget" when another dashboard widget's
    ``component`` or ``ref`` field names it, or when a sidebar tab's
    ``component`` field names it. We don't have full app-context — the
    page id is the most reliable signal.
    """
    refs: set[str] = set()
    custom_components = set(_custom_page_components(manifest).values())

    def walk(node: object) -> None:
        if isinstance(node, dict):
            for key in ("component", "ref"):
                val = node.get(key)
                if isinstance(val, str) and val in custom_components:
                    refs.add(val)
            for v in node.values():
                walk(v)
        elif isinstance(node, list):
            for v in node:
                walk(v)

    # Walk each page WITHOUT its own top-level `component` field — a
    # page naming its own component is a definition, not a "referenced
    # as widget elsewhere" signal. Counting it made every type=custom
    # page that renders <CnDashboardPage> self-flag (false positive
    # observed on pipelinq's Rapportage page, 2026-06-12).
    for page in manifest.get("pages", []):
        if isinstance(page, dict):
            page_without_self = {k: v for k, v in page.items() if k != "component"}
            walk(page_without_self)
        else:
            walk(page)
    walk({k: v for k, v in manifest.items() if k != "pages"})
    return refs


def _slice_slot_body(text: str, start: int) -> tuple[int, str]:
    """Given the position of the opening <template #widget-x>, return
    (end_offset, slot_body_text). Handles nested <template> elements via a
    simple depth counter."""
    pos = start
    depth = 1
    n = len(text)
    while pos < n and depth > 0:
        next_open = TEMPLATE_OPEN_RE.search(text, pos)
        next_close = TEMPLATE_CLOSE_RE.search(text, pos)
        if next_close is None:
            return n, text[start:n]
        if next_open is not None and next_open.start() < next_close.start():
            depth += 1
            pos = next_open.end()
        else:
            depth -= 1
            pos = next_close.end()
    return pos, text[start:pos]


def check_file(
    vue_path: Path,
    custom_widget_ids: set[str],
    findings: list[str],
) -> None:
    """Append a finding line for every <template #widget-X> whose body
    contains <CnDashboardPage>."""
    try:
        text = vue_path.read_text(encoding="utf-8")
    except OSError:
        return

    for match in SLOT_OPEN_RE.finditer(text):
        widget_id = match.group(1)
        if widget_id not in custom_widget_ids:
            continue
        body_start = match.end()
        _, body = _slice_slot_body(text, body_start)
        if DASHBOARD_TAG_RE.search(body):
            line_no = text.count("\n", 0, match.start()) + 1
            findings.append(
                f"{vue_path}:{line_no} widget={widget_id} "
                f"rule=dashboard-in-dashboard-slot"
            )


def check_custom_pages_as_widgets(
    app_dir: Path,
    custom_pages: dict[str, str],
    widget_refs: set[str],
    findings: list[str],
) -> None:
    """For each type=custom page whose component is *also* referenced as
    a widget body elsewhere, check whether that component's .vue file
    uses <CnDashboardPage>. If so, it's the pipelinq pattern."""
    src = app_dir / "src"
    for page_id, component_name in custom_pages.items():
        if component_name not in widget_refs:
            continue
        # Find the component .vue file (best-effort: search src/ for
        # <ComponentName>.vue).
        candidates = list(src.rglob(f"{component_name}.vue"))
        for candidate in candidates:
            try:
                text = candidate.read_text(encoding="utf-8")
            except OSError:
                continue
            match = DASHBOARD_TAG_RE.search(text)
            if match is None:
                continue
            line_no = text.count("\n", 0, match.start()) + 1
            findings.append(
                f"{candidate}:{line_no} page={page_id} component={component_name} "
                f"rule=dashboard-component-used-as-widget"
            )


def main(argv: list[str]) -> int:
    app_dir = Path(argv[1] if len(argv) > 1 else ".").resolve()
    manifest = _load_manifest(app_dir)
    if manifest is None:
        # No manifest = nothing to check (gate is silently inert on apps
        # that haven't adopted ADR-024 yet).
        return 0

    findings: list[str] = []
    custom_widget_ids = _custom_widget_ids(manifest)
    if custom_widget_ids:
        src = app_dir / "src"
        if src.is_dir():
            for vue in src.rglob("*.vue"):
                # Skip modals + dialogs — they own their own page wrappers and
                # don't render dashboards as nested slot content.
                rel_parts = vue.relative_to(src).parts
                if rel_parts and rel_parts[0] in {"modals", "dialogs"}:
                    continue
                check_file(vue, custom_widget_ids, findings)

    custom_pages = _custom_page_components(manifest)
    widget_refs = _widget_refs_to_custom_pages(manifest)
    if custom_pages and widget_refs:
        check_custom_pages_as_widgets(app_dir, custom_pages, widget_refs, findings)

    for line in findings:
        print(line)
    # TERMINAL MARKER (.github#271). The runner used to decide gate-15 with
    # `wc -l` over this helper's stdout after `2>/dev/null || true`. A helper
    # that CRASHED wrote nothing, so the log was empty, so the count was 0, so
    # the gate reported PASS over a check that never executed — the exact shape
    # #147/#233/#245 exist to remove, still live in three gates on 2026-08-08.
    # Demonstrated by running the suite with a python3 that always exits 1:
    # gates 12 and 17 said SKIPPED (wiring); gates 15, 16 and 18 said PASS.
    #
    # The marker is what makes "the checker finished" observable. Its absence
    # is now a wiring skip, never a pass. The exit code is a STATUS (0 clean /
    # 1 findings) and no longer the count — a count in one byte is #209.
    print(f"# count={len(findings)}")
    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
