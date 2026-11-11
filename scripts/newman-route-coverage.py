#!/usr/bin/env python3
"""Measure which appinfo/routes.php routes have at least one Newman request.

Usage:
  newman-route-coverage.py <repo> [--ci-only] [--routes routes.json] [--json out.json]
                           [--controllers a,b] [--unmatched] [--ratchet baseline.json]

A route counts as covered when a request in a collection has the same verb and a
path that matches the route url (each {param} matches one segment, or anything for
a param whose requirement allows a slash). URLs are read from request items and
from pm.sendRequest calls in scripts. Postman variables ({{x}}, :x, ${x}) match any
segment.

--ci-only limits the collections to the ones CI's Newman job runs:
tests/integration/**/*.postman_collection.json without an `@newman exclude <reason>`
in the collection description. Without it, tests/newman and tests/federation are
counted too.

--ratchet <baseline.json> exits 1 when the covered-route count is below the
baseline's "covered" floor. Raise the floor in the same PR that adds coverage;
lowering it needs a reason in that PR. Requires --ci-only, so the floor always
measures what CI actually runs.

routes.php is read with `php -r` (resources expanded to index/show/create/update/
destroy), so php must be on PATH unless --routes is given.
"""
import glob
import json
import os
import re
import subprocess
import sys

ROUTES_PHP = r'''$r=require "appinfo/routes.php"; $o=[];
foreach(($r["resources"]??[]) as $n=>$v){$u=$v["url"];$c=strtolower($n);
 foreach([["index","GET",""],["show","GET","/{id}"],["create","POST",""],["update","PUT","/{id}"],["destroy","DELETE","/{id}"]] as $a)
  $o[]=["name"=>"$c#$a[0]","url"=>"/".ltrim($u,"/").$a[2],"verb"=>$a[1],"req"=>[]];}
foreach(($r["routes"]??[]) as $x){$o[]=["name"=>$x["name"],"url"=>$x["url"],"verb"=>$x["verb"]??"GET","req"=>$x["requirements"]??[]];}
echo json_encode($o);'''

# A placeholder for a Postman variable segment; replaced by 'X' before matching.
VAR = '\x00'


def load_routes(repo, routes_path=None):
    """Return the route list, from a JSON dump or by evaluating appinfo/routes.php."""
    if routes_path:
        with open(routes_path) as fh:
            return json.load(fh)
    return json.loads(subprocess.check_output(['php', '-r', ROUTES_PHP], cwd=repo))


def exclusion_reason(collection):
    """Return the `@newman exclude <reason>` text of a collection, or None."""
    desc = (collection.get('info') or {}).get('description') or ''
    if isinstance(desc, dict):
        desc = desc.get('content') or ''
    m = re.search(r'@newman\s+exclude\b[ \t]*(.*)', str(desc))
    if m and m.group(1).strip():
        return m.group(1).strip()
    return None


def collection_files(repo, ci_only):
    files = sorted(glob.glob(os.path.join(repo, 'tests/integration/**/*.postman_collection.json'), recursive=True))
    if not ci_only:
        files += sorted(glob.glob(os.path.join(repo, 'tests/newman/*.postman_collection.json')))
        files += sorted(glob.glob(os.path.join(repo, 'tests/federation/*.postman_collection.json')))
        return files
    out = []
    for f in files:
        with open(f) as fh:
            if exclusion_reason(json.load(fh)) is None:
                out.append(f)
    return out


def norm(url):
    """Reduce a request URL to an app-relative path with variables as VAR."""
    url = re.sub(r'^(?:\{\{[^}]+\}\}|\$\{[^}]+\})', '', url.strip())
    url = re.sub(r'^https?://[^/]+', '', url)
    url = url.split('?')[0].split('#')[0]
    url = re.sub(r'\{\{[^}]+\}\}', VAR, url)
    url = re.sub(r'(?<=/):[A-Za-z_]\w*', VAR, url)
    url = re.sub(r'\$\{[^}]+\}', VAR, url)
    for p in ('/index.php', '/apps/openregister'):
        if url.startswith(p):
            url = url[len(p):]
    if url.startswith('/ocs/v2.php/apps/openregister'):
        url = url[len('/ocs/v2.php/apps/openregister'):]
    return '/' + url.strip('/')


def scan_script(src, coll, reqs):
    """Collect pm.sendRequest-style {url, method} pairs from a script body."""
    for m in re.finditer(r"url\s*:\s*[`'\"]([^`'\"]+)[`'\"][^}]{0,200}?method\s*:\s*['\"](\w+)", src, re.S):
        reqs.append((m.group(2).upper(), norm(m.group(1)), coll))
    for m in re.finditer(r"method\s*:\s*['\"](\w+)['\"][^}]{0,200}?url\s*:\s*[`'\"]([^`'\"]+)", src, re.S):
        reqs.append((m.group(1).upper(), norm(m.group(2)), coll))


