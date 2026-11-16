#!/usr/bin/env python3
"""plan.py <app> <clone>: print one TSV row per update of every open dependabot PR on development.

columns: pr eco dir pkg from to major action reason
action: apply | skip | already
"""
import json, re, subprocess, sys, os

app, clone = sys.argv[1], sys.argv[2]
ANALYSERS = {"vimeo/psalm", "phpmd/phpmd", "phpstan/phpstan", "squizlabs/php_codesniffer",
             "phpunit/phpunit", "friendsofphp/php-cs-fixer"}

# REST, not GraphQL (blocked in cloud sessions): open dependabot PRs on development.
_jq = ".[] | select(.user.login == \"dependabot[bot]\" or .user.login == \"dependabot\") | {number, headRefName: .head.ref, title, body: (.body // \"\")}"
prs = [json.loads(line) for line in subprocess.run(
    ["gh", "api", "--paginate", f"repos/ConductionNL/{app}/pulls?state=open&base=development&per_page=100", "--jq", _jq],
    capture_output=True, text=True, check=True).stdout.splitlines() if line.strip()]


def vt(v):
    return tuple(int(x) for x in re.findall(r"\d+", v)[:3]) or (0,)


def major(a, b):
    a, b = vt(a), vt(b)
    if a[0] == 0 and b[0] == 0:
        return (a + (0,))[1] != (b + (0,))[1]
    return a[0] != b[0]


def lock_version(eco, d, pkg):
    base = os.path.join(clone, d.strip("/"))
    try:
        if eco == "composer":
            lk = json.load(open(os.path.join(base, "composer.lock")))
            for p in lk.get("packages", []) + lk.get("packages-dev", []):
                if p["name"] == pkg:
                    return p["version"].lstrip("v")
        else:
            lk = json.load(open(os.path.join(base, "package-lock.json")))
            p = lk.get("packages", {}).get(f"node_modules/{pkg}")
            return p and p.get("version")
    except (OSError, ValueError):
        return None
    return None


def manifest_spec(eco, d, pkg):
    base = os.path.join(clone, d.strip("/"))
    try:
        if eco == "composer":
            m = json.load(open(os.path.join(base, "composer.json")))
            for k in ("require", "require-dev"):
                if pkg in m.get(k, {}):
                    return k, m[k][pkg]
        else:
            m = json.load(open(os.path.join(base, "package.json")))
            for k in ("dependencies", "devDependencies", "peerDependencies", "optionalDependencies"):
                if pkg in m.get(k, {}):
                    return k, m[k][pkg]
            if pkg in json.dumps(m.get("overrides", {})):
                return "overrides", ""
    except (OSError, ValueError):
        pass
    return None, None


for pr in sorted(prs, key=lambda p: p["number"]):
    ref = pr["headRefName"]
    eco = "composer" if "/composer/" in ref else "npm"
    m = re.match(r"dependabot/(?:composer|npm_and_yarn)/(.*?)/?development/", ref)
    d = "/" + (m.group(1) if m else "")
    ups = re.findall(r"Updates `([^`]+)` from ([^ ]+) to ([^ \n]+)", pr["body"] or "")
    if not ups:
        t = re.search(r"[Bb]ump (\S+) from (\S+) to (\S+)", pr["title"])
        if t:
            ups = [t.groups()]
    if not ups:
        print(f"{pr['number']}\t{eco}\t{d}\t?\t?\t?\t0\tskip\tcannot parse title")
        continue
    for pkg, a, b in ups:
        b = b.rstrip(".")
        maj = major(a, b)
        cur = lock_version(eco, d, pkg)
        sect, spec = manifest_spec(eco, d, pkg)
        action, reason = "apply", ""
        excl = {}
        xf = os.path.join(os.path.dirname(os.path.abspath(__file__)), f"{app}.exclude")
        for xf in (os.path.join(os.path.dirname(xf), "_all.exclude"), xf):
          if os.path.isfile(xf):
            excl.update(dict(l.rstrip("\n").split("\t", 1) for l in open(xf) if "\t" in l))
        if cur and vt(cur) >= vt(b):
            action, reason = "already", f"development already has {cur}"
        elif pkg in excl:
            action, reason = "skip", excl[pkg]
        elif maj and pkg in ANALYSERS:
            action, reason = "skip", "major bump of a quality-gate analyser/test runner; needs its own PR with config and baseline work"
        elif sect == "overrides":
            action, reason = "skip", "held by an npm override"
        elif spec and re.fullmatch(r"=?v?\d+\.\d+\.\d+", spec.strip()):
            action, reason = "skip", f"pinned exactly ({spec}) in {sect}"
        print(f"{pr['number']}\t{eco}\t{d}\t{pkg}\t{a}\t{b}\t{int(maj)}\t{action}\t{reason}\t{sect or ''}\t{spec or ''}\t{cur or ''}")
