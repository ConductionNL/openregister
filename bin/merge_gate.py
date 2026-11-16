#!/usr/bin/env python3
"""Merge gate for one fleet PR head, run before a GREEN PR is merged into development.

    python3 bin/merge_gate.py <app-clone> <pr-number> [<head-sha>] [--base development]

Three checks. SBOM and checker run on the base branch with the PR head merged in (the tree
that would land); the maintainability floor runs on the PR head against its merge base:
  1. SBOM: a head that changes composer.lock or package-lock.json must bring a root
     sbom.cdx.json that matches them (bin/regen-sbom.sh --check, next to this file).
  2. Checker floor: no Common Ground checker criterion or score may get worse against the
     .checker-floor.json committed at the merge base (checker_floor.py compare).
  3. Maintainability floor: no rating may drop more than 0.01 against the merge base
     (floor_check.py compare).
  4. Absolute floor: the overall maintainability lower bound of the tree that would land may
     not be below the app's row in floors.tsv next to bin/ (app<TAB>lower bound), so the
     per-PR tolerance cannot add up to drift. It is measured with the local checker's own
     estimator, invoked as the checker invokes it, because floors.tsv holds checker numbers:
     floor_check.py's estimator (--components-from-scope) reads 0.3 to 1.0 higher on the same
     commit. An app without a row has no absolute floor.
Portable: runs the same in a local runner and in a cloud session. floors.tsv is read next to bin/, else
from config/floors.tsv. The estimator is MERGE_GATE_CHECKER, else ~/sig-lanes/scan/checker when it exists,
else a clone of the public checker repository at ESTIMATOR_REF (the commit the local runners measure with)
in the gate cache. Gate wall times go to logs/gate-times.log next to bin/ only when that directory exists.
checker_floor.py and floor_check.py come from ConductionNL/.github main (checker_floor.py
from its feat/checker-floor branch while main lacks it), fetched over git, no API calls.

Last line: `GATE OK|RED|UNMEASURED <reason>`. Exit 0 OK (merge), 1 RED (record it, do not
merge), 2 UNMEASURED (do not merge, record why). A gate that cannot run never passes.
"""
import argparse, json, os, subprocess, sys, tempfile, time

# Node's built-in fetch ignores HTTPS_PROXY unless NODE_USE_ENV_PROXY is set. In a cloud session
# direct egress has no DNS, so without this the checker's ADR ruleset download fails (ENOTFOUND)
# and the checker floor ends UNMEASURED even with the host allowlisted. Every child inherits it.
if os.environ.get("HTTPS_PROXY") or os.environ.get("https_proxy"):
    os.environ.setdefault("NODE_USE_ENV_PROXY", "1")

HERE = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.expanduser("~/.cache/hydra-gates")
DOTGITHUB = os.path.join(CACHE, "dotgithub")
LIB = "hydra-gates/scripts/lib"
REGEN = os.path.join(HERE, "regen-sbom.sh")
LOCKFILES = ("composer.lock", "package-lock.json")
FLOORS = next((f for f in (os.path.join(os.path.dirname(HERE), "floors.tsv"),
                            os.path.join(os.path.dirname(HERE), "config", "floors.tsv")) if os.path.isfile(f)),
              os.path.join(os.path.dirname(HERE), "floors.tsv"))
CHECKER_REPO = "https://github.com/rubenvdlinde/CommonGroundChecker"
ESTIMATOR_REF = "aa578398248c36aeb9f3d4620772c78993f41c52"
CHECKER = os.environ.get("MERGE_GATE_CHECKER") or next(
    (d for d in (os.path.expanduser("~/sig-lanes/scan/checker"),) if os.path.isdir(d)),
    os.path.join(CACHE, f"estimator-checker-{ESTIMATOR_REF[:12]}"))
ESTIMATOR = os.path.join(CHECKER, "scripts", "maintainability", "check_maintainability.py")


def estimator_checkout():
    """Clone the checker at ESTIMATOR_REF into the cache when CHECKER is that cache dir and missing."""
    if os.path.isfile(ESTIMATOR) or not CHECKER.startswith(CACHE):
        return
    tmp = f"{CHECKER}.{os.getpid()}"
    if git(CACHE, "init", "-q", tmp).returncode == 0 and \
            git(tmp, "fetch", "-q", "--depth", "1", CHECKER_REPO, ESTIMATOR_REF).returncode == 0 and \
            git(tmp, "checkout", "-q", "--detach", "FETCH_HEAD").returncode == 0:
        try:
            os.rename(tmp, CHECKER)
        except OSError:
            pass
    subprocess.run(["rm", "-rf", tmp])