def walk(items, coll, reqs):
    for it in items or []:
        if 'item' in it:
            walk(it['item'], coll, reqs)
        r = it.get('request')
        if r:
            u = r.get('url')
            raw = u if isinstance(u, str) else (u or {}).get('raw') or ('/' + '/'.join((u or {}).get('path') or []))
            reqs.append(((r.get('method') or 'GET').upper(), norm(raw), coll))
        for ev in it.get('event') or []:
            scan_script('\n'.join((ev.get('script') or {}).get('exec') or []), coll, reqs)


def collect_requests(repo, ci_only):
    """Return [(verb, normalised path, collection relpath)] for every request."""
    reqs = []
    for f in collection_files(repo, ci_only):
        with open(f) as fh:
            d = json.load(fh)
        rel = os.path.relpath(f, repo)
        walk(d.get('item'), rel, reqs)
        for ev in d.get('event') or []:
            scan_script('\n'.join((ev.get('script') or {}).get('exec') or []), rel, reqs)
    return reqs


def route_re(rt):
    url = '/' + rt['url'].strip('/')
    out = ''
    for part in re.split(r'(\{[^}]+\})', url):
        if part.startswith('{') and part.endswith('}'):
            req = (rt.get('req') or {}).get(part[1:-1], '')
            out += '(?:[^/]+)' if '/' not in req and '.+' not in req and '.*' not in req else '(?:.+)'
        else:
            out += re.escape(part)
    return re.compile('^' + out + '$')


def measure(routes, reqs, ci_only=False):
    covered, uncovered = [], []
    for rt in routes:
        rx = route_re(rt)
        verb = rt['verb'].upper()
        hits = [c for (v, p, c) in reqs if v == verb and rx.match(p.replace(VAR, 'X'))]
        entry = {'name': rt['name'], 'verb': verb, 'url': rt['url'], 'by': sorted(set(hits))[:3]}
        (covered if hits else uncovered).append(entry)
    total = len(routes)
    res = {'routes': total, 'covered': len(covered),
           'pct': round(100 * len(covered) / total, 1) if total else 0.0,
           'requests_seen': len(reqs), 'ci_only': ci_only,
           'uncovered': uncovered, 'covered_list': covered}
    by_ctrl = {}
    for u in routes:
        by_ctrl.setdefault(u['name'].split('#')[0], [0, 0])[0] += 1
    for u in covered:
        by_ctrl[u['name'].split('#')[0]][1] += 1
    res['by_controller'] = {k: {'routes': v[0], 'covered': v[1]} for k, v in sorted(by_ctrl.items())}
    return res


def unmatched(routes, reqs):
    rxs = [(rt['verb'].upper(), route_re(rt)) for rt in routes]
    um = {}
    for v, p, _ in reqs:
        pp = p.replace(VAR, 'X')
        if not any(v == rv and rx.match(pp) for rv, rx in rxs):
            um[(v, pp)] = um.get((v, pp), 0) + 1
    return um


def check_ratchet(res, baseline_path):
    """Return (ok, message) comparing res['covered'] with the baseline floor."""
    with open(baseline_path) as fh:
        floor = int(json.load(fh)['covered'])
    now = res['covered']
    if now < floor:
        return False, (f"FAIL: Newman covers {now} routes, below the floor of {floor} in {baseline_path}. "
                       "A collection lost requests, or one was excluded. Run with --json to see which routes.")
    if now > floor:
        return True, (f"OK: Newman covers {now} routes (floor {floor}). "
                      f"Raise \"covered\" in {baseline_path} to {now} so the gain is kept.")
    return True, f"OK: Newman covers {now} routes (floor {floor})."


def main(argv):
    if not argv or argv[0].startswith('--'):
        print(__doc__)
        return 2
    repo = argv[0]

    def opt(name, default=None):
        return argv[argv.index(name) + 1] if name in argv else default

    ci_only = '--ci-only' in argv
    ratchet = opt('--ratchet')
    if ratchet and not ci_only:
        print('--ratchet requires --ci-only', file=sys.stderr)
        return 2
    routes = load_routes(repo, opt('--routes'))
    reqs = collect_requests(repo, ci_only)
    res = measure(routes, reqs, ci_only)

    out = opt('--json')
    if out:
        with open(out, 'w') as fh:
            json.dump(res, fh, indent=1)
    ctrls = opt('--controllers')
    if ctrls:
        want = set(ctrls.split(','))
        mine = [u for u in res['covered_list'] + res['uncovered'] if u['name'].split('#')[0] in want]
        left = [u for u in res['uncovered'] if u['name'].split('#')[0] in want]
        print(f"lane routes {len(mine)}, covered {len(mine) - len(left)}, uncovered {len(left)}")
        for u in left:
            print(f"  LEFT {u['verb']} {u['url']} ({u['name']})")
    print(f"routes {res['routes']}, covered {res['covered']} ({res['pct']}%), "
          f"requests seen {res['requests_seen']}, ci_only={ci_only}")
    if '--unmatched' in argv:
        um = unmatched(routes, reqs)
        for k, n in sorted(um.items(), key=lambda kv: -kv[1])[:60]:
            print(n, k[0], k[1])
        print('unmatched distinct', len(um), 'requests', sum(um.values()))
    if ratchet:
        ok, msg = check_ratchet(res, ratchet)
        print(msg)
        return 0 if ok else 1
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
