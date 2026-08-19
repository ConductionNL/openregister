#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Gate 32 helper — detail-page-discipline (ADR-062 rules 1/2/5/8/9).

Enforces the manifest side of the detail-page grid discipline on changed
manifests (``src/manifest.json`` + ``src/manifest.d/*.json``). Observed
2026-07-08 on the flagship procest ``CaseDetail`` evaluation and the 14-app
fleet redesign: KPI ``summaryAggregates`` chips collided with the header,
page-level and ``config.widgets`` render paths shadowed each other, sidebar
tabs referenced the unresolvable ``CnAuditTrailTab`` component, and cards
carried MDI icon names the shared registry cannot render (the "?" fallback).

For every ``type: "detail"`` page the diff TOUCHES:
  a. page-level ``widgets[]`` AND ``config.widgets`` both present (render-path
     shadowing).
  b. ``config.summaryAggregates`` present (deprecated on detail pages, rule 2
     — use an in-grid stats-block).
  c. widgets ↔ layout integrity: every ``config.widgets[].id`` has exactly one
     ``config.layout[]`` entry (matched by ``widgetId``) and vice versa; layout
     cells must not overlap on the 12-col grid.
  d. sidebar: a tab with ``component: "CnAuditTrailTab"`` (does not resolve —
     use ``widgets:[{type:'audit'}]``) or a tab widget ``type: "audit-trail"``
     (the built-in key is ``audit``).
  e. icons (rule 8): a widget ``icon`` not in the shared registry set.
  f. ``viewAllRoute`` / ``rowRoute`` values must match an existing page id in
     the MERGED manifest (base + fragments).

Diff-scoping (ADR-020): only the changed manifest files passed on argv are
inspected, and within them only detail pages the diff TOUCHES are checked
(page object line-span vs ``HYDRA_GATE_BASE_REF``) — legacy debt on an
untouched page never blocks an unrelated PR. Route resolution (f) is checked
against the page-id set of the WHOLE manifest set so a fragment page can link
to a base page.

Note vs gate 30 (effective-manifest-crossref): gate 30 resolves menu[].route
and deepLinks route prefixes against page ids; it does NOT look at widget
``rowRoute`` / ``viewAllRoute``. This gate covers those — no overlap.

Usage:
    check_detail_page_discipline.py <log-path> <manifest.json> [<manifest.json> ...]
"""

import glob
import json
import os
import re
import subprocess
import sys


# --------------------------------------------------------------------------
# The shared icon registry. SOURCE OF TRUTH:
#   nextcloud-vue  src/components/CnWidgetGrid/widgetIcons.js
# An MDI icon name not in this set renders the "?" fallback (ADR-062 rule 8).
# This list is a hardcoded mirror — when the registry changes, replace the
# names below (kept as a single, alphabetically-ordered constant on purpose so
# the swap is a trivial one-line edit).
#
# ⚠️ MIRROR THE OBJECT KEYS, NOT THE IMPORT FILENAMES.
#
# `CnIcon` resolves a name with a plain key lookup:
#
#     _registry[name] || DASHBOARD_ICONS[name] || ... || HelpCircleOutline
#
# so what must appear here is the KEY on the left of each entry in
# `DASHBOARD_ICONS`. Two keys deliberately differ from the file they import,
# because the registry name is the concept and the file is just the glyph
# chosen for it:
#
#     ClipboardList: ClipboardListIcon   <- 'vue-material-design-icons/ClipboardListOutline.vue'
#     Map:           MapIcon             <- 'vue-material-design-icons/MapOutline.vue'
#
# Deriving this set by grepping the import statements therefore produces
# `ClipboardListOutline` / `MapOutline` — names that are NOT registry keys and
# that render the "?" fallback — while dropping the two that work. That mistake
# is what .github#304 reported as a registry drift; the mirror was right about
# `ClipboardList` and the measurement was wrong. Do not "fix" it in that
# direction.
#
# `Map` was the one genuine omission (added by nextcloud-vue's Map dashboard
# widget); without it the gate REJECTED a valid icon.
# --------------------------------------------------------------------------
ICON_REGISTRY = {
    "Account", "AccountBoxOutline", "AccountGroup", "AlertCircleOutline",
    "Bell", "BookOpenVariant", "Briefcase", "Calendar", "Cash", "CashMultiple",
    "ChartBar", "ChartLine", "CheckCircleOutline", "ClipboardCheckOutline",
    "ClipboardList", "Cog", "CurrencyEur", "DatabaseOutline", "Earth", "Email",
    "FileDocument", "FileSign", "FilterVariant", "FolderOutline", "Gauge",
    "Gavel", "HandshakeOutline", "Heart", "History", "Home", "Lightbulb",
    "LinkVariant", "Map", "MapMarker", "MessageTextOutline", "NoteTextOutline",
    "OfficeBuilding", "Package", "Percent", "Phone", "RocketLaunch",
    "ScaleBalance", "School", "ShieldCheckOutline", "Sitemap", "SourceBranch",
    "Star", "TableColumn", "TagOutline", "Timeline", "TrendingDown",
    "TrendingUp", "Trophy", "Truck", "ViewDashboard", "Web",
}


# --------------------------------------------------------------------------
# Position-tracking JSON parse — objects and arrays remember their source
# line span so a page can be mapped back to the diff for page-level scoping.
# --------------------------------------------------------------------------
class _LineDict(dict):
    __slots__ = ("key_lines", "start_line", "end_line")

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.key_lines = {}
        self.start_line = 0
        self.end_line = 0


class _LineList(list):
    __slots__ = ("start_line", "end_line")


_TOKEN_RE = re.compile(
    r"""
      (?P<ws>[ \t\r\n]+)
    | (?P<str>"(?:[^"\\]|\\.)*")
    | (?P<punct>[{}\[\]:,])
    | (?P<lit>true|false|null)
    | (?P<num>-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)
    """,
    re.VERBOSE,
)


def _tokenize(text):
    line = 1
    pos = 0
    length = len(text)
    while pos < length:
        m = _TOKEN_RE.match(text, pos)
        if not m:
            raise ValueError(f"Unexpected character at offset {pos}: {text[pos]!r}")
        kind = m.lastgroup
        value = m.group()
        tok_line = line
        line += value.count("\n")
        pos = m.end()
        if kind == "ws":
            continue
        yield kind, value, tok_line


class _Parser:
    def __init__(self, text):
        self._tokens = list(_tokenize(text))
        self._i = 0

    def _peek(self):
        return self._tokens[self._i] if self._i < len(self._tokens) else (None, None, None)

    def _next(self):
        tok = self._tokens[self._i]
        self._i += 1
        return tok

    def parse(self):
        return self._parse_value()

    def _parse_value(self):
        kind, value, line = self._peek()
        if kind == "punct" and value == "{":
            return self._parse_object()
        if kind == "punct" and value == "[":
            return self._parse_array()
        if kind == "str":
            self._next()
            return json.loads(value)
        if kind == "num":
            self._next()
            return json.loads(value)
        if kind == "lit":
            self._next()
            return {"true": True, "false": False, "null": None}[value]
        raise ValueError(f"Unexpected token {value!r} at line {line}")

    def _parse_object(self):
        obj = _LineDict()
        _, _, start = self._next()  # consume '{'
        obj.start_line = start
        kind, value, endl = self._peek()
        if kind == "punct" and value == "}":
            self._next()
            obj.end_line = endl
            return obj
        while True:
            kkind, kval, kline = self._next()
            if kkind != "str":
                raise ValueError(f"Expected object key, got {kval!r} at line {kline}")
            key = json.loads(kval)
            ckind, cval, cline = self._next()
            if not (ckind == "punct" and cval == ":"):
                raise ValueError(f"Expected ':' at line {cline}")
            obj[key] = self._parse_value()
            obj.key_lines[key] = kline
            nkind, nval, nline = self._next()
            obj.end_line = nline
            if nkind == "punct" and nval == ",":
                continue
            if nkind == "punct" and nval == "}":
                break
            raise ValueError(f"Expected ',' or '}}' at line {nline}")
        return obj

    def _parse_array(self):
        arr = _LineList()
        _, _, start = self._next()  # consume '['
        arr.start_line = start
        kind, value, endl = self._peek()
        if kind == "punct" and value == "]":
            self._next()
            arr.end_line = endl
            return arr
        while True:
            arr.append(self._parse_value())
            nkind, nval, nline = self._next()
            arr.end_line = nline
            if nkind == "punct" and nval == ",":
                continue
            if nkind == "punct" and nval == "]":
                break
            raise ValueError(f"Expected ',' or ']' at line {nline}")
        return arr


# --------------------------------------------------------------------------
# Diff-scope helpers.
# --------------------------------------------------------------------------
def _changed_lines(file_path, base_ref):
    try:
        proc = subprocess.run(
            ["git", "diff", "-U0", "--no-color", base_ref, "--", file_path],
            capture_output=True, text=True, check=False,
        )
    except (OSError, ValueError):
        return None
    if proc.returncode != 0:
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
        for ln in range(start, start + count):
            changed.add(ln)
    if not saw_hunk:
        if _is_tracked_at(file_path, base_ref):
            return set()
        return None
    return changed


def _is_tracked_at(file_path, base_ref):
    try:
        proc = subprocess.run(
            ["git", "cat-file", "-e", f"{base_ref}:{file_path}"],
            capture_output=True, text=True, check=False,
        )
        return proc.returncode == 0
    except (OSError, ValueError):
        return False


# --------------------------------------------------------------------------
# Manifest-set discovery for cross-fragment route resolution.
# --------------------------------------------------------------------------
def _src_dir(path):
    marker = os.sep + "src" + os.sep
    norm = os.path.normpath(path)
    idx = norm.find(marker)
    if idx == -1:
        return os.path.dirname(norm)
    return norm[: idx + len(os.sep + "src")]


def _pages_of(doc):
    if isinstance(doc, dict) and isinstance(doc.get("pages"), list):
        return doc["pages"]
    return []


def _all_page_ids(paths):
    ids = set()
    seen = set()
    for p in paths:
        sd = _src_dir(p)
        if sd in seen:
            continue
        seen.add(sd)
        candidates = [os.path.join(sd, "manifest.json")]
        candidates += glob.glob(os.path.join(sd, "manifest.d", "*.json"))
        for c in candidates:
            try:
                with open(c, encoding="utf-8") as fh:
                    doc = json.load(fh)
            except (OSError, ValueError):
                continue
            for pg in _pages_of(doc):
                if isinstance(pg, dict):
                    if pg.get("id"):
                        ids.add(pg["id"])
                    if pg.get("route"):
                        ids.add(pg["route"])
    return ids


# --------------------------------------------------------------------------
# Recursive collector for a keyed value anywhere under a node.
# --------------------------------------------------------------------------
def _collect(node, key, out):
    if isinstance(node, dict):
        if key in node:
            out.append(node[key])
        for v in node.values():
            _collect(v, key, out)
    elif isinstance(node, list):
        for v in node:
            _collect(v, key, out)


def _overlap(a, b):
    ax, ay, aw, ah = a
    bx, by, bw, bh = b
    return not (ax + aw <= bx or bx + bw <= ax or ay + ah <= by or by + bh <= ay)


# --------------------------------------------------------------------------
# Per-page checks.
# --------------------------------------------------------------------------
def check_page(path, page, page_ids, findings):
    pid = page.get("id", "?")
    cfg = page.get("config") if isinstance(page.get("config"), dict) else {}

    # (a) render-path shadowing.
    if page.get("widgets") and cfg.get("widgets"):
        findings.append(
            f"{path}: page '{pid}' — page-level widgets[] AND config.widgets both "
            f"present (render-path shadowing, ADR-062 rule 1/5)"
        )

    # (b) deprecated summaryAggregates.
    if "summaryAggregates" in cfg:
        findings.append(
            f"{path}: page '{pid}' — config.summaryAggregates is deprecated on "
            f"detail pages (ADR-062 rule 2) — use an in-grid stats-block widget"
        )

    # (c) widgets <-> layout integrity.
    widgets = cfg.get("widgets") if isinstance(cfg.get("widgets"), list) else []
    layout = cfg.get("layout") if isinstance(cfg.get("layout"), list) else []
    if widgets or layout:
        widget_ids = [w.get("id") for w in widgets if isinstance(w, dict) and w.get("id")]
        layout_ids = [
            l.get("widgetId") for l in layout if isinstance(l, dict) and l.get("widgetId")
        ]
        from collections import Counter
        wc = Counter(widget_ids)
        lc = Counter(layout_ids)
        for wid in set(widget_ids):
            n = lc.get(wid, 0)
            if n != 1:
                findings.append(
                    f"{path}: page '{pid}' — widget '{wid}' maps to {n} layout "
                    f"cell(s) (expected exactly 1) (ADR-062 rule 8)"
                )
        for lid in set(layout_ids):
            if wc.get(lid, 0) == 0:
                findings.append(
                    f"{path}: page '{pid}' — layout cell references widgetId "
                    f"'{lid}' with no matching config.widgets[].id"
                )
        # overlap on the 12-col grid.
        cells = []
        for l in layout:
            if not isinstance(l, dict):
                continue
            try:
                cells.append((
                    (int(l["gridX"]), int(l["gridY"]), int(l["gridWidth"]), int(l["gridHeight"])),
                    l.get("widgetId", "?"),
                ))
            except (KeyError, TypeError, ValueError):
                continue
        for i in range(len(cells)):
            for j in range(i + 1, len(cells)):
                if _overlap(cells[i][0], cells[j][0]):
                    findings.append(
                        f"{path}: page '{pid}' — layout cells overlap: "
                        f"'{cells[i][1]}' {cells[i][0]} vs '{cells[j][1]}' {cells[j][0]}"
                    )

    # (d) sidebar tabs.
    sidebar = cfg.get("sidebar") if isinstance(cfg.get("sidebar"), dict) else {}
    for tab in sidebar.get("tabs", []) if isinstance(sidebar.get("tabs"), list) else []:
        if not isinstance(tab, dict):
            continue
        if tab.get("component") == "CnAuditTrailTab":
            findings.append(
                f"{path}: page '{pid}' — sidebar tab component 'CnAuditTrailTab' "
                f"does not resolve; use widgets:[{{type:'audit'}}] (ADR-062 rule 9)"
            )
        for w in tab.get("widgets", []) if isinstance(tab.get("widgets"), list) else []:
            if isinstance(w, dict) and w.get("type") == "audit-trail":
                findings.append(
                    f"{path}: page '{pid}' — sidebar tab widget type 'audit-trail' "
                    f"— the built-in key is 'audit'"
                )

    # (e) icons — widget defs.
    icons = []
    _collect(widgets, "icon", icons)
    for ic in icons:
        if isinstance(ic, str) and ic and ic not in ICON_REGISTRY:
            # THE SYMPTOM IS SILENT, NOT A '?' (.github#304).
            #
            # This message used to say the icon "renders the '?' fallback".
            # It does not. Read from nextcloud-vue
            # src/components/CnWidgetGrid/widgetIcons.js:
            #
            #     export const DEFAULT_ICON = 'ViewDashboard'
            #     export function getIconComponent (name) {
            #         …
            #         return DASHBOARD_ICONS[name] || DASHBOARD_ICONS[DEFAULT_ICON]
            #     }
            #
            # An unknown name resolves to ViewDashboard — verified as
            # DEFAULT_ICON in every shipped build checked (1.0.0-beta.197,
            # 1.0.0-beta.220, 2.2.0-vue3.3, 2.2.0-vue3.6). A '?' would at least
            # look broken; a generic dashboard glyph looks deliberate, so the
            # author's intent is lost INVISIBLY. That is worse to triage, and
            # saying it accurately is the difference between "someone will
            # notice this in review" and "nobody ever will".
            findings.append(
                f"{path}: page '{pid}' — widget icon '{ic}' is not in the shared "
                f"icon registry, so it silently renders the DEFAULT icon "
                f"('ViewDashboard') instead — the intent is lost with no visible "
                f"breakage (ADR-062 rule 8). NOTE: this checks the nextcloud-vue "
                f"widget vocabulary only; the app's own src/icons.js registration "
                f"is gate-60's subject, and an icon must be in BOTH to render"
            )

    # (f) viewAllRoute / rowRoute must resolve to a page id.
    for key in ("viewAllRoute", "rowRoute"):
        routes = []
        _collect(cfg, key, routes)
        for r in routes:
            if isinstance(r, str) and r and r not in page_ids:
                findings.append(
                    f"{path}: page '{pid}' — {key} '{r}' does not resolve to an "
                    f"existing page id in the merged manifest"
                )

    # (f2) The OBJECT route form: `"route": {"name": "<pageId>", "query": {...}}`,
    # used by stats-block entries[] to deep-link a KPI. The renderer calls
    # `router.resolve({name, query})` inside a COMPUTED and reads `.href` off the
    # result, so an unresolvable name throws and the page emits console errors on
    # every mount — it does not degrade to a dead link.
    #
    # This form is invisible to (f), which only inspects string values. Observed
    # 2026-08-03 on openconnector: deleting the EventDeliveries page during the
    # ADR-080 dead-letter merge left two ConsumerDetail stats entries pointing at
    # it. A string-only scan reported "no unresolvable route refs" while the E2E
    # suite failed on ConsumerDetail — the check and the symptom disagreed, and
    # the check was wrong.
    objroutes = []
    _collect(cfg, "route", objroutes)
    for r in objroutes:
        if isinstance(r, dict):
            name = r.get("name")
            if isinstance(name, str) and name and name not in page_ids:
                findings.append(
                    f"{path}: page '{pid}' — route object {{name: '{name}'}} does not "
                    f"resolve to an existing page id in the merged manifest "
                    f"(router.resolve() throws inside a computed, so the page emits "
                    f"console errors on mount)"
                )


def check_file(path, page_ids, findings, base_ref):
    try:
        with open(path, encoding="utf-8") as fh:
            text = fh.read()
        doc = _Parser(text).parse()
    except (OSError, ValueError) as exc:
        findings.append(f"{path}: PARSE ERROR — {exc}")
        return

    changed = _changed_lines(path, base_ref) if base_ref else None
    for page in _pages_of(doc):
        if not isinstance(page, dict) or page.get("type") != "detail":
            continue
        if changed is not None:
            span = range(getattr(page, "start_line", 0), getattr(page, "end_line", 0) + 1)
            if not any(ln in changed for ln in span):
                continue  # page untouched by the diff (ADR-020)
        check_page(path, page, page_ids, findings)


_USAGE = (
    "usage: check_detail_page_discipline.py <logfile> <manifest.json>...\n"
    "\n"
    "This helper writes its findings to <logfile> and ALWAYS exits 0 when it\n"
    "runs — the runner decides FAIL by COUNTING LOG LINES, so the exit status\n"
    "is not the verdict and a clean exit proves nothing about the result.\n"
    "To read a verdict, read the log.\n"
    "\n"
    "It takes no options. `--app-dir` in particular is NOT this helper's\n"
    "interface (it is check_visual_coverage.py's): passing it used to make\n"
    "argv[1] the literal string '--app-dir', inspect nothing, print nothing\n"
    "and exit 0 — a perfect silent false green over a tree with real findings.\n"
    "See .github#304.\n"
)


def main(argv):
    # A MALFORMED INVOCATION MUST NOT LOOK CLEAN (.github#304).
    #
    # This used to be `if len(argv) < 3: return 0`, so every wrong call — a
    # missing log path, an option this helper does not take — produced empty
    # output and success. Measured:
    #
    #   check_detail_page_discipline.py --app-dir .
    #     EXIT=0  stdout: 0 lines  stderr: 0 bytes   <- inspected NOTHING
    #
    #   check_detail_page_discipline.py <log> <24 manifests>   (the real contract)
    #     EXIT=0  stderr: 0 bytes  findings written to the log: 28
    #
    # Two indistinguishable clean exits, one of which had opened no file at
    # all. The runner reads `_dpd_rc`, so returning non-zero here surfaces as
    # `SKIPPED (wiring)` — "no verdict was produced" — instead of PASS.
    if len(argv) < 3 or any(a.startswith("-") for a in argv[1:]):
        sys.stderr.write(_USAGE)
        return 2
    log_path = argv[1]
    paths = argv[2:]
    base_ref = os.environ.get("HYDRA_GATE_BASE_REF", "").strip()
    page_ids = _all_page_ids(paths)
    findings = []
    for p in paths:
        check_file(p, page_ids, findings, base_ref)
    with open(log_path, "a", encoding="utf-8") as g:
        for msg in findings:
            g.write(msg + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