def run(cmd, cwd=None, env=None):
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, env=env)


GITLEAKS_VERSION = "8.30.0"  # the version the local runners scan with


def ensure_gitleaks():
    """Put gitleaks on PATH for the checker's secretscanning criterion; a cloud session has none.

    Installs the release binary into the gate cache once. Without it the criterion reads
    "not measured" and the gate is UNMEASURED, so a failed download changes nothing."""
    import shutil
    if shutil.which("gitleaks") or os.path.isfile("/usr/local/bin/gitleaks"):
        return
    bindir = os.path.join(CACHE, "bin")
    exe = os.path.join(bindir, "gitleaks")
    if not os.path.isfile(exe):
        os.makedirs(bindir, exist_ok=True)
        url = (f"https://github.com/gitleaks/gitleaks/releases/download/v{GITLEAKS_VERSION}/"
               f"gitleaks_{GITLEAKS_VERSION}_linux_x64.tar.gz")
        tmp = f"{exe}.{os.getpid()}.tgz"
        if run(["curl", "-fsSL", "-o", tmp, url]).returncode == 0 and \
                run(["tar", "-xzf", tmp, "-C", bindir, "gitleaks"]).returncode == 0:
            os.chmod(exe, 0o755)
        subprocess.run(["rm", "-f", tmp])
    if os.path.isfile(exe):
        os.environ["PATH"] = bindir + os.pathsep + os.environ.get("PATH", "")


def canonical_origin(url):
    """https://github.com/<owner>/<repo> for an origin URL, also when a session's git proxy serves it
    (e.g. http://proxy@127.0.0.1:port/git/<owner>/<repo>): the checker names the repository after it."""
    parts = [x for x in url.rstrip("/").split("/") if x]
    if "://" not in url and not url.startswith("git@") or len(parts) < 2:
        return url
    owner, repo = parts[-2].split(":")[-1], parts[-1]
    repo = repo[:-4] if repo.endswith(".git") else repo
    return f"https://github.com/{owner}/{repo}"


def scan_env(d, want):
    """Environment for the checker: without the user and system git config when an insteadOf rule
    rewrites the origin URL away from github.com (git remote get-url expands insteadOf)."""
    got = git(d, "remote", "get-url", "origin").stdout.strip()
    if not want or got == want:
        return None
    return {**os.environ, "GIT_CONFIG_GLOBAL": "/dev/null", "GIT_CONFIG_NOSYSTEM": "1"}


def git(cwd, *a):
    return run(["git", *a], cwd=cwd)


def tail(p):
    lines = (p.stdout + p.stderr).strip().splitlines()
    return lines, (lines[-1] if lines else "")



def with_full_scan(path):
    """A copy of checker_floor.py that scans with --full when it reads star ratings.

    checker_floor.py takes the stars from report.results, which scan-local.ts only writes
    with --full; without it every star of a floor that holds stars reads "missing". The
    copy keeps its scans in their own cache dir, since cached scans lack the report.
    """
    if not path:
        return path
    src = open(path).read()
    if "starRating" not in src or '"--full"' in src:
        return path
    old_cmd = 'tree, "--scoring", scoring, "--out", out]'
    old_dir = 'return os.path.join(base, "checker")'
    if old_cmd not in src or old_dir not in src:
        return path
    out = os.path.join(os.path.dirname(path), "checker_floor_full.py")
    patched = src.replace(old_cmd, 'tree, "--scoring", scoring, "--out", out, "--full"]').replace(
        old_dir, 'return os.path.join(base, "checker-full")')
    if not os.path.isfile(out) or open(out).read() != patched:
        tmp = f"{out}.{os.getpid()}"
        with open(tmp, "w") as fh:
            fh.write(patched)
        os.replace(tmp, out)
    return out


