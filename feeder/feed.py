#!/usr/bin/env python3
"""Feeder for the cloud worker queue (queue/work.tsv on the state branch).

Cloud copy of the local feeder: runs hourly from a routine (see FEEDER.md next to this file). Discovery uses
GraphQL when it answers, else REST (GraphQL is blocked in cloud sessions): the open PRs of the 20 repos cost
about 35 REST calls per run. Each task row carries
everything a worker needs (head shas, PR numbers, base), so workers read only with git.

Adds, never duplicating (ids are deterministic):
  fix     fleet PRs whose latest verdict at the current head is RED, CONFLICT, BLOCK or LINT
  review  openregister PRs on main: never reviewed at any sha, or listed in queue/needs-review.tsv,
          and without an APPROVE/BLOCK at the current head
  verify  open quality/* fleet PRs on development with no verdict at their head, trees of <= 25 per app
  merge   open quality/* fleet PRs GREEN at their head and not yet merged, trees of <= 25 (stacks in order)
          (verify and merge are paused while REST remaining < 800: those workers merge over REST)
  gap     checker-gap rows from GAP-QUEUE.md (FEEDER_INPUTS), one task per row and app
  cover   one slice per directory group with untested classes, for apps below 80% coverage
  build   continuation (:c2..:c4) of a change whose last build was partial, or failed on a blocker that has
          landed since, while its tasks.md on the base still has unchecked code tasks (24 h cooldown)
Also closes unclaimed tasks whose subject went away (head moved, PR closed) with result `stale`.
Logs to $FEEDER_DIR/feed.log. `--dry-run` writes nothing.
"""
import calendar, hashlib, json, os, re, subprocess, sys, time
from collections import defaultdict

HOME = os.path.expanduser("~")
DIR = os.environ.get("FEEDER_DIR") or os.path.join(HOME, "lanes/feeder")
# GAP-QUEUE.md, SCORES-LOCAL.md, fv-merged.tsv, fv-stacks.tsv: inputs copied from the local box, next to this file
INPUTS = os.environ.get("FEEDER_INPUTS") or os.path.join(os.path.dirname(os.path.abspath(__file__)), "inputs")
ST = os.environ.get("FEEDER_ST") or os.path.join(DIR, "state")
CLONES = os.path.join(DIR, "clones")
LOG = os.path.join(DIR, "feed.log")
GAPQ = os.path.join(INPUTS, "GAP-QUEUE.md")
ORG = "ConductionNL"
BRANCH = "sig-pipeline/state"
FLEET = ("stackiq learniq dossiq buildiq pipelinq hermiq integriq humaniq shillinq decidiq portaliq "
         "filinq keepiq launchpad planninq opencatalogi versioniq larpinq thematiq").split()
OR = "openregister"
BASE = {**{a: "development" for a in FLEET}, OR: "main"}
TREE_MAX = 25
VERIFY_TREE = 8  # 17 Nov push (Ruben): small verify trees, fewer conflicts between siblings
MIN_OPEN = 80  # open tasks other than merge
REST_FLOOR = int(os.environ.get("FEEDER_REST_FLOOR", "800"))
COVER_TARGET = 90.0  # PHP and JS suites, per app (Ruben 12 Nov 22:10: five stars, coverage included)
HEADROOM_TARGET = 95.0  # second cover pass, only while the queue is short
# weakest apps first (Ruben 22:10); the rest by their gap to the target
COVER_FIRST = ("stackiq", "dossiq", "buildiq", "integriq")
SCORES = os.path.join(INPUTS, "SCORES-LOCAL.md")
LOCAL_COV_MAX_AGE = 3 * 86400
UNIT_MIN, UNIT_SPLIT, SLICE_MAX = 80, 2500, 1500
SKIP_DIRS = ("lib/Migration", "lib/AppInfo")
DRY = "--dry-run" in sys.argv
ENV = {**os.environ, "ST": ST, "ST_ME_FILE": os.path.join(DIR, ".id"), "SIG_ROLE": "cloudfeeder"}


def log(msg):
    line = f"{time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())} {msg}"
    print(line)
    with open(LOG, "a") as fh:
        fh.write(line + "\n")


def run(cmd, cwd=None, inp=None):
    return subprocess.run(cmd, cwd=cwd, input=inp, capture_output=True, text=True, env=ENV)


def git(cwd, *a):
    return run(["git", *a], cwd=cwd)


def h8(s):
    return hashlib.sha1(s.encode()).hexdigest()[:8]


def clean(s, n=300):
    return re.sub(r"[\t\r\n]+", " ", s or "").strip()[:n]


def rate():
    r = run(["gh", "api", "rate_limit"])
    try:
        d = json.loads(r.stdout)["resources"]
        return d["core"]["remaining"], d["graphql"]["remaining"]
    except Exception:
        return -1, -1


def graphql(query):
    r = run(["gh", "api", "graphql", "-f", f"query={query}"])
    if r.returncode:
        raise RuntimeError(f"graphql: {(r.stderr or r.stdout).strip()[:300]}")
    return json.loads(r.stdout)["data"]


# PR branches the merge pipeline verifies and merges: the cloud's quality/* plus Ruben's own and dependabot's
# (Ruben 15 Nov: merge the open PRs first). Promotions (release/, sync/, development) and colleagues' PRs stay out.
MERGE_AUTHORS = ("rubenvdlinde", "app/dependabot", "dependabot")
MERGE_SKIP_PREFIX = ("release/", "sync/", "development", "bot/", "train/")


def mergeable_branch(p):
    h = p["headRefName"]
    if h.startswith("quality/"):
        return True
    if h.startswith(MERGE_SKIP_PREFIX):
        return False
    return ((p.get("author") or {}).get("login") or "") in MERGE_AUTHORS


def _rest_lines(path, jq, per_page=100):
    """Every item of a REST list endpoint, one JSON object per jq output line. Pages are requested by number
    on the owner/repo path: gh --paginate follows GitHub's next links, which name the repository by numeric
    id (/repositories/<id>/...), and the cloud session proxy refuses those."""
    out, page = [], 1
    sep = "&" if "?" in path else "?"
    while True:
        r = run(["gh", "api", f"{path}{sep}page={page}", "--jq", f"length, ({jq})"])
        if r.returncode:
            raise RuntimeError(f"rest {path} page {page}: {(r.stderr or r.stdout).strip()[:300]}")
        lines = [x for x in r.stdout.splitlines() if x.strip()]
        if not lines:
            break
        n = int(lines[0])
        out.extend(json.loads(x) for x in lines[1:])
        if n < per_page or page >= 50:
            break
        page += 1
    return out


