#!/usr/bin/env python3
"""Measure which appinfo/routes.php routes have at least one Newman request.

Usage: newman_route_coverage.py <repo> [--routes routes.json] [--json out.json] [--ci-only] [--controllers a,b] [--unmatched]

A route counts as covered when a request in a collection has the same verb and a
path that matches the route url (each {param} matches one segment, or anything for
a param whose requirement allows a slash). URLs are read from request items and
from pm.sendRequest calls in scripts. Postman variables ({{x}}, :x) match any
segment. --ci-only limits the collections to the ones CI's Newman job runs
(tests/integration/*.postman_collection.json without an `@newman exclude <reason>`).
"""
import glob, json, os, re, subprocess, sys

args = sys.argv[1:]
repo = args[0]
def opt(name, default=None):
    return args[args.index(name) + 1] if name in args else default

routes_path = opt('--routes')
if routes_path:
    routes = json.load(open(routes_path))
else:
    php = r'''$r=require "appinfo/routes.php"; $o=[];
foreach(($r["resources"]??[]) as $n=>$v){$u=$v["url"];$c=strtolower($n);
 foreach([["index","GET",""],["show","GET","/{id}"],["create","POST",""],["update","PUT","/{id}"],["destroy","DELETE","/{id}"]] as $a)
  $o[]=["name"=>"$c#$a[0]","url"=>"/".ltrim($u,"/").$a[2],"verb"=>$a[1],"req"=>[]];}
foreach(($r["routes"]??[]) as $x){$o[]=["name"=>$x["name"],"url"=>$x["url"],"verb"=>$x["verb"]??"GET","req"=>$x["requirements"]??[]];}
echo json_encode($o);'''
    routes = json.loads(subprocess.check_output(['php', '-r', php], cwd=repo))

def collections(ci_only):
    files = sorted(glob.glob(os.path.join(repo, 'tests/integration/**/*.postman_collection.json'), recursive=True))
    if not ci_only:
        files += sorted(glob.glob(os.path.join(repo, 'tests/newman/*.postman_collection.json')))
        files += sorted(glob.glob(os.path.join(repo, 'tests/federation/*.postman_collection.json')))
        return files
    out = []
    for f in files:
        d = json.load(open(f))
        desc = (d.get('info') or {}).get('description') or ''
        if isinstance(desc, dict):
            desc = desc.get('content') or ''
        m = re.search(r'@newman\s+exclude\b[ \t]*(.*)', str(desc))
        if m and m.group(1).strip():
            continue
        out.append(f)
    return out

def norm(url):
    url = re.sub(r'^\{\{[^}]+\}\}', '', url.strip())
    url = re.sub(r'^https?://[^/]+', '', url)
    url = url.split('?')[0].split('#')[0]
    url = re.sub(r'\{\{[^}]+\}\}', '\x00', url)
    url = re.sub(r'(?<=/):[A-Za-z_]\w*', '\x00', url)
    url = re.sub(r'\$\{[^}]+\}', '\x00', url)
    for p in ('/index.php', '/apps/openregister'):
        if url.startswith(p):
            url = url[len(p):]
    if url.startswith('/ocs/v2.php/apps/openregister'):
        url = url[len('/ocs/v2.php/apps/openregister'):]
    return '/' + url.strip('/')

reqs = []  # (verb, path, collection)
def walk(items, coll):
    for it in items or []:
        if 'item' in it:
            walk(it['item'], coll)
        r = it.get('request')
        if r:
            u = r.get('url')
            raw = u if isinstance(u, str) else (u or {}).get('raw') or ('/' + '/'.join((u or {}).get('path') or []))
            reqs.append(((r.get('method') or 'GET').upper(), norm(raw), coll))
        for ev in it.get('event') or []:
            src = '\n'.join((ev.get('script') or {}).get('exec') or [])
            scan_script(src, coll)

def scan_script(src, coll):
    for m in re.finditer(r"url\s*:\s*[`'\"]([^`'\"]+)[`'\"][^}]{0,200}?method\s*:\s*['\"](\w+)", src, re.S):
        reqs.append((m.group(2).upper(), norm(m.group(1)), coll))
    for m in re.finditer(r"method\s*:\s*['\"](\w+)['\"][^}]{0,200}?url\s*:\s*[`'\"]([^`'\"]+)", src, re.S):
        reqs.append((m.group(1).upper(), norm(m.group(2)), coll))

ci_only = '--ci-only' in args
for f in collections(ci_only):
    d = json.load(open(f))
    walk(d.get('item'), os.path.relpath(f, repo))
    for ev in d.get('event') or []:
        scan_script('\n'.join((ev.get('script') or {}).get('exec') or []), os.path.relpath(f, repo))

def route_re(rt):
    url = '/' + rt['url'].strip('/')
    out = ''
    for part in re.split(r'(\{[^}]+\})', url):
        if part.startswith('{') and part.endswith('}'):
            name = part[1:-1]
            req = (rt.get('req') or {}).get(name, '')
            out += '(?:[^/]+)' if '/' not in req and '.+' not in req and '.*' not in req else '(?:.+)'
        else:
            out += re.escape(part)
    return re.compile('^' + out + '$')

covered, uncovered = [], []
for rt in routes:
    rx = route_re(rt)
    verb = rt['verb'].upper()
    hits = [c for (v, p, c) in reqs if v == verb and rx.match(p.replace('\x00', 'X'))]
    (covered if hits else uncovered).append({'name': rt['name'], 'verb': verb, 'url': rt['url'], 'by': sorted(set(hits))[:3]})

res = {'routes': len(routes), 'covered': len(covered), 'pct': round(100 * len(covered) / len(routes), 1),
       'requests_seen': len(reqs), 'ci_only': ci_only, 'uncovered': uncovered, 'covered_list': covered}
by_ctrl = {}
for u in routes:
    c = u['name'].split('#')[0]
    by_ctrl.setdefault(c, [0, 0])[0] += 1
for u in covered:
    by_ctrl[u['name'].split('#')[0]][1] += 1
res['by_controller'] = {k: {'routes': v[0], 'covered': v[1]} for k, v in sorted(by_ctrl.items())}
out = opt('--json')
if out:
    json.dump(res, open(out, 'w'), indent=1)
ctrls = opt('--controllers')
if ctrls:
    want = set(ctrls.split(','))
    mine = [u for u in covered + uncovered if u['name'].split('#')[0] in want]
    left = [u for u in uncovered if u['name'].split('#')[0] in want]
    print(f"lane routes {len(mine)}, covered {len(mine) - len(left)}, uncovered {len(left)}")
    for u in left:
        print(f"  LEFT {u['verb']} {u['url']} ({u['name']})")
print(f"routes {res['routes']}, covered {res['covered']} ({res['pct']}%), requests seen {res['requests_seen']}, ci_only={ci_only}")
if '--unmatched' in args:
    rxs = [(rt['verb'].upper(), route_re(rt)) for rt in routes]
    um = {}
    for v, p, c in reqs:
        pp = p.replace('\x00', 'X')
        if not any(v == rv and rx.match(pp) for rv, rx in rxs):
            um[(v, pp)] = um.get((v, pp), 0) + 1
    for k, n in sorted(um.items(), key=lambda kv: -kv[1])[:60]:
        print(n, k[0], k[1])
    print('unmatched distinct', len(um), 'requests', sum(um.values()))
