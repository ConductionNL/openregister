#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""The component dependency graph of lib/ under the ADR-012 functional components (ADR-013 Rule 5).

    python3 scripts/component-deps.py                    # the report, for a developer before a push
    python3 scripts/component-deps.py --write-baseline   # rewrite tests/Unit/Architecture/component-dependency-baseline.json
    python3 scripts/component-deps.py --edges-json -     # the raw graph, as ComponentDependencyRulesTest compares it
    python3 scripts/component-deps.py --check-fixture    # the three pinned sample files

The report prints the totals (exposed LOC and violation degree, with the SIG rating each gives), the
communication lines per component pair with their weight, the exposed modules per component, the
violated pairs, and the module edges that break ADR-013 Rule 1: an edge between two different
non-Kernel components, or from a Kernel file to a non-Kernel component.

The components are the `components:` block of sigrid.components.yaml when the tree has it, else
tests/Unit/Architecture/fixtures/components.yaml (a copy of the block the lanes are measured with),
or the file given with --components.

The dependency reader, the LOC count, the scope and the violation degree are those of the SIG
estimate tool (gate 122, check_sig_maintainability.py with the pattern-components patch). When
tools/sig/check_sig_maintainability.py is in the tree it is imported; otherwise the copies below,
taken from sig-measure/check_sig_maintainability.py on the sig-lane-briefs/r12c branch, are used.
Both give the numbers of `lane_measure_c.py` (MEASURE) on the same tree.

