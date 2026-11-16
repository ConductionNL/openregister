#!/usr/bin/env python3
"""apply.py <clone> <plan.tsv>: apply the 'apply' rows, minors first in one go per ecosystem/dir,
then every major alone with a quick check; write results to <clone>/../<app>.applied.tsv.

A row whose install fails or does not land on the target version is marked failed and reverted.
"""
import json, os, subprocess, sys

clone, planf = sys.argv[1], sys.argv[2]
app = os.path.basename(clone.rstrip("/"))
LOG = os.path.join(os.path.dirname(os.path.abspath(clone)), "logs", f"{app}.apply.log")
NPM = os.environ.get("DEPBUNDLE_NPM", "npm")
MANIFESTS = ["composer.json", "composer.lock", "package.json", "package-lock.json"]
rows = [l.rstrip("\n").split("\t") for l in open(planf) if l.strip()]


def sh(cmd, cwd):
    with open(LOG, "a") as fh:
        fh.write(f"\n$ (cd {cwd}) {' '.join(cmd)}\n")
        fh.flush()
        r = subprocess.run(cmd, cwd=cwd, stdout=fh, stderr=subprocess.STDOUT,
                           env={**os.environ, "COMPOSER_NO_INTERACTION": "1"})
    return r.returncode


def lockv(eco, cwd, pkg):
    try:
        if eco == "composer":
            lk = json.load(open(os.path.join(cwd, "composer.lock")))
            for p in lk.get("packages", []) + lk.get("packages-dev", []):
                if p["name"] == pkg:
                    return p["version"].lstrip("v")
        else:
            lk = json.load(open(os.path.join(cwd, "package-lock.json")))
            return (lk["packages"].get(f"node_modules/{pkg}") or {}).get("version")
    except (OSError, ValueError, KeyError):
        return None


def vt(v):
    import re
    return tuple(int(x) for x in re.findall(r"\d+", v)[:3]) or (0,)


def snapshot(cwd):
    return {f: open(os.path.join(cwd, f)).read() for f in MANIFESTS if os.path.isfile(os.path.join(cwd, f))}


def restore(cwd, snap):
    for f, s in snap.items():
        open(os.path.join(cwd, f), "w").write(s)


def prefix(spec):
    s = (spec or "").strip()
    return "~" if s.startswith("~") else "^"


def composer(cwd, items, maj):
    for r in items:
        pkg, to, sect, spec = r[3], r[5], r[9], r[10]
        if maj:
            flag = ["--dev"] if sect == "require-dev" else []
            if sh(["composer", "require", *flag, "--no-update", f"{pkg}:^{to}"], cwd):
                return False
    args = []
    for r in items:
        args += [r[3], "--with", f"{r[3]}:{r[5]}"]
    return sh(["composer", "update", *args, "-W", "--no-install", "--no-scripts", "--no-plugins"], cwd) == 0


def npm(cwd, items, extra=()):
    ok = True
    for sect, flag in (("dependencies", []), ("devDependencies", ["-D"]), ("optionalDependencies", ["-O"]),
                       ("peerDependencies", ["--save-peer"])):
        specs = [f"{r[3]}@{r[5].lstrip('v')}" for r in items if r[9] == sect]
        if not specs:
            continue
        cmd = [NPM, "install", "--package-lock-only", "--ignore-scripts", "--no-audit", "--no-fund", *flag, *extra, *specs]
        if sh(cmd, cwd) and sh(cmd + ["--min-release-age=0"], cwd):
            ok = False
    # a lone transitive bump (no manifest section) is pulled up with npm update
    trans = [r for r in items if not r[9]]
    for r in trans:
        if sh([NPM, "update", "--package-lock-only", "--ignore-scripts", "--no-audit", "--no-fund", r[3]], cwd) and \
                sh([NPM, "update", "--package-lock-only", "--ignore-scripts", "--no-audit", "--no-fund", "--min-release-age=0", r[3]], cwd):
            ok = False
    return ok


