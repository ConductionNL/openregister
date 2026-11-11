#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""gate-122 helper: the SIG maintainability metrics, as a ratchet and as a report.

The Software Improvement Group (SIG, platform Sigrid) rates maintainability per
language from risk profiles: the share of code that sits in units (methods and
functions) above a size, complexity or parameter bound, plus duplication and a
few architecture properties. The 4-star limits come from the SIG/TUV NORD CERT
"Guidance for Producers" v18.1 (the 2026 model). hydra's phpmd runs at its own defaults with
baselines, so a green phpmd is not a green SIG profile.

TWO MODES
---------
ratchet   (the gate, on a delta base)
    For every unit the diff ADDS or CHANGES (a unit whose line range holds an
    added line), a finding when
        NLOC > 60, McCabe > 10, or 5 or more parameters
    and for every duplicated fragment of 6 or more lines the diff INTRODUCES
    (one occurrence of the clone has at least 6 added lines).

    A finding is NEW when the unit is new, or when the change pushed it over a
    bound or made an already-over unit worse. A changed unit that was already
    over the bound at the base and did not get worse is INHERITED, and so is
    every finding on a unit the diff did not touch. Only NEW findings count.

report    (nightly, or a full-scope run with no base)
    The whole risk profile of one app, per language, against the 4-star limits:
    unit size, unit complexity, unit interfacing, duplication, and for PHP and
    Python module coupling, component independence and an entanglement
    approximation. Written as JSON and as a short markdown table.

PROFILES (report mode)
----------------------
four-star   the default: the 4-star limits of the Guidance for Producers
            v18.1: unit size 48.0/24.2/9.1%, complexity 20.7/7.5/1.1%,
            parameters 15.4/3.4/0.9%, module coupling 9.7/5.3/1.8%,
            duplication 5.8%, component independence 94.5%, entanglement
            0.075. Volume is no longer rated.
five-star   SIG publishes no 5-star limits. The 2011 benchmark table of Alves,
            Correia and Visser gives the distance between star bands; its
            ratios applied to the v18.1 limits give unit size 36.0/17.0/5.5%,
            complexity 15.8/4.4/0.5%, parameters 12.5/2.5/0.6%, module
            coupling 7.4/3.3/1.2%, duplication 3.5%. Independence 97.3% and
            entanglement 0.0375 halve the 4-star exposure.

Every row of a report also carries `stars`, the level its share earns on the
same derived scale (STAR_BANDS): 5 to 2 stars, and 1 star for anything worse
than 2. Component independence and entanglement have no band under 4 stars
and read "<4" there.
Chosen with --profile, or HYDRA_GATE_SIG_PROFILE when the flag is absent.

RATING ESTIMATE (report mode)
-----------------------------
`rating` in the JSON, and a paragraph in the markdown: an estimate of SIG's
continuous rating (0.5 to 5.5) per check, property, sub-characteristic and
overall, and the levers that raise the overall rating most per 1,000 lines
fixed. SIG publishes no power-mean exponent, so the overall rating is a range
over p = 1 and p = -1. The method and its assumptions are listed above
rating_estimate() and in research/sig-stars.md section 4. The profile does
not change the estimate.
The profile changes only the limits a report is compared with; the ratchet's
unit bounds and the gate's advisory status do not change.

WHAT IS MEASURED
----------------
Tracked files with a source extension (php, js/mjs/cjs/jsx, ts/tsx, vue, py),
minus Sigrid's own default excludes (vendored at the bottom of this file, from
github.com/Software-Improvement-Group/sigridci resources/default-excludes.txt),
minus test code (SIG rates test code separately), minus the Nextcloud build
output directory js/ and l10n/. A `sigrid.yaml` (or .yml) at the repository
root adds its top-level `exclude:` patterns, so the gate scopes the code the
way SIG will once the repository carries a scope file.