# The GraphQL shape of a PR, built from the REST pulls list. REST names the dependabot app "dependabot[bot]",
# GraphQL "dependabot": the login is mapped so mergeable_branch() sees what it always saw.
REST_PR = ('.[] | {number, title, isDraft: .draft, headRefName: .head.ref, headRefOid: .head.sha, '
           'baseRefName: .base.ref, createdAt: .created_at, author: {login: (.user.login | rtrimstr("[bot]"))}}')


def open_prs_rest(repos):
    return {a: _rest_lines(f"repos/{ORG}/{a}/pulls?state=open&per_page=100", REST_PR) for a in repos}


def open_prs(repos):
    """{app: [pr dict]} of every open PR: GraphQL when it answers (FEEDER_REST=1 skips it), else REST."""
    if os.environ.get("FEEDER_REST") != "1":
        try:
            return open_prs_graphql(repos)
        except Exception as e:
            log(f"graphql discovery failed ({str(e)[:120]}); using REST")
    return open_prs_rest(repos)


def open_prs_graphql(repos):
    """{app: [pr dict]} of every open PR, paginated per repo, several repos per query."""
    out = {a: [] for a in repos}
    cursors = {a: None for a in repos}
    pending = list(repos)
    fields = "number title isDraft headRefName headRefOid baseRefName createdAt author { login }"
    while pending:
        batch, pending = pending[:10], pending[10:]
        parts = []
        for i, a in enumerate(batch):
            after = f', after: "{cursors[a]}"' if cursors[a] else ""
            parts.append(f'r{i}: repository(owner: "{ORG}", name: "{a}") {{ pullRequests(states: OPEN, first: 100{after}) '
                         f'{{ pageInfo {{ hasNextPage endCursor }} nodes {{ {fields} }} }} }}')
        data = graphql("query { " + " ".join(parts) + " }")
        for i, a in enumerate(batch):
            pr = (data.get(f"r{i}") or {}).get("pullRequests") or {}
            out[a].extend(pr.get("nodes") or [])
            if (pr.get("pageInfo") or {}).get("hasNextPage"):
                cursors[a] = pr["pageInfo"]["endCursor"]
                pending.append(a)
    return out


def pr_files(app, numbers):
    """{number: [paths]} for the given PRs of one repo (first 100 files each)."""
    if os.environ.get("FEEDER_REST") == "1":
        return {n: [f["path"] for f in _rest_lines(f"repos/{ORG}/{app}/pulls/{n}/files?per_page=100", ".[] | {path: .filename}")]
                for n in numbers}
    res = {}
    numbers = list(numbers)
    for i in range(0, len(numbers), 20):
        chunk = numbers[i:i + 20]
        parts = [f'p{n}: pullRequest(number: {n}) {{ files(first: 100) {{ nodes {{ path }} }} }}' for n in chunk]
        data = graphql(f'query {{ repository(owner: "{ORG}", name: "{app}") {{ ' + " ".join(parts) + " } }")
        repo = data.get("repository") or {}
        for n in chunk:
            res[n] = [f["path"] for f in (((repo.get(f"p{n}") or {}).get("files") or {}).get("nodes") or [])]
    return res


def state_sync():
    if not os.path.isdir(os.path.join(ST, ".git")):
        git(DIR, "clone", "-q", "--single-branch", "--branch", BRANCH, f"https://github.com/{ORG}/{OR}", ST)
        git(ST, "config", "user.name", "Ruben van der Linde")
        git(ST, "config", "user.email", "ruben@conduction.nl")
    r = run(["bash", "-c", "source bin/state.sh && st_sync"], cwd=ST)
    if r.returncode:
        raise RuntimeError(f"state sync failed: {r.stderr.strip()[:200]}")


def read_tsv(path):
    try:
        return [l.rstrip("\n").split("\t") for l in open(os.path.join(ST, path)) if l.strip() and not l.startswith("#")]
    except FileNotFoundError:
        return []


def live_leases():
    now, live = time.time(), set()
    d = os.path.join(ST, "leases")
    for f in os.listdir(d) if os.path.isdir(d) else []:
        if not f.endswith(".lease"):
            continue
        try:
            exp = int(re.search(r"^expires_epoch=(\d+)", open(os.path.join(d, f)).read(), re.M).group(1))
        except Exception:
            continue
        if exp > now:
            live.add(f[:-6])
    return live


def task_lease(tid):
    return "task-" + re.sub(r"[^A-Za-z0-9._-]", "_", tid)


def st_append(path, lines):
    if DRY or not lines:
        return True
    for i in range(0, len(lines), 200):
        r = run(["bash", "-c", 'source bin/state.sh && f=$1 && shift && st_append "$f" "$@"', "-", path, *lines[i:i + 200]], cwd=ST)
        if r.returncode:
            log(f"st_append {path} rc={r.returncode} {r.stderr.strip()[:200]}")
            return False
    return True


def payload(d):
    return ";".join(f"{k}={clean(str(v), 400).replace(';', ',')}" for k, v in d.items())


def parse_payload(s):
    return dict(kv.split("=", 1) for kv in s.split(";") if "=" in kv)


# ---------------------------------------------------------------- coverage slices
def clone_base(app):
    c = os.path.join(CLONES, app)
    b = BASE[app]
    if not os.path.isdir(os.path.join(c, ".git")):
        os.makedirs(CLONES, exist_ok=True)
        if git(CLONES, "clone", "-q", "--depth", "1", "--branch", b, "--no-checkout",
               f"https://github.com/{ORG}/{app}", c).returncode:
            return None
    elif git(c, "fetch", "-q", "--depth", "1", "origin", b).returncode == 0:
        git(c, "update-ref", "HEAD", "FETCH_HEAD")
    return c


def coverage_of(c):
    """({"php": pct, "js": pct}, generation id) from the committed summary's suites, else .coverage-baseline."""
    sm = git(c, "show", "HEAD:coverage-summary.json")
    if sm.returncode == 0:
        try:
            d = json.loads(sm.stdout)
            gen = git(c, "rev-parse", "HEAD:coverage-summary.json").stdout.strip()[:8]
            out = {}
            for k, v in (d.get("suites") or {}).items():
                pct = (v.get("statements") or v.get("lines") or {}).get("pct")
                if pct is not None:
                    out["php" if k == "phpunit" else "js"] = float(pct)
            if "php" not in out:
                out["php"] = float(d["total"]["lines"]["pct"])
            return out, gen
        except Exception:
            pass
    b = git(c, "show", "HEAD:.coverage-baseline")
    if b.returncode == 0:
        try:
            return {"php": float(b.stdout.strip().split()[0])}, git(c, "rev-parse", "HEAD:.coverage-baseline").stdout.strip()[:8]
        except Exception:
            pass
    return {}, ""


