#!/usr/bin/env python3
"""Per-directory coverage figures for one app, written by a `measure` task to queue/coverage/<app>.json.

    python3 bin/coverage_dirs.py --app <app> --commit <sha> [--clover clover.xml] [--js coverage-summary.json] \
        [--php-tool pcov|xdebug|none] > queue/coverage/<app>.json

--clover is a PHPUnit clover report (--coverage-clover); --js is an Istanbul json-summary (vitest
`--coverage.reporter=json-summary`, jest `--coverageReporters=json-summary`). Paths are cut to start at lib/
or src/. Output:
    {"app", "commit", "measured_at", "php": {"tool", "pct", "statements", "covered", "dirs": {...}},
     "js": {"pct", "statements", "covered", "dirs": {...}}}
where each dirs key is a file's directory with a trailing slash (lib/Service/Object/, src/views/) and the
value is [statements, covered]. A side that was not measured is null.
"""
import argparse, json, sys, time
import xml.etree.ElementTree as ET
from collections import defaultdict


def cut(path, root):
    k = path.find("/" + root + "/")
    if path.startswith(root + "/"):
        return path
    return path[k + 1:] if k >= 0 else None


def side(pairs):
    dirs = defaultdict(lambda: [0, 0])
    for rel, st, cov in pairs:
        d = rel.rsplit("/", 1)[0] + "/"
        dirs[d][0] += st
        dirs[d][1] += cov
    st = sum(v[0] for v in dirs.values())
    cov = sum(v[1] for v in dirs.values())
    return {"pct": round(100.0 * cov / st, 2) if st else None, "statements": st, "covered": cov,
            "dirs": dict(sorted(dirs.items()))}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--app", required=True)
    ap.add_argument("--commit", required=True)
    ap.add_argument("--clover")
    ap.add_argument("--js")
    ap.add_argument("--php-tool", default="pcov")
    a = ap.parse_args()
    out = {"app": a.app, "commit": a.commit, "measured_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
           "php": None, "js": None}
    if a.clover:
        pairs = []
        for _, el in ET.iterparse(a.clover):
            if el.tag == "file":
                rel, m = cut(el.get("name", ""), "lib"), el.find("metrics")
                if rel and m is not None:
                    pairs.append((rel, int(m.get("statements", 0)), int(m.get("coveredstatements", 0))))
                el.clear()
        out["php"] = {"tool": a.php_tool, **side(pairs)}
    if a.js:
        pairs = []
        for name, v in json.load(open(a.js)).items():
            rel = cut(name, "src") if name != "total" else None
            st = v.get("statements") or {}
            if rel:
                pairs.append((rel, int(st.get("total", 0)), int(st.get("covered", 0))))
        out["js"] = side(pairs)
    json.dump(out, sys.stdout, indent=1)
    print()


if __name__ == "__main__":
    main()