def tools():
    """(floor_check.py, checker_floor.py) paths, either None when unavailable."""
    if not os.path.isdir(os.path.join(DOTGITHUB, ".git")):
        os.makedirs(CACHE, exist_ok=True)
        git(CACHE, "clone", "-q", "--depth", "1", "--filter=blob:none", "--sparse", "--no-checkout",
            "https://github.com/ConductionNL/.github", DOTGITHUB)
        git(DOTGITHUB, "sparse-checkout", "set", LIB)
    # Concurrent gates share this checkout: refresh it at most every 6 h, under an exclusive lock,
    # while every running gate holds a shared one (taken in main).
    stamp = os.path.join(CACHE, ".merge3-refreshed")
    import time, fcntl
    if not os.path.exists(stamp) or time.time() - os.path.getmtime(stamp) > 6 * 3600:
        fcntl.flock(LOCKFD, fcntl.LOCK_EX)
        if not os.path.exists(stamp) or time.time() - os.path.getmtime(stamp) > 6 * 3600:
            if git(DOTGITHUB, "fetch", "-q", "--depth", "1", "origin", "main").returncode == 0:
                git(DOTGITHUB, "checkout", "-q", "--detach", "FETCH_HEAD")
            open(stamp, "w").close()
        fcntl.flock(LOCKFD, fcntl.LOCK_SH)
    floor = os.path.join(DOTGITHUB, LIB, "floor_check.py")
    floor = floor if os.path.isfile(floor) else None
    checker = os.path.join(DOTGITHUB, LIB, "checker_floor.py")
    if os.path.isfile(checker):
        return floor, checker
    side = os.path.join(CACHE, "checker-floor-branch")
    os.makedirs(side, exist_ok=True)
    have = all(os.path.isfile(os.path.join(side, n)) for n in ("checker_floor.py", "checker_scoring.json"))
    # Concurrent gates share this directory: keep a recent copy, and replace files atomically.
    if have and time.time() - os.path.getmtime(os.path.join(side, "checker_floor.py")) < 6 * 3600:
        return floor, os.path.join(side, "checker_floor.py")
    if git(DOTGITHUB, "fetch", "-q", "--depth", "1", "origin", "feat/checker-floor").returncode:
        return floor, (os.path.join(side, "checker_floor.py") if have else None)
    for name in ("checker_floor.py", "checker_scoring.json"):
        r = git(DOTGITHUB, "show", f"FETCH_HEAD:{LIB}/{name}")
        if r.returncode:
            return floor, (os.path.join(side, "checker_floor.py") if have else None)
        tmp = os.path.join(side, f".{name}.{os.getpid()}")
        with open(tmp, "w") as fh:
            fh.write(r.stdout)
        os.replace(tmp, os.path.join(side, name))
    return floor, os.path.join(side, "checker_floor.py")


def sbom(head, base):
    names = git(head, "diff", "--name-only", f"{base}...HEAD").stdout.split()
    locks = [f for f in LOCKFILES if f in names]
    if not locks:
        return "OK", "no lockfile change"
    if "sbom.cdx.json" not in names:
        return "RED", f"sbom: {', '.join(locks)} changed without sbom.cdx.json"
    p = run(["bash", REGEN, "--check", head])
    _, last = tail(p)
    if p.returncode == 0:
        return "OK", last
    if p.returncode == 1:
        return "RED", "sbom: sbom.cdx.json does not match " + ", ".join(locks)
    return "UNMEASURED", f"regen-sbom exit {p.returncode}: {last}"


# Hosts the checker's network-bound criteria read. A session whose egress blocks one of them cannot measure
# that criterion; it then reads worse (opensourcehealth rates fewer properties) or "not measured" (adrvalidator).
CRITERION_HOSTS = {
    "opensourcehealth": ("https://api.osv.dev/v1/vulns/GHSA-0000-0000-0000", "https://repo.packagist.org/p2/composer/composer.json",
                         "https://registry.npmjs.org/npm", "https://pypi.org/pypi/pip/json"),
    "adrvalidator": ("https://static.developer.overheid.nl/adr/ruleset.yaml", "https://raw.githubusercontent.com/ConductionNL/.github/main/README.md"),
}