tests/Unit/Architecture/ComponentDependencyRulesTest.php builds the same graph in PHP and runs this
script with --edges-json to prove the two readers agree.
"""
import argparse
import collections
import json
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
APP = os.path.dirname(HERE)
ARCH = os.path.join("tests", "Unit", "Architecture")
BASELINE = os.path.join(ARCH, "component-dependency-baseline.json")
FIXTURE_COMPONENTS = os.path.join(ARCH, "fixtures", "components.yaml")
FIXTURE_SAMPLE = os.path.join(ARCH, "fixtures", "component-deps")
KERNEL = "Kernel"
APPINFO_PREFIX = "lib/AppInfo/"
APPINFO_NAME = re.compile(r"OpenRegister\\+AppInfo\\+")


# --------------------------------------------------------------------------
# Copied from check_sig_maintainability.py (sig-measure, sig-lane-briefs/r12c, with the
# pattern-components patch). Used only when tools/sig/check_sig_maintainability.py is absent.
# Keep in step with that file: the numbers must equal MEASURE's.
# --------------------------------------------------------------------------

class _Tool:
    TEST_PATTERNS = [
        r".*/tests?/.*", r".*/__tests__/.*", r".*/__mocks__/.*", r".*/e2e/.*",
        r".*[.](spec|test)[.](js|mjs|cjs|jsx|ts|tsx)",
        r".*/test_[^/]*[.]py", r".*/[^/]*_test[.]py", r".*/conftest[.]py",
    ]
    NEXTCLOUD_EXCLUDES = [r"/js/.*", r"/l10n/.*"]
    RATING_EXPOSED_CAPS = {
        "component_independence": {5: .0275, 4: .055, 3: .11, 2: .22},
        "component_entanglement": {5: .0375, 4: .075, 3: .15, 2: .30},
    }
    # Sigrid's default excludes, github.com/Software-Improvement-Group/sigridci resources/default-excludes.txt.
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

    @staticmethod
    def read_text(path):
        with open(path, encoding="utf-8", errors="replace") as fh:
            return fh.read()

    @staticmethod
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

    @staticmethod
    def git(app, *args):
        p = subprocess.run(["git", "-c", "safe.directory=*", *args], cwd=app,
                           capture_output=True, text=True, errors="replace")
        return p.returncode, p.stdout

    @classmethod
    def tracked_files(cls, app):
        rc, out = cls.git(app, "ls-files", "-z")
        if rc == 0:
            return [p for p in out.split("\0") if p]
        found = []
        for root, dirs, files in os.walk(app):
            dirs[:] = [d for d in dirs if d not in (".git", "node_modules", "vendor")]
            for fn in files:
                found.append(os.path.relpath(os.path.join(root, fn), app))
        return found

    @staticmethod
    def code_lines(text, lang):
        """Line numbers (1-based) holding code: not blank, not a comment-only line (PHP subset)."""
        lines = set()
        in_block = False
        hash_comments = lang in ("python", "php")
        for i, raw in enumerate(text.splitlines(), 1):
            s = raw.strip()
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

    @classmethod
    def php_dependencies(cls, app, files):
        """{module: set(modules it depends on)}: `use` imports (group use included), fully
        qualified names, and bare class names resolved in the file's own namespace; comments
        stripped; the namespace declaration itself is not a reference."""
        fqcn, ns_of, srcs = {}, {}, {}
        for rel in files:
            src = cls.read_text(os.path.join(app, rel))
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

    @staticmethod
    def pattern_components(app):
        """[(name, [include regex], [exclude regex])] of the `components:` block in SIG_COMPONENTS_YAML."""
        path = os.environ.get("SIG_COMPONENTS_YAML") or os.path.join(app, "sigrid.yaml")
        if not os.path.isfile(path):
            return []
        comps, cur, key = [], None, None
        for line in open(path, encoding="utf-8", errors="replace"):
            stripped = line.strip()
            if not stripped or stripped.startswith("#"):
                continue
            if not line.startswith(" ") and stripped.startswith("components:"):
                cur, key = "section", None
                continue
            if cur is None:
                continue
            if not line.startswith(" "):
                break
            m = re.match(r"^\s*-\s*name:\s*(.+?)\s*$", line)
            if m:
                comps.append((m.group(1).strip('"\''), [], []))
                key = None
                continue
            m = re.match(r"^\s*(include|exclude):\s*$", line)
            if m:
                key = m.group(1)
                continue
            m = re.match(r"^\s*-\s*(.+?)\s*$", line)
            if m and comps and key:
                comps[-1][1 if key == "include" else 2].append(re.compile(m.group(1).strip().strip('"\'')))
        return comps

    @staticmethod
    def pattern_component_of_factory(comps):
        cache = {}

        def component(rel):
            if rel in cache:
                return cache[rel]
            probe = "/" + rel
            name = "Remainder"
            for cname, inc, exc in comps:
                if any(p.fullmatch(probe) for p in inc) and not any(p.fullmatch(probe) for p in exc):
                    name = cname
                    break
            cache[rel] = name
            return name
        return component

    @staticmethod
    def violation_degree(lines):
        """SIG communication violation degree from {(src, dst): weight}: direct cycles, then
        indirect cycles on what remains, then transitive lines. Each line counts once."""
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

    @staticmethod
    def rating_caps(kind):
        return _Tool.RATING_EXPOSED_CAPS[kind]

    @staticmethod
    def interpolate(value, caps):
        """Continuous rating of one exposed share."""
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


def load_tool(app):
    """tools/sig/check_sig_maintainability.py when the tree has it, else the copies above."""
    path = os.path.join(app, "tools", "sig")
    if os.path.isfile(os.path.join(path, "check_sig_maintainability.py")):
        sys.path.insert(0, path)
        try:
            import check_sig_maintainability as tool  # noqa: E402
            if hasattr(tool, "pattern_components"):
                return tool, "tools/sig/check_sig_maintainability.py"
        except Exception:  # noqa: BLE001 - fall back to the copy
            pass
        finally:
            sys.path.remove(path)
    return _Tool, "the copy in scripts/component-deps.py"


# --------------------------------------------------------------------------
# The graph
# --------------------------------------------------------------------------

def excludes(tool, app):
    """Every path regex the tool leaves out of the measured scope: Sigrid's defaults, the Nextcloud
    build output, sigrid.yaml's own excludes and the test-code patterns."""
    defaults = list(tool.SIGRID_DEFAULT_EXCLUDES) + list(tool.NEXTCLOUD_EXCLUDES)
    return defaults, tool.sigrid_yaml_excludes(app), list(tool.TEST_PATTERNS)


def lib_php_files(tool, app):
    defaults, own, tests = excludes(tool, app)
    pats = [re.compile(p) for p in defaults + own + tests]
    out = []
    for rel in tool.tracked_files(app):
        if not rel.startswith("lib/") or os.path.splitext(rel)[1].lower() != ".php":
            continue
        if any(p.fullmatch("/" + rel) for p in pats):
            continue
        if os.path.isfile(os.path.join(app, rel)):
            out.append(rel)
    return out


def components_file(app, given):
    if given:
        return os.path.abspath(given)
    own = os.path.join(app, "sigrid.components.yaml")
    if os.path.isfile(own):
        return own
    return os.path.join(app, FIXTURE_COMPONENTS)