def unit_of(path, depth=2):
    """<root>/A/B/... -> '<root>/A/B/' at depth 2; files directly in a shallower dir -> '<root>/A/*.php' (or '/*')."""
    parts = path.split("/")
    root, dirs = parts[0], parts[1:-1]
    if len(dirs) >= depth:
        return root + "/" + "/".join(dirs[:depth]) + "/"
    return root + "/" + "".join(d + "/" for d in dirs) + ("*.php" if path.endswith(".php") else "*")


def newest(paths):
    """Newest existing file of the given paths, if it is younger than LOCAL_COV_MAX_AGE."""
    paths = [p for p in paths if os.path.isfile(p) and time.time() - os.path.getmtime(p) < LOCAL_COV_MAX_AGE]
    return max(paths, key=os.path.getmtime) if paths else None


def measured(app):
    """queue/coverage/<app>.json written by a measure task, if younger than LOCAL_COV_MAX_AGE; else None."""
    try:
        d = json.load(open(os.path.join(ST, "queue/coverage", f"{app}.json")))
        t = calendar.timegm(time.strptime(d["measured_at"], "%Y-%m-%dT%H:%M:%SZ"))
        return d if time.time() - t < LOCAL_COV_MAX_AGE else None
    except Exception:
        return None


def measured_weights(app, lang, tree):
    """{pseudo file in each measured directory: uncovered statements x 3}, so slices() groups real gaps."""
    d = (measured(app) or {}).get(lang)
    if not d or not d.get("dirs"):
        return None
    out = {}
    root = "lib/" if lang == "php" else "src/"
    for dname, (st, cov) in d["dirs"].items():
        if dname.startswith(root) and st - cov > 0 and not dname.startswith(SKIP_DIRS) \
                and any(f.startswith(dname) for f in tree):
            out[dname + ("_.php" if lang == "php" else "_.js")] = 3 * (st - cov)
    return out or None


def php_uncovered(app, tree):
    """{lib path: uncovered statements x 3} from the newest local clover of the app, or None."""
    import glob
    import xml.etree.ElementTree as ET
    f = newest(glob.glob(os.path.join(HOME, f"lanes/cgc/out/{app}-*-clover.xml"))
               + glob.glob(os.path.join(HOME, f"lanes/cg*/{app}/coverage/clover.xml"))
               + (glob.glob(os.path.join(HOME, "lanes/orcova/coverage/clover.xml")) if app == OR else []))
    if not f:
        return None
    out = {}
    try:
        for _, el in ET.iterparse(f):
            if el.tag != "file":
                continue
            name = el.get("name", "")
            k = name.find("/lib/")
            m = el.find("metrics")
            if k >= 0 and m is not None:
                rel = name[k + 1:]
                miss = int(m.get("statements", 0)) - int(m.get("coveredstatements", 0))
                if rel in tree and miss > 0 and not rel.startswith(SKIP_DIRS):
                    out[rel] = out.get(rel, 0) + 3 * miss
            el.clear()
    except Exception:
        return None
    return out


JS_EXT = (".js", ".vue", ".ts")


def js_uncovered(app, c, tree, pct):
    """{src path: uncovered statements x 3} from a json-summary (committed or a local run), else size x (1 - pct)."""
    import glob
    summary = None
    for rel in ("coverage-frontend/coverage-summary.json", "coverage-vitest/coverage-summary.json"):
        r = git(c, "show", f"HEAD:{rel}")
        if r.returncode == 0:
            summary = r.stdout
            break
    if summary is None:
        f = newest(glob.glob(os.path.join(HOME, f"lanes/cg*/{app}/coverage-vitest/coverage-summary.json"))
                   + glob.glob(os.path.join(HOME, f"lanes/cg*/{app}/coverage-frontend/coverage-summary.json"))
                   + (glob.glob(os.path.join(HOME, "lanes/orcova/coverage-frontend/coverage-summary.json")) if app == OR else []))
        if f:
            summary = open(f).read()
    out = {}
    if summary:
        try:
            for name, v in json.loads(summary).items():
                k = name.find("src/")
                if name == "total" or k < 0:
                    continue
                rel = name[k:] if name.startswith("src/") else name[name.find("/src/") + 1:] if "/src/" in name else None
                st = v.get("statements") or {}
                miss = int(st.get("total", 0)) - int(st.get("covered", 0))
                if rel and rel in tree and miss > 0:
                    out[rel] = 3 * miss
            if out:
                return out, "uncovered"
        except Exception:
            pass
    lines = {}
    for l in git(c, "grep", "-c", "", "HEAD", "--", *[f"src/*{e}" for e in JS_EXT]).stdout.splitlines():
        m = re.match(r"HEAD:(.+):(\d+)$", l)
        if m and not re.search(r"(\.spec\.|\.test\.|/__tests__/|^src/l10n/)", m.group(1)):
            lines[m.group(1)] = int(int(m.group(2)) * (1 - pct / 100.0))
    return {k: v for k, v in lines.items() if v > 0}, "thin"


def busy_units(paths, js_names=None):
    """Units that an open coverage PR's test files already work on."""
    out = set()
    for p in paths:
        m = re.match(r"tests/(?:Unit|unit)/(.+)", p)
        if m:
            lib = "lib/" + m.group(1)
            out.add(unit_of(lib)); out.add(unit_of(lib, 3))
        b = os.path.basename(p)
        if js_names and re.search(r"\.(spec|test)\.[jt]s$", b):
            stem = b.split(".")[0].lower()
            for src in js_names.get(stem, []):
                out.add(unit_of(src)); out.add(unit_of(src, 3))
    return out


def untested(c):
    """{lib php path: lines} for classes with no test file named after them."""
    files = git(c, "ls-tree", "-r", "--name-only", "HEAD").stdout.split("\n")
    tests = set()
    for f in files:
        if f.startswith("tests/") and f.endswith("Test.php"):
            tests.add(os.path.basename(f))
    names = sorted(tests)
    lines = {}
    out = git(c, "grep", "-c", "", "HEAD", "--", "lib/*.php").stdout
    for l in out.splitlines():
        m = re.match(r"HEAD:(.+):(\d+)$", l)
        if m:
            lines[m.group(1)] = int(m.group(2))
    res = {}
    for f, n in lines.items():
        if f.startswith(SKIP_DIRS):
            continue
        cls = os.path.basename(f)[:-4]
        # a test file for the class: <Class>Test.php or <Class><Aspect>Test.php
        import bisect
        i = bisect.bisect_left(names, cls)
        if i < len(names) and names[i].startswith(cls):
            continue
        res[f] = n
    return res


