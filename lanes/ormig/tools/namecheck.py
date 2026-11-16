"""Name-based scan: calls whose method name changed signature tag->main, regardless of receiver type.

namecheck.py <app> [--all]  (reads signatures/app-<app>.json; prints file:line method verdict)
Flags a call when it fits some tag signature of that method name but no main signature,
or when a positional argument lands on a parameter whose name/type changed.
"""
import json, sys, collections
D = '/tmp/lanes/ormig/signatures'
A = json.load(open(f'{D}/tag.json')); B = json.load(open(f'{D}/main.json'))
def methods(db):
    m = collections.defaultdict(list)
    for fq, c in db.items():
        for n, v in (c.get('methods') or {}).items():
            if n.startswith('__') and n != '__construct': continue
            m[n].append((fq, v))
    return m
MA, MB = methods(A), methods(B)
def fits(v, args):
    params = v['params']; names = [p['name'] for p in params]
    variadic = any(p['variadic'] for p in params)
    pos = [a for a in args if not a['name'] and not a['unpack']]
    named = [a['name'] for a in args if a['name']]
    if any(a['unpack'] for a in args): return True
    if not variadic and len(pos) > len(params): return False
    for n in named:
        if n not in names and not variadic: return False
    given = set(names[:len(pos)]) | set(named)
    for p in params:
        if not p['default'] and not p['variadic'] and p['name'] not in given: return False
    return True
def ptype(v, i):
    ps = v['params']; return (ps[i]['name'], ps[i]['type']) if i < len(ps) else None
app = sys.argv[1]
refs = json.load(open(f'{D}/app-{app}.json'))
SKIP = {'find', 'findAll', 'get', 'set', 'save', 'delete', 'update', 'insert', 'count', 'search', 'run', 'execute', 'handle', 'list', 'create', 'apply', 'build', 'resolve', 'render', 'validate', 'load', 'add', 'remove', 'has', 'process', 'send', 'map', 'dispatch'}
out = []
for r in refs:
    if r['k'] != 'call' or r['file'].startswith('tests/'): continue
    n = r['method']
    if n not in MB or n not in MA: continue
    sa = {fq: v for fq, v in MA[n] if fq.startswith('OCA\\OpenRegister\\')}
    sb = {fq: v for fq, v in MB[n] if fq.startswith('OCA\\OpenRegister\\')}
    if not sa or not sb: continue
    changed = [fq for fq in sa if fq in sb and json.dumps(sa[fq]['params']) != json.dumps(sb[fq]['params'])]
    if not changed: continue
    args = r['args']
    okA = [fq for fq in changed if fits(sa[fq], args)]
    okB = [fq for fq in sb if fits(sb[fq], args)]
    verdict = None
    if okA and not okB: verdict = 'BREAK'
    elif okA:
        pos = [i for i, a in enumerate(args) if not a['name'] and not a['unpack']]
        for fq in okA:
            if fq in okB:
                if any(ptype(sa[fq], i) != ptype(sb[fq], i) for i in pos): verdict = 'REVIEW-positional'; break
            else:
                pass
        if verdict is None and n not in SKIP and not set(okA) & set(okB): verdict = 'REVIEW-other-class'
    if verdict and (n not in SKIP or verdict == 'BREAK' and '--all' in sys.argv):
        out.append(f"{r['file']}:{r['line']} {n} {verdict} tag:{','.join(x.split(chr(92))[-1] for x in okA)}")
print('\n'.join(sorted(set(out))))
print(len(set(out)), 'flagged', file=sys.stderr)