def component_function(tool, app, yaml_path):
    old = os.environ.get("SIG_COMPONENTS_YAML")
    os.environ["SIG_COMPONENTS_YAML"] = yaml_path
    try:
        comps = tool.pattern_components(app)
    finally:
        if old is None:
            os.environ.pop("SIG_COMPONENTS_YAML", None)
        else:
            os.environ["SIG_COMPONENTS_YAML"] = old
    if not comps:
        raise SystemExit(f"no components: block in {yaml_path}")
    return tool.pattern_component_of_factory(comps)


def analyse(tool, app, yaml_path):
    files = lib_php_files(tool, app)
    deps = tool.php_dependencies(app, files)
    comp = component_function(tool, app, yaml_path)
    loc = {f: len(tool.code_lines(tool.read_text(os.path.join(app, f)), "php")) for f in deps}
    incoming = collections.defaultdict(set)
    for s, ts in deps.items():
        for t in ts:
            incoming[t].add(s)
    mods = sorted(deps)
    exposed = sorted(x for x in mods if any(comp(s) != comp(x) for s in incoming[x]))
    lines = collections.Counter()
    for t in mods:
        for s in incoming[t]:
            if comp(s) != comp(t):
                lines[(comp(s), comp(t))] += 1
    degree, kinds, violated = tool.violation_degree(dict(lines))
    total = sum(loc.values()) or 1
    exposed_loc = sum(loc[x] for x in exposed)
    edges = sorted((s, t) for s, ts in deps.items() for t in ts)
    forbidden = [(s, t) for s, t in edges if comp(s) != comp(t) and comp(t) != KERNEL]
    return dict(deps=deps, comp=comp, loc=loc, exposed=exposed, lines=lines, degree=degree, kinds=kinds,
                violated=violated, total=total, exposed_loc=exposed_loc, edges=edges, forbidden=forbidden,
                independence=tool.interpolate(exposed_loc / total, tool.rating_caps("component_independence")),
                entanglement=tool.interpolate(degree, tool.rating_caps("component_entanglement")))


def appinfo_naming(tool, app, files):
    """lib/ files outside lib/AppInfo whose code (comments stripped) names OCA\\OpenRegister\\AppInfo\\."""
    out = []
    for rel in files:
        if rel.startswith(APPINFO_PREFIX):
            continue
        body = re.sub(r"/\*.*?\*/|//[^\n]*", "", tool.read_text(os.path.join(app, rel)), flags=re.S)
        if APPINFO_NAME.search(body):
            out.append(rel)
    return sorted(out)


def edge(s, t):
    return f"{s} -> {t}"


# --------------------------------------------------------------------------
# Output
# --------------------------------------------------------------------------

def report(a, app, yaml_path, source):
    r = a
    rel_yaml = os.path.relpath(yaml_path, app)
    print(f"components {rel_yaml}; reader {source}")
    print(f"TOTAL: exposed {r['exposed_loc']:,} of {r['total']:,} LOC ({100 * r['exposed_loc'] / r['total']:.2f}%, "
          f"independence {r['independence']:.2f}), lines {len(r['lines'])}, violation degree {r['degree']:.4f} "
          f"(entanglement {r['entanglement']:.2f}), modules {len(r['deps'])}")
    print("\nCOMMUNICATION LINES (source -> target: module edges)")
    for (s, t), w in sorted(r['lines'].items(), key=lambda kv: (-kv[1], kv[0])):
        print(f"  {s} -> {t}: {w}")
    print("\nEXPOSED MODULES PER COMPONENT (LOC, referenced from another component)")
    per = collections.defaultdict(list)
    for x in r['exposed']:
        per[r['comp'](x)].append(x)
    for c in sorted(per, key=lambda c: -sum(r['loc'][x] for x in per[c])):
        mods = sorted(per[c], key=lambda x: (-r['loc'][x], x))
        print(f"  {c}: {len(mods)} modules, {sum(r['loc'][x] for x in mods):,} LOC")
        for x in mods[:a_limit(ARGS)]:
            print(f"    {x} ({r['loc'][x]})")
        if len(mods) > a_limit(ARGS):
            print(f"    and {len(mods) - a_limit(ARGS)} more")
    print(f"\nVIOLATED PAIRS ({len(r['violated'])}: {', '.join(f'{k} {v}' for k, v in sorted(r['kinds'].items()))})")
    for v in r['violated']:
        print(f"  {v}")
    print(f"\nRULE 1 MODULE EDGES (non-Kernel to another non-Kernel component, or Kernel to non-Kernel): {len(r['forbidden'])}")
    base = load_baseline(app)
    if base is not None:
        known = set(base.get("forbidden_edges", []))
        new = [edge(s, t) for s, t in r['forbidden'] if edge(s, t) not in known]
        print(f"  not in {BASELINE}: {len(new)}")
        for e in new:
            s, t = e.split(" -> ")
            print(f"    {e} ({r['comp'](s)} -> {r['comp'](t)})")