def lib_lines(c):
    out = {}
    for l in git(c, "grep", "-c", "", "HEAD", "--", "lib/*.php").stdout.splitlines():
        m = re.match(r"HEAD:(.+):(\d+)$", l)
        if m and not m.group(1).startswith(SKIP_DIRS):
            out[m.group(1)] = int(m.group(2))
    return out


def slices(c, u=None):
    """List of (sorted unit paths, weight): directory groups weighted by untested lines (or by the given map)."""
    u = untested(c) if u is None else u
    units = defaultdict(int)
    for f, n in u.items():
        units[unit_of(f)] += n
    final = {}
    for unit, n in units.items():
        if n > UNIT_SPLIT and unit.endswith("/"):
            for f, m in u.items():
                if unit_of(f) == unit:
                    final.setdefault(unit_of(f, 3), 0)
                    final[unit_of(f, 3)] += m
        else:
            final[unit] = n
    final = {k: v for k, v in final.items() if v >= UNIT_MIN}
    big = [([k], v) for k, v in final.items() if v >= 300]
    small = defaultdict(list)
    for k, v in final.items():
        if v < 300:
            small["/".join(k.split("/")[:2])].append((k, v))
    for _, items in sorted(small.items()):
        cur, w = [], 0
        for k, v in sorted(items):
            if w + v > SLICE_MAX and cur:
                big.append((cur, w)); cur, w = [], 0
            cur.append(k); w += v
        if cur:
            big.append((cur, w))
    return [(sorted(p), w) for p, w in sorted(big)]


# ---------------------------------------------------------------- gap rows
def gap_rows():
    try:
        text = open(GAPQ).read()
    except FileNotFoundError:
        return []
    out = []
    apps = FLEET + [OR]
    for line in text.splitlines():
        cells = [x.strip() for x in line.strip().strip("|").split("|")]
        if len(cells) < 5 or not cells[0].isdigit():
            continue
        num, who, crit, fix, size = cells[:5]
        low = (fix + " " + size).lower()
        if size.strip().lower() == "skip" or "checker side" in low or "decide with ruben" in low:
            continue
        if crit.lower().startswith(("coverage", "testcoderatio", "maintainability", "adrvalidator")):
            continue  # cover tasks, the debt sweep and the API-versioning lane own these
        for a in apps:
            m = re.search(rf"\b{a}\b([^,]*)", who)
            if m:
                out.append((num, a, crit, clean(m.group(0), 80), fix))
    return out


GAP_CRITS = ("owaspsecurecoding", "secretscanning", "publiccode", "complexity", "opensourcehealth", "docker",
             "dockerimage", "helmchart", "spdxheaders", "sbom", "license", "changelog", "security", "contributing")


def crit_key(crit):
    c = crit.lower()
    if any(w in c for w in ("docker", "helmchart")):
        return "deploy"
    m = re.match(r"[a-z]+", c)
    return m.group(0) if m else c


def score_status():
    """{(app, criterion): status letter} from the App x criterion table of SCORES-LOCAL.md."""
    st, cols = {}, None
    try:
        for line in open(SCORES):
            cells = [x.strip() for x in line.strip().strip("|").split("|")]
            if len(cells) > 3 and cells[0] == "criterion" and cells[1] == "level":
                cols = cells[2:]
            elif cols and len(cells) == len(cols) + 2 and cells[0] not in ("---", ""):
                for a, v in zip(cols, cells[2:]):
                    st[(a, cells[0])] = v[:1]
            elif cols and not line.startswith("|"):
                cols = None if line.strip() else cols
    except FileNotFoundError:
        pass
    return st


def score_findings():
    """[(app, criterion, status, text with evidence)] for actionable non-pass findings in SCORES-LOCAL.md."""
    out, app, cur = [], None, None
    try:
        lines = open(SCORES).read().splitlines()
    except FileNotFoundError:
        return out
    for line in lines + ["### end ("]:
        m = re.match(r"### (\S+) \(", line)
        f = re.match(r"- \*\*(\w+)\*\* (WARN|FAIL)[^:]*: (.*)", line)
        if m or f or (cur and not line.strip()):
            if cur:
                out.append(tuple(cur[:3]) + (clean(" ".join(cur[3]), 700),))
                cur = None
            if m:
                app = m.group(1)
            if f and app and f.group(1) in GAP_CRITS:
                cur = [app, f.group(1), f.group(2), [f.group(3)]]
        elif cur and line.strip().startswith("- `"):
            cur[3].append(line.strip()[2:])
    keep = []
    for a, c, stt, text in out:
        if c == "opensourcehealth" and not re.search(r"Vulnerabilities: \S+ (?!0 critical, 0 high, 0 moderate)", text):
            continue
        keep.append((a, c, stt, text))
    return keep


def toast_without_override(c):
    try:
        pj = json.loads(git(c, "show", "HEAD:package.json").stdout)
    except Exception:
        return False
    deps = {**(pj.get("dependencies") or {}), **(pj.get("devDependencies") or {})}
    ov = ((pj.get("overrides") or {}).get("@toast-ui/editor") or {})
    return "@toast-ui/editor" in deps and not (isinstance(ov, dict) and ov.get("dompurify"))


DOMPURIFY_FIX = ('Add to package.json "overrides": {"@toast-ui/editor": {"dompurify": "^3.4.17"}} (merge into an existing '
                 'overrides object), run `npm install --package-lock-only --ignore-scripts` so package-lock.json resolves '
                 'dompurify >= 3.4.17 under @toast-ui/editor, regenerate sbom.cdx.json with bin/regen-sbom.sh, check '
                 '`npm ls dompurify` and `npm run build`, and open the PR to development.')


# ---------------------------------------------------------------- main
# ---------------------------------------------------------------- build continuations
CONT_MAX = 8  # continuation ids :c2 .. :c8, then the change is left to a person
CONT_COOLDOWN = 6 * 3600  # a change that just came back is not requeued for 6 h ...
CONT_MERGED_MIN = 2 * 3600  # ... unless the partial's PR is no longer open (its work is on the base) and 2 h passed
CONT_APPS_TOP = int(os.environ.get("FEEDER_CONT_APPS", "0"))  # only the first N apps of app-order.txt (0: building paused)
CONT_DONE = re.compile(r"^(?:PR\b|already|done|built)", re.I)
# unchecked tasks a cloud worker cannot do in code: live instance, browser/e2e, archive, CLI validate, tracking
CONT_NONCODE = re.compile(
    r"\blive\b(?!-region)|\bon the (?:dev |live |test )?instance\b|\be2e\b|end-to-end|playwright|\bbrowser\b|screenshot|"
    r"\bnewman\b|postman|capabilities\.json|tracking[- ]issue|openspec (?:cli )?validate|openspec archive|opsx-archive|"
    r"\barchive (?:the|this) change|^(?:\d+(?:\.\d+)*[a-z]?\.?\s+)?archive\b|\bwcag-em\b|"
    r"manual(?:ly)? (?:test|check|audit|pass)|\bsmoke[- ]test|\bwait for board\b|\bdq-data\b", re.I)