def apply(items, maj):
    out = {}
    groups = {}
    for r in items:
        groups.setdefault((r[1], r[2]), []).append(r)
    for (eco, d), its in groups.items():
        cwd = os.path.join(clone, d.strip("/"))
        snap = snapshot(cwd)
        ok = composer(cwd, its, maj) if eco == "composer" else npm(cwd, its)
        if not ok and len(its) > 1:
            restore(cwd, snap)
            # fall back to one at a time
            for r in its:
                s1 = snapshot(cwd)
                ok1 = composer(cwd, [r], maj) if eco == "composer" else npm(cwd, [r])
                if not ok1:
                    restore(cwd, s1)
                    out[id(r)] = "failed: install did not resolve"
            ok = True
        elif not ok:
            restore(cwd, snap)
            out[id(its[0])] = "failed: install did not resolve"
        for r in its:
            if id(r) in out:
                continue
            got = lockv(eco, cwd, r[3])
            out[id(r)] = ("applied" if got == r[5].lstrip("v") else f"applied ({got})") if got and vt(got) >= vt(r[5]) \
                else f"failed: landed on {got}, wanted {r[5]}"
    return out


PEER = os.path.join(os.path.dirname(os.path.abspath(__file__)), "peercheck.js")


def peers(lockfile):
    r = subprocess.run(["node", PEER, lockfile], capture_output=True, text=True)
    return set(tuple(l.split("\t")) for l in r.stdout.splitlines() if l.strip())


def direct(cwd, pkg):
    m = json.load(open(os.path.join(cwd, "package.json")))
    return any(pkg in m.get(k, {}) for k in ("dependencies", "devDependencies", "optionalDependencies", "peerDependencies"))


apply_rows = [r for r in rows if r[7] == "apply"]
dirs = {os.path.join(clone, r[2].strip("/")) for r in rows}
base = {d: snapshot(d) for d in dirs}
basepeers = {}
for d in dirs:
    if "package-lock.json" in base[d]:
        f = os.path.join(os.path.dirname(LOG), f"{app}.{abs(hash(d))}.base-lock.json")
        open(f, "w").write(base[d]["package-lock.json"])
        basepeers[d] = peers(f)
excluded = {}
for attempt in range(8):
    for d in dirs:
        restore(d, base[d])
    live = [r for r in apply_rows if id(r) not in excluded]
    res = apply([r for r in live if r[6] == "0"], False)
    for r in [r for r in live if r[6] == "1"]:
        res.update(apply([r], True))
    culprit = None
    for d in basepeers:
        bumped = {r[3]: r for r in live if r[1] == "npm" and os.path.join(clone, r[2].strip("/")) == d
                  and res.get(id(r), "").startswith("applied")}
        new = peers(os.path.join(d, "package-lock.json")) - basepeers[d]
        fixed = False
        for holder, peer, rng, ver in sorted(new):
            if peer not in bumped and not direct(d, peer):
                sh([NPM, "update", "--package-lock-only", "--ignore-scripts", "--no-audit", "--no-fund", peer], d)
                fixed = True
        if fixed:
            new = peers(os.path.join(d, "package-lock.json")) - basepeers[d]
        for holder, peer, rng, ver in sorted(new):
            if peer in bumped:
                culprit = (bumped[peer], f"peer conflict: {holder} requires {peer} {rng}")
            elif holder in bumped:
                culprit = (bumped[holder], f"peer conflict: needs {peer} {rng}, app has {ver}")
            if culprit:
                break
        if culprit:
            break
    if not culprit:
        break
    excluded[id(culprit[0])] = culprit[1]
    with open(LOG, "a") as fh:
        fh.write(f"\n### excluding {culprit[0][3]}: {culprit[1]}\n")
res.update({k: "skip: " + v for k, v in excluded.items()})
with open(os.path.join(os.path.dirname(os.path.abspath(clone)), f"{app}.applied.tsv"), "w") as fh:
    for r in rows:
        status = res.get(id(r), r[7] + (": " + r[8] if r[8] else ""))
        fh.write("\t".join(r[:7] + [status]) + "\n")
        print("\t".join([r[0], r[3], r[4], r[5], status]))