ARGS = None


def a_limit(args):
    return args.limit if args is not None else 20


def load_baseline(app):
    path = os.path.join(app, BASELINE)
    if not os.path.isfile(path):
        return None
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def write_baseline(tool, app, yaml_path, r):
    files = lib_php_files(tool, app)
    head = (tool.git(app, "rev-parse", "--short=12", "HEAD")[1] or "").strip()
    defaults, _own, tests = excludes(tool, app)
    data = {
        "_comment": [
            "Generated by scripts/component-deps.py --write-baseline; do not edit by hand.",
            "An upper bound (ADR-013 Rule 5, ratchet): ComponentDependencyRulesTest fails on a Rule 1 module edge",
            "or a file naming OCA\\OpenRegister\\AppInfo\\ that is not listed here. Entries that no longer occur are",
            "allowed; a cleanup PR regenerates the file to shrink it. Never add an entry to get a green build.",
        ],
        "generated_on": head,
        "components": os.path.relpath(yaml_path, app),
        "scope_excludes": defaults,
        "test_patterns": tests,
        "forbidden_edges": [edge(s, t) for s, t in r['forbidden']],
        "appinfo_naming": appinfo_naming(tool, app, files),
    }
    with open(os.path.join(app, BASELINE), "w", encoding="utf-8") as fh:
        json.dump(data, fh, indent=1)
        fh.write("\n")
    print(f"wrote {BASELINE}: {len(data['forbidden_edges'])} module edges, {len(data['appinfo_naming'])} AppInfo-naming files")


def edges_json(tool, app, r, out):
    files = lib_php_files(tool, app)
    data = {
        "edges": [edge(s, t) for s, t in r['edges']],
        "exposed_loc": r['exposed_loc'],
        "total_loc": r['total'],
        "violation_degree": r['degree'],
        "appinfo_naming": appinfo_naming(tool, app, files),
    }
    text = json.dumps(data, indent=1) + "\n"
    if out == "-":
        sys.stdout.write(text)
    else:
        with open(out, "w", encoding="utf-8") as fh:
            fh.write(text)


def fixture_result(tool, app):
    root = os.path.join(app, FIXTURE_SAMPLE)
    r = analyse(tool, root, os.path.join(root, "components.yaml"))
    return {
        "edges": [edge(s, t) for s, t in r['edges']],
        "forbidden_edges": [edge(s, t) for s, t in r['forbidden']],
        "exposed": r['exposed'],
        "exposed_loc": r['exposed_loc'],
        "lines": {f"{s} -> {t}": w for (s, t), w in sorted(r['lines'].items())},
        "violation_degree": r['degree'],
    }


def main(argv=None):
    global ARGS
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--app", default=APP, help="the app tree (default: this repository)")
    ap.add_argument("--components", help="components block (default: sigrid.components.yaml, else the fixture copy)")
    ap.add_argument("--write-baseline", action="store_true", help=f"write {BASELINE}")
    ap.add_argument("--edges-json", metavar="FILE", help="write the raw graph as JSON (- for stdout)")
    ap.add_argument("--check-fixture", action="store_true", help="compare the sample files with their pinned expectation")
    ap.add_argument("--limit", type=int, default=20, help="exposed modules listed per component (default 20)")
    ARGS = a = ap.parse_args(argv)
    app = os.path.abspath(a.app)
    tool, source = load_tool(app)
    if a.check_fixture:
        got = fixture_result(tool, app)
        with open(os.path.join(app, FIXTURE_SAMPLE, "expected.json"), encoding="utf-8") as fh:
            want = json.load(fh)
        if got != want:
            print(json.dumps(got, indent=1))
            print("fixture: MISMATCH with expected.json")
            return 1
        print("fixture: the reader gives the pinned graph")
        return 0
    yaml_path = components_file(app, a.components)
    r = analyse(tool, app, yaml_path)
    if a.edges_json:
        edges_json(tool, app, r, a.edges_json)
        return 0
    if a.write_baseline:
        write_baseline(tool, app, yaml_path, r)
        return 0
    report(r, app, yaml_path, source)
    return 0


if __name__ == "__main__":
    sys.exit(main())