CONT_CODE = re.compile(r"\blib/|\bsrc/|\.php\b|\.vue\b|phpunit|vitest|\bjest\b", re.I)


def ts(s):
    try:
        return calendar.timegm(time.strptime(s, "%Y-%m-%dT%H:%M:%SZ"))
    except (TypeError, ValueError):
        return 0


def unchecked_tasks(clone, change):
    """Unchecked task lines of openspec/changes/<change>/tasks.md at the clone's HEAD; None when it has none
    (archived or absent)."""
    if not clone:
        return None
    r = git(clone, "show", f"HEAD:openspec/changes/{change}/tasks.md")
    if r.returncode:
        return None
    return [re.sub(r"^\s*[-*]\s*\[ \]\s*", "", l) for l in r.stdout.splitlines() if re.match(r"\s*[-*]\s*\[ \]", l)]


def is_code_task(t):
    head = re.split(r"\bVerify:", t, 1)[0]
    return bool(CONT_CODE.search(head)) or not CONT_NONCODE.search(head)


def build_continuations(tasks, done_rows, prs, clones, add, now_ts=None):
    """Queue one continuation row per build change whose latest result is `partial`, or `failed` naming
    blocking changes that are all resolved since; only while tasks.md on the base still has code tasks
    unchecked. Returns (counts per app, [(id, reason)])."""
    now_ts = now_ts or time.time()
    order = [l.strip() for l in open(os.path.join(ST, "queue/app-order.txt")) if l.strip() and not l.startswith("#")]
    allowed = set(order[:CONT_APPS_TOP])
    builds = {r[0]: r for r in tasks if len(r) >= 7 and r[1] == "build"}
    done_ids = {d[0] for d in done_rows}
    key_of = {}
    for tid, r in builds.items():
        key_of[tid] = (r[2], r[4])
    rows_of, latest, n_of, open_keys, base_row = defaultdict(list), {}, defaultdict(int), set(), {}
    for tid, (a, c) in key_of.items():
        m = re.search(r":c(\d+)$", tid)
        n_of[a, c] = max(n_of[a, c], int(m.group(1)) if m else 1)
        if not m:
            base_row[a, c] = builds[tid]
        if tid not in done_ids:
            open_keys.add((a, c))
    for d in done_rows:
        if len(d) >= 4 and d[0] in key_of:
            k = key_of[d[0]]
            rows_of[k].append(d)
            if k not in latest or d[3] >= latest[k][3]:
                latest[k] = d
    apps_of = defaultdict(set)
    for a, c in key_of.values():
        apps_of[c].add(a)
    open_pr = {(a, p["number"]) for a in prs for p in prs[a]}
    tasks_cache = {}

    def unchecked(a, c):
        if (a, c) not in tasks_cache:
            tasks_cache[a, c] = unchecked_tasks(clones.get(a), c)
        return tasks_cache[a, c]

    def resolved(blocker, since):
        for a in apps_of[blocker]:
            if any(CONT_DONE.match(d[1]) and d[3] > since for d in rows_of.get((a, blocker), [])):
                return True
            u = unchecked(a, blocker)
            if u is not None and not u and git(clones[a], "cat-file", "-e", f"HEAD:openspec/changes/{blocker}/tasks.md").returncode == 0:
                return True
        return False

    counts, samples = defaultdict(int), []
    for (a, c), d in sorted(latest.items()):
        res = d[1]
        kind = res.split(":")[0].split()[0].lower() if res.strip() else ""
        if kind not in ("partial", "failed") or (a, c) in open_keys or a not in allowed:
            continue
        nxt = n_of[a, c] + 1
        if nxt > CONT_MAX or (a, c) not in base_row:
            continue
        age = now_ts - ts(d[3])
        if kind == "partial":
            prn = re.search(r"#(\d+)", res)
            pr_gone = prn and (a, int(prn.group(1))) not in open_pr
            if age < CONT_COOLDOWN and not (pr_gone and age >= CONT_MERGED_MIN):
                continue
            why = "partial, PR merged/closed" if pr_gone else "partial, cooled 24h"
        else:
            names = set(re.findall(r"[a-z0-9]+(?:-[a-z0-9]+)+", res.lower())) & set(apps_of)
            names.discard(c)
            if not names or not all(resolved(b, d[3]) for b in names):
                continue
            why = "failed, blocker resolved: " + ",".join(sorted(names))
        u = unchecked(a, c)
        if not u:
            continue  # archived, absent, or every task ticked
        code = [t for t in u if is_code_task(t)]
        if not code:
            continue
        r0 = base_row[a, c]
        tid = f"{r0[0]}:c{nxt}"
        pl = parse_payload(r0[5])
        pl.update({"cont": f"c{nxt}", "prev": d[0], "left": clean(res, 250)})
        if add(tid, "build", a, r0[3], r0[4], payload(pl)):
            counts[a] += 1
            samples.append((tid, f"{why}; {len(code)} code tasks unchecked"))
    return counts, samples