TOOLS, PINNED
-------------
lizard 1.24.3 (units: NLOC, McCabe, parameters) and jscpd 4.0.5 (the
ratchet's clones). The report's duplication follows SIG's line definition
directly (windows of 6 code lines equal modulo whitespace) and shows jscpd's
figure next to it as a cross-check. In a Vue file the windows stay inside one
top-level block: template, script and style are separate pieces, and the tag
lines belong to none. That is our assumption until a real Sigrid scan confirms
it, and every report with Vue files says so. jscpd's figure does not apply it.
A different version produces different numbers, so the pinned version is
loaded or installed into the tool cache (HYDRA_GATE_TOOLS_DIR, default
~/.cache/hydra-gates/tools) and any other version is refused. Set
HYDRA_GATE_SIG_NO_INSTALL=1 to forbid the install.

Four lizard traps are handled here:
  * PHP parameter counts: lizard's PHP reader keeps only the last parameter in
    `parameters`. The count is the number of unique `$names` in `long_name`.
  * Vue files: only the <script> blocks are read, with the file's own line
    numbers. lizard's Vue reader tokenises the template too, so a `=>` in a
    template handler or a quote in an HTML comment hid every unit of a file.
  * `get()` / `set()` method shorthand made lizard's JavaScript and TypeScript
    readers drop every unit of the file; the name is aliased while lizard reads.
  * Crashes: lizard raises on some files (a Vue component in openconnector).
    Each file is analysed on its own with a time limit, and a file lizard
    cannot read is listed as skipped, never silently dropped.

Python methods do not count `self` or `cls` as a parameter.

Usage:
    check_sig_maintainability.py ratchet <app-dir> --base-ref REF
    check_sig_maintainability.py report  <app-dir> [--json FILE] [--md FILE]
                                         [--profile four-star|five-star]

Output ends in one of
    [gate-122] sig-maintainability: checked U changed unit(s) in F file(s), ...
    [gate-122] sig-maintainability: FAIL: N finding(s) ...
    [gate-122] sig-maintainability: report: ...
Exit status: 0 clean (or a report), 1 findings, 2 an unknown profile,
3 the base does not resolve, 4 nothing to judge, 5 a pinned tool is unavailable. A traceback is exit 1 with
no summary line, which the runner reports as WIRING, never as a finding.
"""
import argparse
import collections
import json
import os
import re
import shutil
import signal
import subprocess
import sys
import tempfile

GATE = "[gate-122] sig-maintainability"
LIZARD_VERSION = "1.24.3"
# Raised whenever UnitReader reads the same source into different units, so
# a unit cache written by an older reader is not reused.
UNIT_READER_VERSION = 2
# The languages whose unit checks (size, complexity, interfacing) each reader
# version measures differently from the version before it.
UNIT_READER_RE_MEASURED = {
    2: ("vue", "javascript", "typescript"),  # Vue <script> extraction, get/set shorthand
}
JSCPD_VERSION = "4.0.5"

LANG_OF_EXT = {
    ".php": "php",
    ".js": "javascript", ".mjs": "javascript", ".cjs": "javascript", ".jsx": "javascript",
    ".ts": "typescript", ".tsx": "typescript",
    ".vue": "vue",
    ".py": "python",
}

# Ratchet bounds: a changed unit is a finding ABOVE these.
RATCHET = {"nloc": 60, "ccn": 10, "params": 4}
RATCHET_LABEL = {"nloc": "NLOC > 60", "ccn": "McCabe > 10", "params": "5 or more parameters"}
DUP_MIN_LINES = 6
VUE_PIECES_ASSUMPTION = ("Vue duplication treats template, script and style as separate pieces, so no 6-line "
                         "window crosses a block boundary. This is our assumption until a real Sigrid scan confirms it.")

# Star bands, per language: for each level, the max share of unit LOC strictly
# above each bound. 4 stars is the SIG/TUV NORD CERT Guidance for Producers
# v18.1 (31 March 2026, the 2026 model). SIG publishes no other level, so 5, 3
# and 2 stars apply the band-to-4-star ratios of Alves, Correia and Visser 2011
# (Table III) to the v18.1 limits, and duplication the Heitlager 2007 band
# ratios. Worse than 2 stars is 1 star. Volume is not rated since v18.1.
BOUNDS = {
    "unit_size": (15, 30, 60),
    "unit_complexity": (5, 10, 25),
    "unit_interfacing": (2, 4, 6),  # 3+, 5+, 7+ parameters
    "module_coupling": (10, 20, 50),
}
STAR_BANDS = {
    "unit_size": {5: (.360, .170, .055), 4: (.480, .242, .091), 3: (.630, .347, .154), 2: (.847, .490, .253)},
    "unit_complexity": {5: (.158, .044, .005), 4: (.207, .075, .011), 3: (.277, .106, .017), 2: (.346, .132, .027)},
    "unit_interfacing": {5: (.125, .025, .006), 4: (.154, .034, .009), 3: (.183, .048, .014), 2: (.260, .072, .021)},
    "module_coupling": {5: (.074, .033, .012), 4: (.097, .053, .018), 3: (.107, .059, .023), 2: (.130, .080, .038)},
    "duplication": {5: .035, 4: .058, 3: .116, 2: .230},
}
# Component independence (a minimum) and entanglement have a published 4-star
# limit only. 5 stars halves the exposed share (1 - independence) and the
# entanglement limit; below 4 stars no level is estimated, shown as "<4".
INDEPENDENCE_BANDS = {5: .973, 4: .945}
ENTANGLEMENT_BANDS = {5: .0375, 4: .075}
BELOW_FOUR = "<4"


def band_limits(level):
    """{property: [(bound, cap), ...]} of one star level."""
    return {prop: list(zip(BOUNDS[prop], STAR_BANDS[prop][level])) for prop in BOUNDS}


def stars_of(kind, share, index=None):
    """The star level (5 to 1) one measured share earns. Independence and
    entanglement have no level under 4, so they return 5, 4 or "<4"."""
    if kind == "component_independence":
        return next((lvl for lvl in (5, 4) if share >= INDEPENDENCE_BANDS[lvl]), BELOW_FOUR)
    if kind == "component_entanglement":
        return next((lvl for lvl in (5, 4) if share <= ENTANGLEMENT_BANDS[lvl]), BELOW_FOUR)
    bands = STAR_BANDS[kind]
    caps = {lvl: bands[lvl] if index is None else bands[lvl][index] for lvl in bands}
    return next((lvl for lvl in (5, 4, 3, 2) if share <= caps[lvl]), 1)


LIMITS = band_limits(4)
DUPLICATION_LIMIT = STAR_BANDS["duplication"][4]
INDEPENDENCE_MIN = INDEPENDENCE_BANDS[4]
ENTANGLEMENT_LIMIT = ENTANGLEMENT_BANDS[4]
GUIDANCE = "SIG/TUV NORD CERT Guidance for Producers v18.1"


PROFILES = {
    "four-star": {
        "stars": "4-star",
        "source": f"{GUIDANCE}, 4 stars",
        "limits": LIMITS,
        "duplication": DUPLICATION_LIMIT,
        "independence_min": INDEPENDENCE_MIN,
        "entanglement": ENTANGLEMENT_LIMIT,
    },
    "five-star": {
        "stars": "5-star",
        "source": f"5-star limits derived from the {GUIDANCE} 4-star limits with the band ratios of "
                  "Alves, Correia and Visser 2011; independence and entanglement at half the exposed share "
                  "(SIG publishes no 5-star limits)",
        "limits": band_limits(5),
        "duplication": STAR_BANDS["duplication"][5],
        "independence_min": INDEPENDENCE_BANDS[5],
        "entanglement": ENTANGLEMENT_BANDS[5],
    },
}
DEFAULT_PROFILE = "four-star"

TEST_PATTERNS = [
    r".*/tests?/.*", r".*/__tests__/.*", r".*/__mocks__/.*", r".*/e2e/.*",
    r".*[.](spec|test)[.](js|mjs|cjs|jsx|ts|tsx)",
    r".*/test_[^/]*[.]py", r".*/[^/]*_test[.]py", r".*/conftest[.]py",
]
NEXTCLOUD_EXCLUDES = [r"/js/.*", r"/l10n/.*"]

FILE_TIMEOUT = int(os.environ.get("HYDRA_GATE_SIG_FILE_TIMEOUT", "30"))


# --------------------------------------------------------------------------
# scope
# --------------------------------------------------------------------------

def sigrid_yaml_excludes(app):
    """Top-level `exclude:` regexes of sigrid.yaml / sigrid.yml, if present."""
    for name in ("sigrid.yaml", "sigrid.yml"):
        path = os.path.join(app, name)
        if not os.path.isfile(path):
            continue
        out, inside = [], False
        with open(path, encoding="utf-8", errors="replace") as fh:
            for line in fh:
                if re.match(r"^exclude:\s*$", line):
                    inside = True
                    continue
                if inside:
                    m = re.match(r"^\s+-\s+[\"']?(.+?)[\"']?\s*$", line)
                    if m:
                        out.append(m.group(1))
                    elif line.strip() and not line.startswith((" ", "\t", "#")):
                        inside = False
        return out
    return []


class Scope:
    def __init__(self, app):
        pats = SIGRID_DEFAULT_EXCLUDES + NEXTCLOUD_EXCLUDES + sigrid_yaml_excludes(app)
        self.exclude = [re.compile(p) for p in pats]
        self.tests = [re.compile(p) for p in TEST_PATTERNS]

    def language(self, rel):
        lang = LANG_OF_EXT.get(os.path.splitext(rel)[1].lower())
        if not lang:
            return None
        probe = "/" + rel
        if any(p.fullmatch(probe) for p in self.exclude):
            return None
        if any(p.fullmatch(probe) for p in self.tests):
            return None
        return lang


def tracked_files(app):
    rc, out = git(app, "ls-files", "-z")
    if rc == 0:
        return [p for p in out.split("\0") if p]
    found = []
    for root, dirs, files in os.walk(app):
        dirs[:] = [d for d in dirs if d not in (".git", "node_modules", "vendor")]
        for fn in files:
            found.append(os.path.relpath(os.path.join(root, fn), app))
    return found


def production_files(app, scope):
    """{rel path: language} for every measured file in the working tree."""
    out = {}
    for rel in tracked_files(app):
        lang = scope.language(rel)
        if lang and os.path.isfile(os.path.join(app, rel)):
            out[rel] = lang
    return out


def git(app, *args):
    p = subprocess.run(["git", "-c", "safe.directory=*", *args], cwd=app,
                       capture_output=True, text=True, errors="replace")
    return p.returncode, p.stdout


# --------------------------------------------------------------------------
# tools
# --------------------------------------------------------------------------

def tools_dir():
    base = os.environ.get("HYDRA_GATE_TOOLS_DIR") or os.path.join(
        os.environ.get("XDG_CACHE_HOME") or os.path.join(os.path.expanduser("~"), ".cache"),
        "hydra-gates", "tools")
    return base


def may_install():
    return os.environ.get("HYDRA_GATE_SIG_NO_INSTALL", "0") != "1"


def load_lizard():
    """Import lizard at the pinned version, installing it into the tool cache
    when allowed. Returns (module, None) or (None, reason)."""
    target = os.path.join(tools_dir(), f"lizard-{LIZARD_VERSION}")
    if os.path.isdir(target):
        sys.path.insert(0, target)
    try:
        import lizard  # noqa: F401
    except ImportError:
        lizard = None
    if lizard is not None and getattr(lizard, "version", "") == LIZARD_VERSION:
        return lizard, None
    if lizard is not None:
        found = getattr(lizard, "version", "?")
        for name in [m for m in sys.modules if m == "lizard" or m.startswith(("lizard_", "lizard."))]:
            del sys.modules[name]
        if target in sys.path:
            return None, f"lizard {found} is importable but {LIZARD_VERSION} is pinned; remove {target} and retry"
    if not may_install():
        return None, f"lizard {LIZARD_VERSION} is not installed and HYDRA_GATE_SIG_NO_INSTALL=1 forbids installing it"
    os.makedirs(target, exist_ok=True)
    p = subprocess.run([sys.executable, "-m", "pip", "install", "--quiet", "--disable-pip-version-check",
                        "--no-input", "--target", target, f"lizard=={LIZARD_VERSION}"],
                       capture_output=True, text=True)
    if p.returncode != 0:
        shutil.rmtree(target, ignore_errors=True)
        return None, f"pip could not install lizard=={LIZARD_VERSION}: {(p.stderr or p.stdout).strip()[-300:]}"
    sys.path.insert(0, target)
    try:
        import lizard
    except ImportError as e:
        return None, f"lizard installed into {target} but does not import: {e}"
    if getattr(lizard, "version", "") != LIZARD_VERSION:
        return None, f"imported lizard {getattr(lizard, 'version', '?')} from {lizard.__file__}, not the pinned {LIZARD_VERSION}"
    return lizard, None


def find_jscpd():
    """Path to a jscpd binary at the pinned version, or (None, reason)."""
    env = os.environ.get("HYDRA_GATE_JSCPD")
    target = os.path.join(tools_dir(), f"jscpd-{JSCPD_VERSION}")
    exe = env or os.path.join(target, "node_modules", ".bin", "jscpd")
    if not os.path.isfile(exe):
        if env:
            return None, f"HYDRA_GATE_JSCPD={env} does not exist"
        if not shutil.which("npm"):
            return None, "npm is not on PATH, so jscpd cannot be installed"
        if not may_install():
            return None, f"jscpd {JSCPD_VERSION} is not installed and HYDRA_GATE_SIG_NO_INSTALL=1 forbids installing it"
        os.makedirs(target, exist_ok=True)
        p = subprocess.run(["npm", "install", "--silent", "--no-audit", "--no-fund", "--ignore-scripts",
                            "--prefix", target, f"jscpd@{JSCPD_VERSION}"], capture_output=True, text=True)
        if p.returncode != 0 or not os.path.isfile(exe):
            return None, f"npm could not install jscpd@{JSCPD_VERSION}: {(p.stderr or p.stdout).strip()[-300:]}"
    p = subprocess.run([exe, "--version"], capture_output=True, text=True)
    found = (p.stdout or "").strip().splitlines()[-1:] or ["?"]
    if found[0] != JSCPD_VERSION:
        return None, f"{exe} reports jscpd {found[0]}, not the pinned {JSCPD_VERSION}"
    return exe, None


# --------------------------------------------------------------------------
# units
# --------------------------------------------------------------------------

class _Timeout(Exception):
    pass


def _alarm(_sig, _frame):
    raise _Timeout()


def unit_params(lang, func):
    if lang == "php":
        # lizard's PHP reader keeps only the last parameter; long_name lists each $name twice.
        return len(set(re.findall(r"\$\w+", func.long_name)))
    params = list(func.parameters)
    if lang == "python" and params and params[0] in ("self", "cls"):
        params = params[1:]
    return len(params)


VUE_BLOCK = re.compile(r"<!--.*?--!?>|<script\b[^>]*>(.*?)</script\b[^>]*>", re.S | re.I)
ACCESSOR_SHORTHAND = re.compile(r"(?<![\w$.])(get|set)(?=\s*\()")
ACCESSOR_ALIAS = "__sig_accessor_"


def vue_script(code):
    """The <script> blocks of a Vue SFC as (text, extension for lizard). Every
    character outside them is dropped except newlines, so a unit keeps the
    line numbers of the file itself. Template, style and HTML comments never
    reach lizard."""
    out, pos = [], 0
    for m in VUE_BLOCK.finditer(code):
        out.append("\n" * code.count("\n", pos, m.start(1) if m.group(1) is not None else m.end()))
        if m.group(1) is not None:
            out.append(m.group(1))
            out.append("\n" * code.count("\n", m.end(1), m.end()))
        pos = m.end()
    out.append("\n" * code.count("\n", pos))
    return "".join(out), ".ts"


class UnitReader:
    """lizard units of one file. Two lizard traps are worked around before
    lizard reads the code:
      * A Vue file is cut down to its <script> blocks (vue_script). lizard's
        own Vue reader tokenises the whole file, so a `=>` in a template
        handler, or a quote in an HTML comment, can hide every unit, and it
        numbers units from the <script> tag rather than the file.
      * Method shorthand named get or set (`get() {}`, a computed proxy) makes
        lizard's JavaScript and TypeScript readers drop every unit of the
        file. The name is aliased for lizard and restored in the result."""

    def __init__(self, lizard):
        self.analyzer = lizard.FileAnalyzer(lizard.get_extensions([]))
        self.skipped = []

    def units(self, rel, lang, code):
        """Units of one file's source. A file lizard cannot read is recorded in
        self.skipped and yields no units."""
        name = rel
        if lang == "vue":
            code, ext = vue_script(code)
            name = rel + ext
        if lang in ("vue", "javascript", "typescript"):
            code = ACCESSOR_SHORTHAND.sub(ACCESSOR_ALIAS + r"\1", code)
        use_alarm = hasattr(signal, "SIGALRM")
        if use_alarm:
            old = signal.signal(signal.SIGALRM, _alarm)
            signal.alarm(FILE_TIMEOUT)
        try:
            info = self.analyzer.analyze_source_code(name, code)
        except _Timeout:
            self.skipped.append({"file": rel, "reason": f"lizard did not finish within {FILE_TIMEOUT}s"})
            return []
        except Exception as e:  # lizard raises on some inputs; one file must not sink the run
            self.skipped.append({"file": rel, "reason": f"lizard raised {type(e).__name__}: {str(e)[:160]}"})
            return []
        finally:
            if use_alarm:
                signal.alarm(0)
                signal.signal(signal.SIGALRM, old)
        out = []
        for f in info.function_list:
            out.append({
                "file": rel, "lang": lang, "name": f.name.replace(ACCESSOR_ALIAS, ""),
                "start": f.start_line, "end": f.end_line,
                "nloc": f.nloc, "ccn": f.cyclomatic_complexity, "params": unit_params(lang, f),
            })
        return out


def read_text(path):
    with open(path, encoding="utf-8", errors="replace") as fh:
        return fh.read()


# --------------------------------------------------------------------------
# duplication
# --------------------------------------------------------------------------

def run_jscpd(app, exe, files):
    """Clone pairs among `files` (repo-relative), as a list of
    {"a": (file, start, end), "b": (file, start, end), "lines": n, "code": {file: set}}.

    jscpd matches tokens and skips comments, so a reported fragment can span a
    docblock, and its first and last line may match only in part. SIG counts
    code lines repeated literally modulo whitespace, so the code lines of both
    fragments are paired in order and only the pairs that are equal without
    whitespace count. A clone is kept when at least 6 lines do, and "code"
    holds those line numbers per fragment."""
    if not files:
        return []
    out_dir = tempfile.mkdtemp(prefix="sig-jscpd-")
    try:
        cmd = [exe, "--silent", "--reporters", "json", "--output", out_dir,
               "--min-lines", str(DUP_MIN_LINES), "--min-tokens", "30", "--mode", "weak", *files]
        p = subprocess.run(cmd, cwd=app, capture_output=True, text=True)
        report = os.path.join(out_dir, "jscpd-report.json")
        if not os.path.isfile(report):
            raise RuntimeError(f"jscpd exited {p.returncode} without a report: {(p.stderr or p.stdout).strip()[-300:]}")
        with open(report) as fh:
            data = json.load(fh)
    finally:
        shutil.rmtree(out_dir, ignore_errors=True)
    code, text = {}, {}

    def code_in(occ):
        f, s, e = occ
        if f not in code:
            lang = LANG_OF_EXT.get(os.path.splitext(f)[1].lower(), "")
            text[f] = read_text(os.path.join(app, f)).splitlines()
            code[f] = code_lines("\n".join(text[f]), lang)
        return [n for n in range(s, e + 1) if n in code[f]]

    def literal_pairs(occ_a, occ_b):
        same_a, same_b = set(), set()
        for na, nb in zip(code_in(occ_a), code_in(occ_b)):
            if "".join(text[occ_a[0]][na - 1].split()) == "".join(text[occ_b[0]][nb - 1].split()):
                same_a.add(na)
                same_b.add(nb)
        return same_a, same_b

    clones = []
    for d in data.get("duplicates", []):
        a, b = d["firstFile"], d["secondFile"]
        occ_a = (os.path.relpath(os.path.join(app, a["name"]), app), a["start"], a["end"])
        occ_b = (os.path.relpath(os.path.join(app, b["name"]), app), b["start"], b["end"])
        code_a, code_b = literal_pairs(occ_a, occ_b)
        if len(code_b) < DUP_MIN_LINES:
            continue
        clones.append({"a": occ_a, "b": occ_b, "lines": len(code_b), "code": {occ_a: code_a, occ_b: code_b}})
    return clones


def code_lines(text, lang):
    """Line numbers (1-based) holding code: not blank, not a comment-only line.
    In a Vue file an HTML comment (`<!-- ... -->`, such as the SPDX header) is a comment too."""
    lines = set()
    in_block = in_html = False
    hash_comments = lang in ("python", "php")
    for i, raw in enumerate(text.splitlines(), 1):
        s = raw.strip()
        if lang == "vue" and (in_html or s.startswith("<!--")):
            in_html = "-->" not in s[4 if s.startswith("<!--") else 0:]
            if in_html or not s.rsplit("-->", 1)[1].strip():
                continue
            s = s.rsplit("-->", 1)[1].strip()
        if in_block:
            if "*/" in s:
                in_block = False
                s = s.split("*/", 1)[1].strip()
            else:
                continue
        if not s:
            continue
        if s.startswith("/*"):
            if "*/" not in s[2:]:
                in_block = True
                continue
            s = s[s.index("*/", 2) + 2:].strip()
            if not s:
                continue
        if s.startswith("//") or (hash_comments and s.startswith("#")) or s.startswith("*"):
            continue
        lines.add(i)
    return lines


# --------------------------------------------------------------------------
# report
# --------------------------------------------------------------------------

def profile(units, key, limits, kind):
    """One row per bound: the share of unit LOC above it, the profile's limit,
    and the star level the share earns."""
    total = sum(u["nloc"] for u in units) or 1
    rows = []
    for i, (bound, cap) in enumerate(limits):
        loc = sum(u["nloc"] for u in units if u[key] > bound)
        share = loc / total
        rows.append({"above": bound, "share": round(share, 4), "loc": loc, "limit": cap, "ok": share <= cap,
                     "stars": stars_of(kind, round(share, 4), i)})
    return rows


def worst(units, key, n=8):
    ranked = sorted(units, key=lambda u: (-u[key], u["file"], u["start"]))[:n]
    return [f"{u['file']}:{u['start']} {u['name']} ({u[key]})" for u in ranked]


SFC_OPEN = re.compile(r"^<([a-zA-Z][\w-]*)\b")


def sfc_pieces(lines, code):
    """The code lines of a Vue file as one sorted list per piece: the inside
    of each top-level block (template, script, style, custom blocks) and each
    run of lines between blocks. The block's own tag lines are in no piece.
    A top-level tag starts in the first column, so an indented nested
    `<template>` stays inside its block. Boundaries are read from `lines`, so
    they hold when `code` leaves the tag lines out."""
    pieces, current, block = [], [], None
    for n, s in enumerate(lines, 1):
        opened = SFC_OPEN.match(s) if block is None else None
        if opened:
            pieces.append(current)
            current = []
            name = opened.group(1)
            if f"</{name}" not in s and not s.rstrip().endswith("/>"):
                block = name
        elif block is not None and re.match(rf"^</{re.escape(block)}\s*>", s):
            pieces.append(current)
            current, block = [], None
        elif n in code:
            current.append(n)
    pieces.append(current)
    return [p for p in pieces if p]


def dup_windows(lines, code, lang=None):
    """SIG's duplication windows of one file: (index, key, line numbers) for
    every run of DUP_MIN_LINES consecutive code lines, keyed by the lines
    without whitespace. `lines` is the file's text split into lines, `code`
    the line numbers code_lines() returns. Windows made only of brackets are
    skipped. In a Vue file no window crosses a block boundary (sfc_pieces),
    which is VUE_PIECES_ASSUMPTION."""
    pieces = sfc_pieces(lines, code) if lang == "vue" else [sorted(code)]
    index = 0
    for piece in pieces:
        seq = [(n, "".join(lines[n - 1].split())) for n in piece]
        for i in range(len(seq) - DUP_MIN_LINES + 1):
            win = seq[i:i + DUP_MIN_LINES]
            key = tuple(t for _n, t in win)
            if not all(len(t) <= 2 for t in key):
                yield index + i, key, [n for n, _t in win]
        index += len(seq)


def duplication_profile(app, clones, files_by_lang, limit=DUPLICATION_LIMIT):
    """Redundant code lines per language, two ways.

    "duplication" follows SIG's definition directly: every window of 6
    consecutive code lines, compared without whitespace, that already occurred
    earlier in a file of the same language makes its lines redundant (so N
    copies count N-1 times). Windows made only of brackets are skipped.

    "duplication_jscpd" is the cross-check from jscpd's clone pairs: the
    literally equal code lines of each pair's second occurrence. jscpd reports
    clones pairwise and needs 30 tokens, so it finds fewer clones than SIG."""
    lines_of, code = {}, {}
    for rel, lang in files_by_lang.items():
        text = read_text(os.path.join(app, rel))
        lines_of[rel] = text.splitlines()
        code[rel] = code_lines(text, lang)
    windows = collections.defaultdict(dict)
    redundant = collections.defaultdict(set)
    for rel in sorted(files_by_lang):
        seen = windows[files_by_lang[rel]]
        for i, key, nums in dup_windows(lines_of[rel], code[rel], files_by_lang[rel]):
            if key in seen and seen[key] != (rel, i):
                redundant[rel].update(nums)
            else:
                seen.setdefault(key, (rel, i))
    by_jscpd = collections.defaultdict(set)
    for c in clones:
        f = c["b"][0]
        if f in code:
            by_jscpd[f].update(c["code"][c["b"]])

    def row(dup, total):
        share = dup / total if total else 0.0
        return {"code_lines": total, "redundant_lines": dup, "share": round(share, 4),
                "limit": limit, "ok": share <= limit, "stars": stars_of("duplication", round(share, 4))}
    out = {}
    for lang in sorted(set(files_by_lang.values())):
        files = [f for f, lg in files_by_lang.items() if lg == lang]
        total = sum(len(code[f]) for f in files)
        out[lang] = (row(sum(len(redundant[f]) for f in files), total),
                     row(sum(len(by_jscpd[f]) for f in files), total))
    return out


# ---- module structure ------------------------------------------------------

def php_dependencies(app, files):
    """{module: set(modules it depends on)} for PHP files that declare a class
    in a namespace. Dependencies are `use` imports, fully-qualified names, and
    bare class names resolved in the file's own namespace. The namespace
    declaration itself is not a reference: lib/Db/Mapper/Handler.php in
    namespace ...\\Db\\Mapper does not depend on lib/Db/Mapper.php."""
    fqcn, ns_of, srcs = {}, {}, {}
    for rel in files:
        src = read_text(os.path.join(app, rel))
        m = re.search(r"^namespace\s+([^;]+);", src, re.M)
        if not m:
            continue
        ns = m.group(1).strip()
        ns_of[rel] = ns
        srcs[rel] = re.sub(r"^namespace\s+[^;]+;", "", re.sub(r"/\*.*?\*/|//[^\n]*", "", src, flags=re.S), count=1, flags=re.M)
        fqcn[f"{ns}\\{os.path.basename(rel)[:-4]}"] = rel
    deps = {}
    for rel, body in srcs.items():
        targets = set()
        for imp in re.findall(r"^use\s+([^;]+);", body, re.M):
            prefix = ""
            group = re.match(r"(.*)\\\{(.*)\}$", imp.strip())
            parts = group.group(2).split(",") if group else imp.split(",")
            if group:
                prefix = group.group(1).strip().lstrip("\\") + "\\"
            for part in parts:
                name = prefix + part.strip().split(" as ")[0].strip().lstrip("\\")
                if name in fqcn:
                    targets.add(fqcn[name])
        for ident in set(re.findall(r"\b([A-Z][A-Za-z0-9_]*)\b", body)):
            t = fqcn.get(f"{ns_of[rel]}\\{ident}")
            if t:
                targets.add(t)
        for fq in re.findall(r"\\?([A-Z][A-Za-z0-9_]*(?:\\[A-Za-z0-9_]+)+)", body):
            if fq in fqcn:
                targets.add(fqcn[fq])
        deps[rel] = targets - {rel}
    return deps


def python_dependencies(app, files):
    """{module: set(modules it imports)} for Python files, resolving absolute
    imports against the repository root and every `src`-style parent, and
    relative imports against the file's package."""
    index = {}
    for rel in files:
        mod = rel[:-3].replace("/", ".")
        if mod.endswith(".__init__"):
            mod = mod[: -len(".__init__")]
        parts = mod.split(".")
        for i in range(len(parts)):
            index.setdefault(".".join(parts[i:]), rel)

    def resolve(name):
        while name:
            if name in index:
                return index[name]
            name = name.rpartition(".")[0]
        return None

    deps = {}
    for rel in files:
        src = read_text(os.path.join(app, rel))
        pkg = rel[:-3].replace("/", ".").split(".")[:-1]
        targets = set()
        for m in re.finditer(r"^\s*import\s+([\w., ]+)", src, re.M):
            for part in m.group(1).split(","):
                t = resolve(part.strip().split(" as ")[0].strip())
                if t:
                    targets.add(t)
        for m in re.finditer(r"^\s*from\s+(\.*)([\w.]*)\s+import\s+(\([^)]*\)|[^\n#]+)", src, re.M):
            dots, base, names = m.group(1), m.group(2), m.group(3).strip("() \n")
            if dots:
                anchor = pkg[: len(pkg) - (len(dots) - 1)] if len(dots) > 1 else pkg
                base = ".".join([*anchor, base] if base else anchor)
            for n in names.replace("\n", " ").split(","):
                n = n.strip().split(" as ")[0].strip()
                t = resolve(f"{base}.{n}" if n else base) if base else None
                if t:
                    targets.add(t)
        deps[rel] = targets - {rel}
    return deps


def component_of_factory(files):
    """Components are directories. Start at the repository root and descend
    while one child directory holds more than 80% of the modules under the
    current root: lib/ in a Nextcloud app, src/api/ in a Python service. (In
    the fleet's apps lib/Service holds 50 to 71% of lib/, so it stays a
    component next to lib/Db and lib/Controller.) A component is a first-level
    directory below that root, and "(root)" for files directly in it. A module
    outside the root belongs to its own top-level directory ("(top)" for a file
    at the repository root)."""
    root = ""
    while files:
        children = collections.Counter()
        inside = [f for f in files if not root or f.startswith(root + "/")]
        for f in inside:
            rest = f[len(root) + 1:] if root else f
            if "/" in rest:
                children[rest.split("/")[0]] += 1
        if not children:
            break
        top, count = children.most_common(1)[0]
        if count * 5 <= len(inside) * 4:
            break
        root = f"{root}/{top}" if root else top

    def component(rel):
        if not root or rel.startswith(root + "/"):
            rest = rel[len(root) + 1:] if root else rel
            return rest.split("/")[0] if "/" in rest else "(root)"
        return rel.split("/")[0] if "/" in rel else "(top)"
    return root, component


def violation_degree(lines):
    """SIG communication violation degree from {(src, dst): weight}: direct
    cycles, then indirect cycles on what remains, then transitive lines.
    Each line is counted as at most one violation."""
    total = sum(lines.values()) or 1
    violated = {}
    for (a, b), w in lines.items():
        if (b, a) in lines and a < b:
            w2 = lines[(b, a)]
            low = min(w, w2)
            if w <= w2:
                violated[(a, b)] = ("cyclic", low)
            if w2 <= w:
                violated[(b, a)] = ("cyclic", low)
    remaining = {k: v for k, v in lines.items() if k not in violated}

    def find_cycle(graph):
        adj = collections.defaultdict(list)
        for (a, b) in graph:
            adj[a].append(b)
        state, stack = {}, []

        def dfs(n):
            state[n] = 1
            stack.append(n)
            for m in sorted(adj[n]):
                if state.get(m) == 1:
                    return stack[stack.index(m):] + [m]
                if not state.get(m):
                    c = dfs(m)
                    if c:
                        return c
            state[n] = 2
            stack.pop()
            return None
        for n in sorted(adj):
            if not state.get(n):
                c = dfs(n)
                if c:
                    return c
        return None

    while True:
        cyc = find_cycle(remaining)
        if not cyc:
            break
        edges = list(zip(cyc, cyc[1:]))
        weakest = min(edges, key=lambda e: (remaining[e], e))
        violated[weakest] = ("indirect-cyclic", remaining[weakest])
        del remaining[weakest]

    outgoing = collections.Counter(a for (a, _b) in remaining)
    adj = collections.defaultdict(set)
    for (a, b) in remaining:
        adj[a].add(b)

    def reach(start, skip):
        seen, todo = {start}, [start]
        while todo:
            n = todo.pop()
            for m in adj[n]:
                if (n, m) != skip and m not in seen:
                    seen.add(m)
                    todo.append(m)
        return seen

    rev = collections.defaultdict(set)
    for (a, b) in remaining:
        rev[b].add(a)

    def reach_back(start, skip):
        seen, todo = {start}, [start]
        while todo:
            n = todo.pop()
            for m in rev[n]:
                if (m, n) != skip and m not in seen:
                    seen.add(m)
                    todo.append(m)
        return seen

    for (a, b), w in sorted(remaining.items()):
        if not outgoing[b]:
            continue
        fwd, back = reach(a, (a, b)), reach_back(b, (a, b))
        if b not in fwd:
            continue
        on_paths = [remaining[(u, v)] for (u, v) in remaining
                    if (u, v) != (a, b) and u in fwd and v in back]
        if on_paths and w <= min(on_paths):
            violated[(a, b)] = ("transitive", w)
    degree = sum(w for _kind, w in violated.values()) / total
    kinds = collections.Counter(kind for kind, _w in violated.values())
    return round(degree, 4), dict(kinds), sorted(f"{a} -> {b} ({kind})" for (a, b), (kind, _w) in violated.items())


def structure(app, files, deps, prof=PROFILES[DEFAULT_PROFILE]):
    """Module coupling, component independence and entanglement for one language."""
    if not deps:
        return None
    modules = sorted(deps)
    loc = {m: len(code_lines(read_text(os.path.join(app, m)), files[m])) for m in modules}
    incoming = collections.defaultdict(set)
    for src, targets in deps.items():
        for t in targets:
            incoming[t].add(src)
    total = sum(loc.values()) or 1
    coupling = []
    for i, (bound, cap) in enumerate(prof["limits"]["module_coupling"]):
        above = sum(loc[m] for m in modules if len(incoming[m]) > bound)
        share = above / total
        coupling.append({"above": bound, "share": round(share, 4), "loc": above, "limit": cap, "ok": share <= cap,
                         "stars": stars_of("module_coupling", round(share, 4), i)})
    root, comp = component_of_factory(modules)
    hidden = sum(loc[m] for m in modules if all(comp(s) == comp(m) for s in incoming[m]))
    lines = collections.Counter()
    for t, srcs in incoming.items():
        for s in srcs:
            if comp(s) != comp(t):
                lines[(comp(s), comp(t))] += 1
    connected = {c for k in lines for c in k}
    density = len(lines) / max(len(connected), 1)
    degree, kinds, examples = violation_degree(dict(lines))
    comp_loc = collections.Counter()
    for m in modules:
        comp_loc[comp(m)] += loc[m]
    independence = hidden / total
    return {
        "modules": len(modules),
        "module_loc": total,
        "module_coupling": coupling,
        "highest_fan_in": [f"{m} (fan-in {len(incoming[m])}, {loc[m]} loc)"
                           for m in sorted(modules, key=lambda m: (-len(incoming[m]), m))[:10]],
        "component_root": root or ".",
        "components": dict(comp_loc.most_common()),
        "component_independence": {"hidden_share": round(independence, 4), "hidden_loc": hidden,
                                   "limit_min": prof["independence_min"],
                                   "ok": independence >= prof["independence_min"],
                                   "stars": stars_of("component_independence", round(independence, 4))},
        "component_entanglement": {
            "communication_lines": len(lines),
            "connected_components": len(connected),
            "communication_density": round(density, 2),
            "violation_degree": round(degree, 4),
            "violations": kinds,
            "violation_examples": examples[:12],
            "limit": prof["entanglement"],
            "ok": None,
            "stars": stars_of("component_entanglement", round(degree, 4)),
            "note": "SIG normalises density and violation degree by benchmark maxima it does not publish, "
                    "so this is the two inputs, not the rated value. Lower is better on both. "
                    "`stars` rates the violation degree against the entanglement bands as an estimate; "
                    "it is not a floored check.",
        },
    }


def build_report(app, lizard, jscpd, profile_name=DEFAULT_PROFILE, reader=None):
    """The risk profile of one app. `jscpd` None skips the jscpd cross-check
    (duplication_jscpd is then None); `reader` replaces the plain UnitReader,
    for a caller that caches units."""
    prof = PROFILES[profile_name]
    scope = Scope(app)
    files = production_files(app, scope)
    reader = reader or UnitReader(lizard)
    units = []
    for rel, lang in sorted(files.items()):
        units.extend(reader.units(rel, lang, read_text(os.path.join(app, rel))))
    by_lang = collections.defaultdict(list)
    for u in units:
        by_lang[u["lang"]].append(u)
    clones = run_jscpd(app, jscpd, sorted(files)) if files and jscpd else []
    dup = duplication_profile(app, clones, files, prof["duplication"])
    report = {
        "app": os.path.basename(os.path.abspath(app)),
        "head": (git(app, "rev-parse", "HEAD")[1] or "").strip() or None,
        "tools": {"lizard": LIZARD_VERSION, "jscpd": JSCPD_VERSION if jscpd else None},
        "profile": profile_name,
        "stars": prof["stars"],
        "limits": prof["source"],
        "files": len(files),
        "skipped_files": reader.skipped,
        "assumptions": [VUE_PIECES_ASSUMPTION] if "vue" in files.values() else [],
        "languages": {},
    }
    for lang in sorted(set(files.values())):
        rows = by_lang.get(lang, [])
        report["languages"][lang] = {
            "files": sum(1 for v in files.values() if v == lang),
            "units": len(rows),
            "unit_loc": sum(u["nloc"] for u in rows),
            "unit_size": profile(rows, "nloc", prof["limits"]["unit_size"], "unit_size"),
            "unit_complexity": profile(rows, "ccn", prof["limits"]["unit_complexity"], "unit_complexity"),
            "unit_interfacing": profile(rows, "params", prof["limits"]["unit_interfacing"], "unit_interfacing"),
            "duplication": dup[lang][0] if lang in dup else None,
            "duplication_jscpd": dup[lang][1] if lang in dup and jscpd else None,
            "largest_units": worst(rows, "nloc"),
            "most_complex_units": worst(rows, "ccn"),
            "widest_units": worst(rows, "params"),
        }
    php = {f: lg for f, lg in files.items() if lg == "php" and f.startswith("lib/")}
    py = {f: lg for f, lg in files.items() if lg == "python"}
    report["structure"] = {}
    if php:
        report["structure"]["php"] = structure(app, php, php_dependencies(app, php), prof)
    if py:
        report["structure"]["python"] = structure(app, py, python_dependencies(app, py), prof)
    report["rating"] = rating_estimate(report)
    return report


def checks_of(report):
    """Every check of the report's profile as (label, ok)."""
    out = []
    for lang, d in report["languages"].items():
        for prop in ("unit_size", "unit_complexity", "unit_interfacing"):
            for row in d[prop]:
                out.append((f"{lang} {prop} >{row['above']}", row["ok"]))
        if d.get("duplication"):
            out.append((f"{lang} duplication", d["duplication"]["ok"]))
    for lang, s in report.get("structure", {}).items():
        if not s:
            continue
        for row in s["module_coupling"]:
            out.append((f"{lang} module_coupling >{row['above']}", row["ok"]))
        out.append((f"{lang} component_independence", s["component_independence"]["ok"]))
    return out


# ---- rating estimate ---------------------------------------------------------
#
# An estimate of SIG's continuous maintainability rating (0.5 to 5.5), built
# from the report. Sources and assumptions: research/sig-stars.md section 4.
#   check         Alves, Correia, Visser 2011, eq. 1: linear interpolation
#                 between the caps of the star level a share falls in; a
#                 property is the minimum over its checks. Worse than 2 stars
#                 falls linearly to 0.5 at 100% (assumed).
#   language      a property's per-language ratings, weighted by the code that
#                 property measures in each language (assumed).
#   sub-char.     plain mean of the mapped properties (criteria v12.0 mapping,
#                 volume and component balance dropped).
#   overall       power mean of the sub-characteristics. SIG publishes no
#                 exponent, so p = 1 and p = -1 are both reported.

# Assumed caps on the exposed share (1 - hidden) and on the violation degree.
# Only 4 stars is published; every level doubles the exposure of the one above.
RATING_EXPOSED_CAPS = {
    "component_independence": {5: .0275, 4: .055, 3: .11, 2: .22},
    "component_entanglement": {5: .0375, 4: .075, 3: .15, 2: .30},
}
RATED_PROPERTIES = ("duplication", "unit_size", "unit_complexity", "unit_interfacing",
                    "module_coupling", "component_independence", "component_entanglement")
SUBCHARACTERISTICS = {
    "analysability": ("duplication", "unit_size"),
    "modifiability": ("duplication", "unit_complexity", "module_coupling"),
    "testability": ("unit_complexity", "component_independence"),
    "modularity": ("module_coupling", "component_independence", "component_entanglement"),
    "reusability": ("unit_size", "unit_interfacing"),
}
EXPONENTS = (1, -1)
LEVER_LOC = 1000
SURE_TOP = 3


def rating_caps(kind, index=None):
    """{5: cap, 4: cap, 3: cap, 2: cap} of one check, as a maximum exposed share."""
    if kind in RATING_EXPOSED_CAPS:
        return RATING_EXPOSED_CAPS[kind]
    bands = STAR_BANDS[kind]
    return {lvl: bands[lvl] if index is None else bands[lvl][index] for lvl in (5, 4, 3, 2)}


def interpolate(value, caps):
    """Continuous rating of one exposed share: s0 + 0.5 - (v - t0) / (t1 - t0),
    t0 and t1 the caps around v (0 below 5 stars, 100% past 2 stars)."""
    lower = 0.0
    for level in (5, 4, 3, 2):
        if value <= caps[level]:
            start, upper = level, caps[level]
            break
        lower = caps[level]
    else:
        start, upper = 1, 1.0
    if upper <= lower:
        return start + 0.5
    return max(0.5, min(5.5, start + 0.5 - (value - lower) / (upper - lower)))


def power_mean(values, p):
    values = [v for v in values if v is not None]
    if not values:
        return None
    if p == 0:
        product = 1.0
        for v in values:
            product *= v
        return product ** (1 / len(values))
    return (sum(v ** p for v in values) / len(values)) ** (1 / p)


def rating_inputs(report):
    """{property: {language: {"loc": weight, "above": [exposed loc per check]}}}
    from a gate-122 report. Entanglement carries "value" (the violation degree)
    instead of exposed loc. Reports written before rows carried "loc" are
    read from share x total."""
    def above(rows, total):
        return [r["loc"] if "loc" in r else round(r["share"] * total) for r in rows]
    out = {prop: {} for prop in RATED_PROPERTIES}
    for lang, d in report.get("languages", {}).items():
        total = d.get("unit_loc") or 0
        for prop in ("unit_size", "unit_complexity", "unit_interfacing"):
            out[prop][lang] = {"loc": total, "above": above(d[prop], total)}
        dup = d.get("duplication")
        if dup:
            out["duplication"][lang] = {"loc": dup["code_lines"], "above": [dup["redundant_lines"]]}
    for lang, s in (report.get("structure") or {}).items():
        if not s:
            continue
        total = s["module_loc"]
        out["module_coupling"][lang] = {"loc": total, "above": above(s["module_coupling"], total)}
        ci = s["component_independence"]
        hidden = ci["hidden_loc"] if "hidden_loc" in ci else round(ci["hidden_share"] * total)
        out["component_independence"][lang] = {"loc": total, "above": [total - hidden]}
        out["component_entanglement"][lang] = {"loc": total, "value": s["component_entanglement"]["violation_degree"]}
    return out


def check_ratings(prop, entry):
    """The continuous rating of each check of one property in one language."""
    if "value" in entry:
        return [interpolate(entry["value"], rating_caps(prop))]
    single = len(entry["above"]) == 1
    return [interpolate(a / entry["loc"], rating_caps(prop, None if single else i))
            for i, a in enumerate(entry["above"])]


def property_rating(prop, entry):
    if not entry or not entry.get("loc"):
        return None
    return min(check_ratings(prop, entry))


def rate_inputs(inputs):
    """Property, sub-characteristic and overall ratings of rating_inputs()."""
    props = {}
    for prop in RATED_PROPERTIES:
        langs = {lang: (property_rating(prop, e), e["loc"]) for lang, e in inputs.get(prop, {}).items()}
        weighted = [(r, w) for r, w in langs.values() if r is not None and w]
        rating = sum(r * w for r, w in weighted) / sum(w for _r, w in weighted) if weighted else None
        props[prop] = {"rating": rating,
                       "languages": {lg: {"rating": r, "loc": w} for lg, (r, w) in langs.items() if r is not None}}
    subs = {}
    for sub, members in SUBCHARACTERISTICS.items():
        subs[sub] = power_mean([props[m]["rating"] for m in members], 1)
    overall = {p: power_mean(list(subs.values()), p) for p in EXPONENTS}
    return {"properties": props, "subcharacteristics": subs, "overall": overall}


def fix_loc(prop, entry, n):
    """`entry` with n exposed lines brought to low risk, worst band first: the
    lines above the highest bound go before those above the next one down.
    Fixed duplication is removed code, so it also leaves the total."""
    above = list(entry["above"])
    exclusive = [a - b for a, b in zip(above, above[1:] + [0])]
    left = n
    for i in reversed(range(len(exclusive))):
        take = min(left, exclusive[i])
        exclusive[i] -= take
        left -= take
    cumulative = [sum(exclusive[i:]) for i in range(len(exclusive))]
    total = entry["loc"] - (n - left if prop == "duplication" else 0)
    return {"loc": total, "above": cumulative}


def with_entry(inputs, prop, lang, entry):
    out = {p: dict(v) for p, v in inputs.items()}
    out[prop][lang] = entry
    return out


def levers(inputs, base=None):
    """For every property and language: how much the overall rating rises when
    the next LEVER_LOC exposed lines (or all of them, when fewer) move to low
    risk, worst band first (a finite difference, under each exponent), and the
    lines needed to raise the property by 0.5. Ranked by that gain under each
    exponent and listed by the sum of both ranks; "sure" when a lever is in the
    top SURE_TOP under every exponent."""
    base = base or rate_inputs(inputs)
    if all(base["overall"][p] is None for p in EXPONENTS):
        return []
    out = []
    for prop in RATED_PROPERTIES:
        for lang, entry in sorted(inputs.get(prop, {}).items()):
            if "value" in entry or not entry.get("loc") or entry["above"][0] <= 0:
                continue
            n = min(LEVER_LOC, entry["above"][0])
            after = rate_inputs(with_entry(inputs, prop, lang, fix_loc(prop, entry, n)))
            out.append({"property": prop, "language": lang, "fixable_loc": entry["above"][0], "fixed_loc": n,
                        "gain_per_kloc": {p: after["overall"][p] - base["overall"][p] for p in EXPONENTS},
                        "loc_for_half_star": loc_for_half_star(inputs, prop, lang,
                                                               base["properties"][prop]["rating"])})
    for p in EXPONENTS:
        for rank, lever in enumerate(sorted(out, key=lambda lv: (-lv["gain_per_kloc"][p], lv["property"],
                                                                 lv["language"])), 1):
            lever.setdefault("rank", {})[p] = rank
    for lever in out:
        lever["sure"] = all(lever["rank"][p] <= SURE_TOP for p in EXPONENTS)
    return sorted(out, key=lambda lv: (sum(lv["rank"].values()), lv["rank"][-1]))


def loc_for_half_star(inputs, prop, lang, before):
    """Fewest lines fixed in one language that raise the property's
    cross-language rating by 0.5, or None when fixing all of them does not."""
    entry = inputs[prop][lang]
    target = min(before + 0.5, 5.5)

    def reached(n):
        after = rate_inputs(with_entry(inputs, prop, lang, fix_loc(prop, entry, n)))
        return after["properties"][prop]["rating"] >= target - 1e-9
    high = entry["above"][0]
    if not reached(high):
        return None
    low = 0
    while high - low > 1:
        mid = (low + high) // 2
        if reached(mid):
            high = mid
        else:
            low = mid
    return high


def rating_estimate(report):
    """The rating section of a report: every level, the range, the levers."""
    inputs = rating_inputs(report)
    rated = rate_inputs(inputs)
    overall = [v for v in rated["overall"].values() if v is not None]

    def r2(x):
        return None if x is None else round(x, 2)
    return {
        "note": "Estimate of SIG's maintainability rating, 0.5 to 5.5. SIG publishes 4-star limits only and "
                "no power-mean exponent, so the overall rating is a range over p = 1 and p = -1.",
        "overall": {"p1": r2(rated["overall"][1]), "p-1": r2(rated["overall"][-1]),
                    "range": [r2(min(overall)), r2(max(overall))] if overall else None},
        "subcharacteristics": {k: r2(v) for k, v in rated["subcharacteristics"].items()},
        "properties": {k: {"rating": r2(v["rating"]),
                           "languages": {lg: {"rating": r2(x["rating"]), "loc": x["loc"]}
                                         for lg, x in v["languages"].items()}}
                       for k, v in rated["properties"].items()},
        "levers": [{"property": lv["property"], "language": lv["language"],
                    "fixable_loc": lv["fixable_loc"], "fixed_loc": lv["fixed_loc"],
                    "gain_per_kloc": {"p1": round(lv["gain_per_kloc"][1], 4),
                                      "p-1": round(lv["gain_per_kloc"][-1], 4)},
                    "rank": {"p1": lv["rank"][1], "p-1": lv["rank"][-1]},
                    "sure": lv["sure"], "loc_for_half_star": lv["loc_for_half_star"]}
                   for lv in levers(inputs, rated)],
    }


def pct(x):
    return f"{x * 100:.1f}%"


def rating_markdown(rating, top=5):
    """The rating estimate as markdown lines: range, sub-characteristics, properties, levers."""
    if not rating or not rating["overall"]["range"]:
        return []

    def r(x):
        return "n/a" if x is None else f"{x:.2f}"
    lo, hi = rating["overall"]["range"]
    out = ["", f"Estimated SIG maintainability rating: {lo:.2f} to {hi:.2f} "
               f"(power mean p = -1 {r(rating['overall']['p-1'])}, p = 1 {r(rating['overall']['p1'])}). "
               "An estimate: SIG publishes only the 4-star limits and no exponent.", "",
           "Sub-characteristics: " + ", ".join(f"{k} {r(v)}" for k, v in rating["subcharacteristics"].items()) + ".",
           "Properties: " + ", ".join(f"{k.replace('_', ' ')} {r(v['rating'])}"
                                      for k, v in rating["properties"].items()) + "."]
    if rating["levers"]:
        out += ["", f"Levers: the overall gain from fixing the next {LEVER_LOC:,} exposed lines (fewer when fewer "
                    "exist), worst band first.", "",
                "| lever | fixable lines | gain, p = 1 / p = -1 | lines for +0.5 | sure |", "|---|---|---|---|---|"]
        for lv in rating["levers"][:top]:
            half = (f"{lv['loc_for_half_star']:,}" if lv["loc_for_half_star"] is not None
                    else f"not by {lv['language']} alone")
            out.append(f"| {lv['property'].replace('_', ' ')} ({lv['language']}) | {lv['fixable_loc']:,} | "
                       f"{lv['gain_per_kloc']['p1']:+.4f} / {lv['gain_per_kloc']['p-1']:+.4f} | {half} | "
                       f"{'yes' if lv['sure'] else 'no'} |")
    return out


def markdown(report):
    out = [f"## SIG maintainability profile: {report['app']}", "",
           f"lizard {LIZARD_VERSION}, jscpd {JSCPD_VERSION}; limits: {report['limits']}. "
           f"Each cell is the share of code above the bound, against the {report['stars']} limit, "
           f"with the star level it earns.", "",
           "| language | units | size >15/30/60 | complexity >5/10/25 | params 3+/5+/7+ | duplication | jscpd |",
           "|---|---|---|---|---|---|---|"]

    def cells(rows):
        return " / ".join(f"{pct(r['share'])} {r['stars']}★{'' if r['ok'] else ' ✗'}" for r in rows)
    for lang, d in report["languages"].items():
        dup = d.get("duplication")
        dcell = f"{pct(dup['share'])} {dup['stars']}★{'' if dup['ok'] else ' ✗'}" if dup else "n/a"
        jdup = d.get("duplication_jscpd")
        jcell = pct(jdup["share"]) if jdup else "n/a"
        out.append(f"| {lang} | {d['units']} | {cells(d['unit_size'])} | {cells(d['unit_complexity'])} "
                   f"| {cells(d['unit_interfacing'])} | {dcell} | {jcell} |")
    for lang, s in report.get("structure", {}).items():
        if not s:
            continue
        e = s["component_entanglement"]
        ci = s["component_independence"]
        out += ["", f"{lang} structure ({s['modules']} modules, components under `{s['component_root']}`): "
                f"module coupling >10/20/50 {cells(s['module_coupling'])}; "
                f"component independence {pct(ci['hidden_share'])} {ci['stars']}★{'' if ci['ok'] else ' ✗'} (min {pct(ci['limit_min'])}); "
                f"entanglement inputs: {e['communication_lines']} lines between {e['connected_components']} components, "
                f"density {e['communication_density']}, violation degree {e['violation_degree']}."]
    checks = checks_of(report)
    out += ["", f"{sum(1 for _c, ok in checks if ok)} of {len(checks)} {report['stars']} checks met. ✗ marks a miss."]
    out += rating_markdown(report.get("rating"))
    for assumption in report.get("assumptions", []):
        out += ["", f"Assumption: {assumption}"]
    if report["skipped_files"]:
        out += ["", f"{len(report['skipped_files'])} file(s) lizard could not read, NOT measured: "
                + ", ".join(s["file"] for s in report["skipped_files"][:10])]
    return "\n".join(out) + "\n"


def cmd_report(args, lizard, jscpd):
    report = build_report(args.app, lizard, jscpd, args.profile)
    if args.json:
        with open(args.json, "w") as fh:
            json.dump(report, fh, indent=1)
    md = markdown(report)
    if args.md:
        with open(args.md, "w") as fh:
            fh.write(md)
    print(md)
    for s in report["skipped_files"]:
        print(f"  skipped (lizard): {s['file']}: {s['reason']}")
    if not report["files"]:
        print(f"{GATE}: no measured source file in this repository (php, js, ts, vue, py outside tests and Sigrid's default excludes)")
        return 4
    checks = checks_of(report)
    missed = [c for c, ok in checks if not ok]
    units = sum(d["units"] for d in report["languages"].values())
    print(f"{GATE}: report: {units} unit(s) "
          f"in {report['files']} file(s), languages {', '.join(report['languages'])}; "
          f"{len(checks) - len(missed)} of {len(checks)} {report['stars']} checks met"
          + (f"; missed: {', '.join(missed)}" if missed else ""))
    return 0


# --------------------------------------------------------------------------
# ratchet
# --------------------------------------------------------------------------

def added_lines(app, base, rel, old=None):
    """Lines of `rel` the change added. A renamed file is diffed against its old
    path, so a pure move adds nothing and only the lines edited in it count."""
    paths = [old, rel] if old and old != rel else [rel]
    rc, out = git(app, "diff", "-U0", "--no-color", "-M", "--src-prefix=a/", "--dst-prefix=b/",
                  base, "HEAD", "--", *paths)
    lines = set()
    current = None
    for line in out.splitlines():
        if line.startswith("+++ "):
            current = line[4:]
            current = current[2:] if current.startswith("b/") else None
            continue
        m = re.match(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@", line)
        if m and current == rel:
            start, count = int(m.group(1)), int(m.group(2) or 1)
            lines.update(range(start, start + count))
    return lines


def changed_paths(app, base):
    """{new path: old path or None} for files added, copied, modified or renamed."""
    rc, out = git(app, "diff", "--name-status", "-M", "--diff-filter=ACMR", base, "HEAD")
    paths = {}
    for line in out.splitlines():
        parts = line.split("\t")
        if parts[0].startswith(("R", "C")) and len(parts) == 3:
            paths[parts[2]] = parts[1] if parts[0].startswith("R") else None
        elif len(parts) == 2:
            paths[parts[1]] = parts[1] if parts[0] != "A" else None
    return paths


def cmd_ratchet(args, lizard, jscpd_exe):
    app = args.app
    rc, mb = git(app, "merge-base", args.base_ref, "HEAD")
    if rc != 0 or not mb.strip():
        print(f"{GATE}: the base '{args.base_ref}' has no merge base with HEAD, so no change could be isolated")
        return 3
    mb = mb.strip()
    scope = Scope(app)
    changed = {}
    for new, old in changed_paths(app, mb).items():
        lang = scope.language(new)
        if lang and os.path.isfile(os.path.join(app, new)):
            changed[new] = (lang, old)
    if not changed:
        print(f"{GATE}: this change touches no measured source file (php, js, ts, vue, py outside tests and Sigrid's default excludes)")
        return 4

    reader = UnitReader(lizard)
    new_findings, inherited = [], []
    touched_units = 0
    added = {}
    for rel, (lang, old) in sorted(changed.items()):
        added[rel] = added_lines(app, mb, rel, old)
        head_units = reader.units(rel, lang, read_text(os.path.join(app, rel)))
        base_units = {}
        if old:
            rc, base_src = git(app, "show", f"{mb}:{old}")
            if rc == 0:
                for u in reader.units(old, lang, base_src):
                    base_units.setdefault(u["name"], u)
        for u in head_units:
            touched = any(u["start"] <= n <= u["end"] for n in added[rel])
            touched_units += touched
            before = base_units.get(u["name"])
            for key, bound in RATCHET.items():
                if u[key] <= bound:
                    continue
                f = {"file": rel, "line": u["start"], "unit": u["name"], "metric": key,
                     "value": u[key], "rule": RATCHET_LABEL[key],
                     "before": before[key] if before else None}
                if not touched:
                    inherited.append(f)
                elif before is None or before[key] <= bound or u[key] > before[key]:
                    new_findings.append(f)
                else:
                    inherited.append(f)

    # Duplication: clones among every measured file of the languages this change touches.
    langs = {lang for lang, _old in changed.values()}
    corpus = sorted(f for f, lg in production_files(app, scope).items() if lg in langs)
    if not any(len(lines) >= DUP_MIN_LINES for lines in added.values()):
        corpus = []  # no file gained 6 lines, so no new clone is possible
    dup_new = 0
    for c in run_jscpd(app, jscpd_exe, corpus):
        hits = []
        for occ in (c["a"], c["b"]):
            f, s, e = occ
            if f in added and len(added[f] & c["code"][occ]) >= DUP_MIN_LINES:
                hits.append(occ)
        if hits:
            f, s, e = hits[0]
            other = c["b"] if hits[0] == c["a"] else c["a"]
            new_findings.append({"file": f, "line": s, "unit": f"lines {s}-{e}", "metric": "duplication",
                                 "value": c["lines"], "rule": f"duplicated fragment of {DUP_MIN_LINES}+ lines",
                                 "before": None, "other": f"{other[0]}:{other[1]}-{other[2]}"})
            dup_new += 1

    for s in reader.skipped:
        print(f"  unverified (lizard could not read it): {s['file']}: {s['reason']}")
    for f in new_findings:
        was = f" (was {f['before']})" if f.get("before") is not None else ""
        extra = f", same as {f['other']}" if f.get("other") else ""
        print(f"  {f['file']}:{f['line']} {f['unit']}: {f['rule']}: {f['value']}{was}{extra}")
    if inherited:
        print(f"  inherited, not counted: {len(inherited)} finding(s) on units this change did not touch "
              f"or did not make worse")
        for f in inherited[:10]:
            print(f"    {f['file']}:{f['line']} {f['unit']}: {f['rule']}: {f['value']}")
    tail = (f"lizard {LIZARD_VERSION}, jscpd {JSCPD_VERSION}; {len(inherited)} inherited"
            + (f"; {len(reader.skipped)} file(s) NOT measured" if reader.skipped else ""))
    if new_findings:
        print(f"{GATE}: FAIL: {len(new_findings)} finding(s) in {touched_units} changed unit(s) "
              f"({dup_new} duplicated fragment(s)); {tail}")
        return 1
    print(f"{GATE}: checked {touched_units} changed unit(s) in {len(changed)} file(s), 0 finding(s); {tail}")
    return 0


# --------------------------------------------------------------------------

def main(argv):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="mode", required=True)
    r = sub.add_parser("ratchet")
    r.add_argument("app")
    r.add_argument("--base-ref", required=True)
    p = sub.add_parser("report")
    p.add_argument("app")
    p.add_argument("--json")
    p.add_argument("--md")
    p.add_argument("--profile", default=os.environ.get("HYDRA_GATE_SIG_PROFILE") or DEFAULT_PROFILE,
                   help="limits to report against: " + ", ".join(PROFILES)
                        + " (default: HYDRA_GATE_SIG_PROFILE, else " + DEFAULT_PROFILE + ")")
    args = ap.parse_args(argv)
    args.app = os.path.abspath(args.app)
    if args.mode == "report" and args.profile not in PROFILES:
        print(f"{GATE}: unknown profile '{args.profile}'; choose one of {', '.join(PROFILES)}")
        return 2

    lizard, why = load_lizard()
    if not lizard:
        print(f"{GATE}: tooling: {why}")
        return 5
    jscpd, why = find_jscpd()
    if not jscpd:
        print(f"{GATE}: tooling: {why}")
        return 5
    if args.mode == "ratchet":
        return cmd_ratchet(args, lizard, jscpd)
    return cmd_report(args, lizard, jscpd)


# Sigrid's default excludes, verbatim, from
# github.com/Software-Improvement-Group/sigridci resources/default-excludes.txt
# (fetched 2026-10-29). Matched against "/" + the repo-relative path.
SIGRID_DEFAULT_EXCLUDES = r"""
.*/[.]cache/.*
.*/[.]sonarlint/.*
.*/[.]DS_Store
.*/[.]ipynb
.*/[.]class
.*/[.]classpath
.*/[.]project
.*/[.]hg/.*
.*/.*[.][Gg]en(enerated)?[.].*
.*/[.]circleci/.*
.*/[.]git/.*
.*/git[.]log
.*/[.]github/.*
.*/[.]gitlab/.*
.*/[.]gradle/.*
.*/[.]idea/.*
.*/[.]maven/.*
.*/[.]mvn/.*
.*/[.]phpstorm[.]meta[.]php/.*
.*/[.]prettierrc[.]js
.*/[.]settings/.*
.*/[.]svn/.*
.*/[.]teamcity/.*
.*/\$tf/.*
.*/__MACOSX/.*
.*/.*(-|.)min[.](css|js)
.*/.*[.]asm[.]js
.*/.*[.]conf(ig)?[.](js|ts|coffee)
.*/.*[.]es[.]js
.*/.*[.]eslintrc[.]js
.*/.*[.]excl[.]mendix
.*/.*[.]g[.]vb
.*/.*[.]out[.]js
.*/.*[.][Dd]esigner[.]cs
.*/.*[.]style[.](js|jsx|ts|tsx)
.*/.*[.]styles[.](js|jsx|ts|tsx)
.*/.*[.]umd[.]js
.*/.*[.]vendor[.](js|ts)
.*/.*[.]wasm[.]js
.*/3rd(party)?/.*
.*/AppData/.*
.*/[Aa]ssembly[Ii]nfo.*
.*/bin/.*
.*/bitbucket-pipelines[.]yml
.*bootstrap.*[.](js|css)
.*/modernizr.*[.]js
.*/bower_components/.*
.*/branches/.*
.*/build/.*
.*/build[.]rs
.*/contrib/.*
.*/[Dd]ebug/.*
.*/dist/.*
.*/(java)?doc(s)?/.*
.*/environment[.][\w.]*[.](t|j)s
.*/example(s)?/.*
.*/external-files/.*
.*/gen(enerated)?/.*
.*/gradle.*[.]properties
.*/gradlew[.]bat
.*/InvisibleStyleSheet[.]css
.*/jdiff/.*
.*/jquery.*[.](js|css)
.*/library-preload[.]js
.*/LICENSE[.]txt
.*/locale/.*[.]js
.*/log4j.*[.]properties
.*/mapping.*[.]txt
.*/META-INF/.*
.*[Mm]ock.*
.*/[Mm]igrations?/.*
.*/node_modules/.*
.*/open3rd/.*
.*/obj/.*
.*/out/.*
.*/package-info[.]java
.*/[Pp]ods/.*
.*/polyfills[.]js
.*/proguard-rules[.]pro
.*/project[.]properties
.*/public/components/.*
.*/[Rr]elease/.*
.*/Reference[.]cs
.*/sap/.*/libs/.*[.]js
.*/sapui5-rt.*
.*/Service References/.*
.*sigridci.*
.*/sigrid(-metadata)?[.]ya?ml
.*/src/test/resources/.*
.*stub.*
.*/tags/.*
.*/target(-eclipse)?/.*
.*/[Tt]emporary[Gg]enerated[Ff]ile.*
.*/theme/.*[.]css
.*/themes/.*[.]css
.*/[Tt]hird(_|-)?[Pp]arty/.*
.*/[Vv]endor/.*
.*/widgets/com[.]mendix[.].*
.*/widgets/.*/com/mendix/.*
.*/.*vendor.*[.]js
.*pom[.]xml
.*/webpack[.][^/]*[.]js
.*/webpack[.](js|ts)
.*/wwwroot/assets/.*
.*/App[.]config
.*tinymce.*
.*toastify.*
.*x-editable.*
.*/.*[.]editorConfig[.]js
.*/.*[.]editorPreview[.]js
.*[.]gradle
.*[.]gradle[.]kts
.*[.]lock[.]hcl
.*AssemblyInfo[.]cs
.*/[.]sigrid/.*
""".strip().splitlines()


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