def unreachable(criterion):
    """Hosts of a criterion this session cannot reach (a proxy refusal or no answer within 8 s).
    Any HTTP answer from the host itself, a 404 included, counts as reachable."""
    import urllib.request, urllib.error
    from urllib.parse import urlparse
    bad = []
    for url in CRITERION_HOSTS.get(criterion, ()):
        try:
            urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "merge-gate-probe"}), timeout=8).close()
        except urllib.error.HTTPError as e:
            if e.code in (403, 407):
                bad.append(urlparse(url).netloc)
        except Exception:
            bad.append(urlparse(url).netloc)
    return bad


def checker(tool, head, base, env=None):
    p = run([sys.executable, tool, "compare", head, "--base-ref", base], env=env)
    lines, last = tail(p)
    # "did not measure" is mostly a transient fetch failure (seen locally too): scan once more without the
    # scan cache, which would otherwise hand back the same unmeasured scan of this tree commit.
    if p.returncode == 2 and "did not measure" in last:
        with tempfile.TemporaryDirectory(prefix="checker-rescan-") as fresh:
            p = run([sys.executable, tool, "compare", head, "--base-ref", base],
                    env={**(env or os.environ), "HYDRA_GATE_FLOOR_CACHE": fresh})
        lines, last = tail(p)
    if p.returncode in (0, 3, 4):
        return "OK", last
    if p.returncode == 1:
        worse = [l.strip()[6:] for l in lines if l.strip().startswith("WORSE ")]
        why = "; ".join(worse)
        # Only network-bound criteria got worse: a blocked registry is not the PR's doing.
        crits = {w.split(":")[0].split()[0] for w in worse}
        if worse and crits <= set(CRITERION_HOSTS):
            bad = sorted({h for c in crits for h in unreachable(c)})
            if bad:
                return "UNMEASURED", f"checker floor: {why} while {', '.join(bad)} is unreachable from this session"
        return "RED", "checker floor: " + (why or last or "worse")
    if "did not measure" in last:
        bad = sorted({h for c in CRITERION_HOSTS if c in last for h in unreachable(c)})
        if bad:
            last += f" ({', '.join(bad)} unreachable from this session)"
    return "UNMEASURED", f"checker_floor exit {p.returncode}: {last}"


def floor(tool, head, base):
    p = run([sys.executable, tool, "compare", head, "--base-ref", base])
    lines, last = tail(p)
    if p.returncode in (0, 4):
        return "OK", last
    if p.returncode == 1:
        drops = "; ".join(l.strip()[5:] for l in lines if l.strip().startswith("DROP "))
        return "RED", "floor: " + (drops or last or "lowered")
    return "UNMEASURED", f"floor_check exit {p.returncode}: {last}"


def app_floor(app):
    """The app's absolute maintainability floor from FLOORS, or None without a row."""
    try:
        with open(FLOORS) as fh:
            for line in fh:
                parts = line.rstrip("\n").split("\t")
                if len(parts) >= 2 and parts[0] == app:
                    return float(parts[1])
    except OSError:
        return None
    return None


def absolute(tree, app):
    want = app_floor(app)
    if want is None:
        return "OK", f"no absolute floor for {app}"
    estimator_checkout()
    if not os.path.isfile(ESTIMATOR):
        return "UNMEASURED", f"absolute floor: estimator not found at {ESTIMATOR}"
    with tempfile.TemporaryDirectory(prefix="abs-floor-") as tmp:
        out = os.path.join(tmp, "report.json")
        p = run([sys.executable, ESTIMATOR, "report", os.path.abspath(tree), "--json", out], cwd=CHECKER)
        _, last = tail(p)
        try:
            with open(out) as fh:
                got = float(f"{json.load(fh)['rating']['overall']['range'][0]:.2f}")
        except (OSError, ValueError, KeyError, TypeError, IndexError):
            return "UNMEASURED", f"absolute floor: estimator exit {p.returncode}: {last}"
    if got < want - 1e-9:
        return "RED", f"absolute floor: {app} maintainability {got:.2f} after merge is below its floor {want:.2f}"
    return "OK", f"absolute floor {want:.2f} holds at {got:.2f}"


TIMES = []