def main():
    os.makedirs(DIR, exist_ok=True)
    rest, gql = rate()
    state_sync()
    tasks = read_tsv("queue/work.tsv")
    done = {r[0] for r in read_tsv("queue/work-done.tsv")}
    live = live_leases()
    known = {r[0] for r in tasks}
    open_tasks = [r for r in tasks if r[0] not in done and len(r) >= 7]
    unclaimed = [r for r in open_tasks if task_lease(r[0]) not in live]
    now = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    prs = open_prs(FLEET + [OR])
    head = {(a, p["number"]): p["headRefOid"] for a in prs for p in prs[a]}

    fv = read_tsv("queue/fleet-verdicts.tsv")
    rows_at = defaultdict(list)
    for r in fv:
        if len(r) >= 4 and r[1].isdigit():
            rows_at[(r[0], int(r[1]), r[2])].append(r)

    new, stale = [], []

    def add(tid, ty, app, base, target, pl, age=0):
        """Queue a task once; `age` seconds back-dates it so it is claimed earlier within its type."""
        if tid in known:
            return False
        known.add(tid)
        created = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(time.time() - age)) if age else now
        new.append("\t".join([tid, ty, app, base, str(target), pl, created]))
        return True

    # stale: unclaimed tasks whose PR heads moved or closed
    for r in unclaimed:
        ty, app, pl = r[1], r[2], parse_payload(r[5])
        if ty in ("verify", "merge"):
            members = [m.split("@") for m in pl.get("prs", "").split(",") if "@" in m]
            if members and not any(head.get((app, int(n))) == s for n, s in members):
                stale.append(r[0])
        elif ty in ("fix", "review"):
            if head.get((app, int(r[4]))) != pl.get("sha"):
                stale.append(r[0])
    stale_set = set(stale)
    queued, queued_merge = set(), set()
    for r in open_tasks:
        if r[0] in stale_set:
            continue
        pl = parse_payload(r[5])
        if r[1] in ("verify", "merge"):
            for m in pl.get("prs", "").split(","):
                if "@" in m:
                    n, s = m.split("@")
                    (queued if r[1] == "verify" else queued_merge).add((r[2], int(n), s))
        elif r[1] == "fix":
            queued.add(("fix", r[2], int(r[4]), pl.get("sha")))

    counts = defaultdict(int)
    paused = 0 <= rest < REST_FLOOR

    # fix
    for a in FLEET:
        for p in prs[a]:
            n, s = p["number"], p["headRefOid"]
            if p["baseRefName"] != "development" or not mergeable_branch(p):
                continue
            at = rows_at.get((a, n, s), [])
            if not at or at[-1][3] == "REVERIFY":
                continue
            last = at[-1]
            if last[3] in ("RED", "CONFLICT", "BLOCK", "LINT"):
                again = any(x[3] == "REVERIFY" for x in at)
                if add(f"fix:{a}:{n}:{s[:8]}" + (f":v{len(at)}" if again else ""), "fix", a, "development", n,
                       payload({"sha": s, "verdict": last[3], "branch": p["headRefName"],
                                "reason": clean(last[5] if len(last) > 5 else "", 250)})):
                    counts["fix"] += 1

    # review
    verdicts = defaultdict(set)
    for l in open(os.path.join(ST, "verdicts.txt")):
        f = l.split(None, 3)
        if len(f) >= 3 and f[0].isdigit():
            verdicts[int(f[0])].add((f[1], f[2]))
    needs = {int(r[0]): r for r in read_tsv("queue/needs-review.tsv") if r and r[0].isdigit()}
    for p in prs[OR]:
        n, s = p["number"], p["headRefOid"]
        if p["baseRefName"] != "main" or p["isDraft"]:
            continue
        v = verdicts.get(n, set())
        if any(sha == s and verdict in ("APPROVE", "BLOCK") for sha, verdict in v):
            continue
        if v and n not in needs:
            continue  # reviewed before; the integrator lists it in needs-review when it needs another look
        last_ok = next((sha for sha, verdict in sorted(v) if verdict == "APPROVE"), "")
        if n in needs and len(needs[n]) > 2 and needs[n][2]:
            last_ok = needs[n][2]
        if add(f"review:{OR}:{n}:{s[:8]}", "review", OR, "main", n,
               payload({"sha": s, "last_approved": last_ok, "title": clean(p["title"], 150)})):
            counts["review"] += 1

    # review of fleet quality PRs older than 24 h with no verdict at the head and no queued verify
    cutoff = time.time() - 86400
    for a in FLEET:
        for p in prs[a]:
            n, s = p["number"], p["headRefOid"]
            if p["baseRefName"] != "development" or not mergeable_branch(p) or p["isDraft"]:
                continue
            try:
                created = calendar.timegm(time.strptime(p["createdAt"], "%Y-%m-%dT%H:%M:%SZ"))
            except Exception:
                continue
            if created > cutoff or (a, n, s) in queued or [x for x in rows_at.get((a, n, s), []) if x[3] != "REVERIFY"]:
                continue
            queued.add((a, n, s))
            if add(f"review:{a}:{n}:{s[:8]}", "review", a, "development", n,
                   payload({"sha": s, "branch": p["headRefName"], "title": clean(p["title"], 150)})):
                counts["review"] += 1
    for r in open_tasks:
        if r[1] == "review" and r[2] != OR and r[0] not in stale_set:
            queued.add((r[2], int(r[4]), parse_payload(r[5]).get("sha")))

    # verify (merging over REST: paused when the shared quota is low)
    if not paused:
        for a in FLEET:
            todo, solo = [], []
            for p in sorted(prs[a], key=lambda x: x["number"]):
                n, s = p["number"], p["headRefOid"]
                if p["baseRefName"] != "development" or not mergeable_branch(p) or p["isDraft"]:
                    continue
                at = rows_at.get((a, n, s), [])
                if (a, n, s) in queued or (at and at[-1][3] != "REVERIFY"):
                    continue
                if [x for x in at if x[3] != "REVERIFY"]:
                    # verified before at this head and sent back: alone, so a sibling cannot conflict it again
                    n_re = sum(1 for x in at if x[3] == "REVERIFY")
                    if add(f"verify:{a}:{n}:{h8(f'{n}@{s}:r{n_re}')}", "verify", a, "development", "merge",
                           payload({"prs": f"{n}@{s}"})):
                        counts["verify"] += 1
                    continue
                todo.append(f"{n}@{s}")
            for i in range(0, len(todo), VERIFY_TREE):
                tree = todo[i:i + VERIFY_TREE]
                if add(f"verify:{a}:{tree[0].split('@')[0]}:{h8(','.join(tree))}", "verify", a, "development",
                       "merge", payload({"prs": ",".join(tree)})):
                    counts["verify"] += 1

    # merge: GREEN at the current head, still open; not RED in a merge ledger at that head
    if not paused:
        red = set()
        for l in open(os.path.join(INPUTS, "fv-merged.tsv")) if os.path.exists(
                os.path.join(INPUTS, "fv-merged.tsv")) else []:
            f = l.rstrip("\n").split("\t")
            if len(f) >= 4 and f[2] == "RED":
                red.add((f[0], int(f[1]), f[3]))
        for r in read_tsv("queue/fleet-merged.tsv"):
            if len(r) >= 4 and r[3] in ("RED", "FAIL") and r[1].isdigit():
                red.add((r[0], int(r[1]), r[2]))
        stacks = defaultdict(list)
        try:
            for l in open(os.path.join(INPUTS, "fv-stacks.tsv")):
                f = l.rstrip("\n").split("\t")
                if len(f) == 2:
                    stacks[f[0]].append([int(x) for x in f[1].split(",") if x.strip().isdigit()])
        except FileNotFoundError:
            pass
        for a in FLEET:
            green, resalt = {}, set()
            for p in prs[a]:
                n, s = p["number"], p["headRefOid"]
                if p["baseRefName"] != "development" or not mergeable_branch(p) or p["isDraft"]:
                    continue
                at = rows_at.get((a, n, s), [])
                kinds = [x[3] for x in at]
                reverified = "REVERIFY" in kinds and kinds[-1] == "GREEN" and \
                    len(kinds) - 1 - kinds[::-1].index("REVERIFY") < len(kinds) - 1
                if at and kinds[-1] == "GREEN" and ((a, n, s) not in red or reverified) and (a, n, s) not in queued_merge:
                    green[n] = s
                    if reverified:
                        resalt.add(n)
            in_stack = {n for st in stacks[a] for n in st}
            order = [n for n in sorted(green) if n not in in_stack]
            groups = []
            for st in stacks[a]:
                members = [n for n in st if n in green]
                if members:
                    order += members
                    groups.append(">".join(map(str, members)))
            for i in range(0, len(order), TREE_MAX):
                tree = [f"{n}@{green[n]}" for n in order[i:i + TREE_MAX]]
                chunk = set(order[i:i + TREE_MAX])
                st = [g for g in groups if set(map(int, g.split(">"))) & chunk]
                pl = {"prs": ",".join(tree)}
                if st:
                    pl["stacks"] = " ".join(st)
                salt = ":r" if chunk & resalt else ""
                if add(f"merge:{a}:{order[i]}:{h8(','.join(tree))}{salt}", "merge", a, "development", "development", payload(pl)):
                    counts["merge"] += 1

    # gap: GAP-QUEUE rows, then SCORES-LOCAL findings, then the dompurify override; one open task per app and criterion
    status = score_status()
    open_gap = set()
    by_id = {r[0]: r for r in tasks}
    recent = set()
    for d in read_tsv("queue/work-done.tsv"):
        try:
            if len(d) >= 4 and time.time() - calendar.timegm(time.strptime(d[3], "%Y-%m-%dT%H:%M:%SZ")) < 12 * 3600:
                recent.add(d[0])
        except ValueError:
            pass
    for r in tasks:
        # open gap tasks, and gap tasks finished in the last 12 h (a rescan may not show their PR yet)
        if r[1] == "gap" and r[0] not in stale_set and (r[0] not in done or r[0] in recent):
            open_gap.add((r[2], crit_key(parse_payload(r[5]).get("criterion", ""))))

    def passing(a, ck):
        names = ("docker", "dockerimage", "helmchart") if ck == "deploy" else (ck,)
        got = [status.get((a, x)) for x in names if status.get((a, x))]
        return bool(got) and all(x in ("P", "i") for x in got)

    for num, a, crit, who, fix in gap_rows():
        ck = crit_key(crit)
        if (a, ck) in open_gap or passing(a, ck):
            continue
        if add(f"gap:{a}:{num}:{h8(crit + fix)}", "gap", a, BASE[a], num,
               payload({"criterion": clean(crit, 80), "row": who, "fix": clean(fix, 700)})):
            counts["gap"] += 1; open_gap.add((a, ck))
    for a, crit, stt, text in score_findings():
        ck = crit_key(crit)
        if a not in BASE or (a, ck) in open_gap:
            continue
        if add(f"gap:{a}:{crit}:{h8(text)}", "gap", a, BASE[a], crit,
               payload({"criterion": f"{crit} {stt}", "row": a,
                        "fix": f"Make the checker criterion {crit} pass for this app; findings: {text}"})):
            counts["gap"] += 1; open_gap.add((a, ck))

    # cover: PHP (lib/) and JS (src/) slices per app whose suite is below the target
    cov_log = []
    clones, js_names, trees = {}, {}, {}
    for a in FLEET + [OR]:
        clones[a] = clone_base(a)

    # build: continue changes whose last build came back partial, or failed on a blocker that has landed since
    cont, cont_samples = build_continuations(tasks, read_tsv("queue/work-done.tsv"), prs, clones, add)
    counts["build"] += sum(cont.values())
    if cont:
        log(f"{'DRY ' if DRY else ''}continuations {dict(cont)} e.g. " + " | ".join(f"{t} ({w})" for t, w in cont_samples[:3]))
    for a in FLEET:
        c = clones[a]
        if c and (a, "dompurify") not in open_gap and toast_without_override(c):
            if add(f"gap:{a}:dompurify:1", "gap", a, "development", "dompurify",
                   payload({"criterion": "opensourcehealth dompurify override", "row": a, "fix": DOMPURIFY_FIX})):
                counts["gap"] += 1

    need_measure = set()

    def lang_of(r):
        return parse_payload(r[5]).get("lang", "php")

    # Slices whose cover task ended "already ..." in the last 24 h: requeueing them only burns a worker slot.
    cooled = defaultdict(set)
    payload_of = {r[0]: r[5] for r in tasks if len(r) >= 7 and r[1] == "cover"}
    for d in read_tsv("queue/work-done.tsv"):
        if len(d) >= 4 and d[0] in payload_of and d[1].startswith(("already", "nothing", "no ")):
            try:
                recent = time.time() - calendar.timegm(time.strptime(d[3], "%Y-%m-%dT%H:%M:%SZ")) < 86400
            except ValueError:
                recent = False
            if recent:
                cooled[d[0].split(":")[1]] |= set(parse_payload(payload_of[d[0]]).get("dirs", "").split(","))

    def cover_pass(target, tag):
        for a in FLEET + [OR]:
            c = clones[a]
            if not c:
                cov_log.append(f"{a}:noclone"); continue
            pcts, gen = coverage_of(c)
            m = measured(a)
            if m:
                for lang in ("php", "js"):
                    if (m.get(lang) or {}).get("pct") is not None:
                        pcts[lang] = float(m[lang]["pct"])
                gen = gen + "m" + h8(m.get("measured_at", ""))[:4]
            tree = trees.setdefault(a, set(git(c, "ls-tree", "-r", "--name-only", "HEAD").stdout.split("\n")))
            for lang in ("php", "js"):
                pct = pcts.get(lang)
                if pct is None or pct >= target:
                    continue
                if a not in js_names:
                    names = defaultdict(list)
                    for f in tree:
                        if f.startswith("src/") and f.endswith(JS_EXT):
                            names[os.path.basename(f).split(".")[0].lower()].append(f)
                    js_names[a] = names
                    covpr = [p["number"] for p in prs[a] if p["headRefName"].startswith(("test/coverage-", "quality/test-coverage-"))]
                    busy = set()
                    for paths in pr_files(a, covpr).values():
                        busy |= busy_units(paths, names)
                    js_names[a, "busy"] = busy
                busy = js_names[a, "busy"]
                taken = set()
                for r in tasks + [l.split("\t") for l in new]:
                    if r[1] == "cover" and r[2] == a and (r[0].split(":")[2] == gen or r[0] in known and r[0] not in done):
                        taken |= set(parse_payload(r[5]).get("dirs", "").split(","))
                have = len([r for r in open_tasks if r[1] == "cover" and r[2] == a and lang_of(r) == lang and r[0] not in stale_set]) \
                    + len([l for l in new if l.split("\t")[1] == "cover" and l.split("\t")[2] == a and f"lang={lang}" in l])
                cap = max(4, int((target - pct) * 3 + 0.999)) - have
                real = measured_weights(a, lang, tree)
                if lang == "php":
                    w = real or php_uncovered(a, tree)
                    cands = [(p, x, "uncovered") for p, x in sorted(slices(c, w), key=lambda y: -y[1])] if w else \
                        [(p, x, "untested") for p, x in sorted(slices(c), key=lambda y: -y[1])]
                    used = {x for p, _, _ in cands for x in p}
                    cands += [(p, x, "thin") for p, x in sorted(slices(c, lib_lines(c)), key=lambda y: -y[1]) if not used & set(p)]
                else:
                    w, focus = (real, "uncovered") if real else js_uncovered(a, c, tree, pct)
                    cands = [(p, x, focus) for p, x in sorted(slices(c, w), key=lambda y: -y[1])] if w else []
                if not w or (lang == "js" and focus == "thin"):
                    need_measure.add(a)
                made = 0
                for paths, wt, focus in cands:
                    if made >= cap:
                        break
                    if busy & set(paths) or taken & set(paths) or cooled[a] & set(paths):
                        continue
                    key = ("js" if lang == "js" else "") + h8(",".join(paths))
                    pfx = ("test/coverage-" if a == OR else "quality/test-coverage-") + ("js-" if lang == "js" else "")
                    # COVER_FIRST apps in that order, then by gap to the target; heavier slices first within an app
                    rank = (len(COVER_FIRST) - COVER_FIRST.index(a)) if a in COVER_FIRST else 0
                    age = rank * 30 * 86400 + int((target - pct) * 3600) + min(wt, 3000)
                    if add(f"cover:{a}:{gen}:{key}", "cover", a, BASE[a], pfx + h8(",".join(paths)),
                           payload({"lang": lang, "dirs": ",".join(paths), "focus": focus, "weight": wt,
                                    "coverage": pct, "target": target}), age):
                        counts["cover"] += 1; made += 1; taken |= set(paths)
                if made == 0:
                    need_measure.add(a)  # every slice of this data is used: fresh figures restart the flow
                cov_log.append(f"{a}:{lang}{pct}:+{made}{tag}")

    cover_pass(COVER_TARGET, "")

    def non_merge_open():
        n = len([r for r in open_tasks if r[1] != "merge" and r[0] not in stale_set])
        return n + len([l for l in new if l.split("\t")[1] != "merge"])

    if non_merge_open() < MIN_OPEN:
        cover_pass(HEADROOM_TARGET, "h")

    # measure: an app sliced without real per-directory data, or out of slices, gets a measurement every 6 h at most
    day = time.strftime("%Y%m%d", time.gmtime()) + f"h{time.gmtime().tm_hour // 2 * 2:02d}"
    done_at = {d[0]: d[3] for d in read_tsv("queue/work-done.tsv") if len(d) >= 4}

    def within(tid, secs):
        try:
            return time.time() - calendar.timegm(time.strptime(done_at[tid], "%Y-%m-%dT%H:%M:%SZ")) < secs
        except (KeyError, ValueError):
            return False
    measuring = {r[2] for r in tasks if r[1] == "measure" and (r[0] not in done or within(r[0], 2 * 3600))}
    for a in sorted(need_measure):
        if a in measuring:
            continue
        rank = (len(COVER_FIRST) - COVER_FIRST.index(a)) if a in COVER_FIRST else 0
        if add(f"measure:{a}:{day}", "measure", a, BASE[a], f"queue/coverage/{a}.json",
               payload({"why": "slices need per-directory coverage"}), rank * 3600):
            counts["measure"] += 1

    if os.environ.get("FEEDER_SHOW"):
        print("\n".join(new))
    if not DRY:
        st_append("queue/work.tsv", new)
        st_append("queue/work-done.tsv", [f"{t}\tstale\tfeeder\t{now}" for t in stale])
    depth = defaultdict(int)
    for r in open_tasks:
        if r[0] not in stale_set:
            depth[r[1]] += 1
    for l in new:
        depth[l.split("\t")[1]] += 1
    total = sum(depth.values())
    nonmerge = total - depth.get("merge", 0)
    log(f"{'DRY ' if DRY else ''}added {dict(counts)} stale={len(stale)} open={total} by-type={dict(depth)} "
        f"rest={rest} graphql={gql}{' verify-paused' if paused else ''}"
        f" non-merge={nonmerge}{' BELOW-' + str(MIN_OPEN) + ' (no more real work found)' if nonmerge < MIN_OPEN else ''}")
    log("coverage " + " ".join(cov_log))


def continue_only():
    """`--continue-only`: just the build continuation pass (with --dry-run: print, write nothing)."""
    state_sync()
    tasks = read_tsv("queue/work.tsv")
    known = {r[0] for r in tasks}
    prs = open_prs(FLEET + [OR])
    clones = {a: os.path.join(CLONES, a) for a in FLEET + [OR] if os.path.isdir(os.path.join(CLONES, a, ".git"))}
    now = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    new = []

    def add(tid, ty, app, base, target, pl, age=0):
        if tid in known:
            return False
        known.add(tid)
        new.append("\t".join([tid, ty, app, base, str(target), pl, now]))
        return True
    counts, samples = build_continuations(tasks, read_tsv("queue/work-done.tsv"), prs, clones, add)
    for a, n in sorted(counts.items(), key=lambda x: -x[1]):
        print(f"{a}\t{n}")
    print(f"total\t{sum(counts.values())}")
    for t, w in samples[:int(os.environ.get("FEEDER_CONT_SHOW", "10"))]:
        print(f"  {t}  <- {w}")
    if not DRY:
        ok = st_append("queue/work.tsv", new)
        log(f"continue-only queued {len(new) if ok else 0} build continuations {dict(counts)}")


if __name__ == "__main__":
    try:
        continue_only() if "--continue-only" in sys.argv else main()
    except Exception as e:
        log(f"ERROR {type(e).__name__}: {e}")
        sys.exit(1)