def timed(name, fn):
    """Run one check and note its wall time for the gate-times log."""
    t = time.time()
    r = fn()
    TIMES.append(f"{name}={time.time() - t:.0f}s")
    return r


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("clone")
    ap.add_argument("pr")
    ap.add_argument("sha", nargs="?")
    ap.add_argument("--base", default="development")
    a = ap.parse_args()
    c = os.path.abspath(a.clone)
    if git(c, "fetch", "-q", "origin", f"+refs/heads/{a.base}:refs/remotes/origin/{a.base}",
           f"+refs/pull/{a.pr}/head:refs/gate/{a.pr}").returncode:
        return "UNMEASURED", "fetch failed"
    head_sha = git(c, "rev-parse", f"refs/gate/{a.pr}").stdout.strip()
    if a.sha and not head_sha.startswith(a.sha[:12]):
        return "UNMEASURED", f"head moved to {head_sha[:10]}"
    base = git(c, "rev-parse", f"origin/{a.base}").stdout.strip()
    floor_tool, checker_tool = tools()
    checker_tool = with_full_scan(checker_tool)
    if not floor_tool or not checker_tool:
        return "UNMEASURED", "floor_check.py or checker_floor.py not available"
    url = git(c, "remote", "get-url", "origin").stdout.strip()
    url = canonical_origin(url) if url else url
    ensure_gitleaks()
    app = os.path.basename(url or c)
    app = app[:-4] if app.endswith(".git") else app
    with tempfile.TemporaryDirectory(prefix="merge-gate-") as tmp:
        # Two checkouts: the head alone (maintainability floor) and the tree that would land
        # (SBOM, checker, absolute floor); the four checks run at once, the verdict is the first
        # failing one in this order.
        dirs = {}
        for name in ("head", "tree"):
            d = os.path.join(tmp, name)
            if git(tmp, "clone", "-q", "--shared", "--no-checkout", c, d).returncode or \
                    git(d, "checkout", "-q", "--detach", head_sha).returncode:
                return "UNMEASURED", f"cannot check out {head_sha[:10]}"
            git(d, "fetch", "-q", c, f"+refs/remotes/origin/{a.base}:refs/remotes/origin/{a.base}")
            # The checker names the repository after the origin URL; a local-path origin hides the
            # published image references (dockerimage criterion) of the real repository.
            if url:
                git(d, "remote", "set-url", "origin", url)
            dirs[name] = d
        head, tree = dirs["head"], dirs["tree"]
        # SBOM and checker run on the tree that would land: the base with the PR merged in.
        # A head scanned alone lacks what the base gained after it branched off.
        m = git(tree, "-c", "user.name=merge-gate", "-c", "user.email=merge-gate@localhost",
                "merge", "-q", "--no-ff", "--no-edit", base)
        if m.returncode:
            return "UNMEASURED", f"{head_sha[:10]} does not merge cleanly onto {a.base}"
        # Maintainability is compared on the head alone against its merge base, which is
        # measured once per branch point and cached; the base moves after every merge.
        checks = (("floor", lambda: floor(floor_tool, head, base)), ("sbom", lambda: sbom(tree, base)),
                  ("checker", lambda: checker(checker_tool, tree, base, scan_env(tree, url))), ("absolute", lambda: absolute(tree, app)))
        from concurrent.futures import ThreadPoolExecutor
        with ThreadPoolExecutor(max_workers=len(checks)) as pool:
            futures = [pool.submit(timed, name, check) for name, check in checks]
            results = [f.result() for f in futures]
        for v, why in results:
            if v != "OK":
                return v, why
    return "OK", f"sbom, checker floor and maintainability floor hold; {why}"


if __name__ == "__main__":
    import fcntl
    os.makedirs(CACHE, exist_ok=True)
    LOCKFD = open(os.path.join(CACHE, ".merge3.lock"), "w")
    fcntl.flock(LOCKFD, fcntl.LOCK_SH)
    t0 = time.time()
    verdict, reason = main()
    try:
        if not os.path.isdir(os.path.join(os.path.dirname(HERE), "logs")):
            raise OSError("no logs dir")
        with open(os.path.join(os.path.dirname(HERE), "logs", "gate-times.log"), "a") as fh:
            fh.write(f"{time.strftime('%H:%M:%S', time.gmtime())}\t{sys.argv[1].rstrip('/').split('/')[-1]}\t{sys.argv[2]}\t{verdict}\ttotal={time.time() - t0:.0f}s\t{' '.join(TIMES)}\n")
    except OSError:
        pass
    print(f"GATE {verdict} {reason}")
    sys.exit({"OK": 0, "RED": 1}.get(verdict, 2))
