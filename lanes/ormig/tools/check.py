import json, sys, os, re, collections
D = '/tmp/lanes/ormig/signatures'
STRICT = os.environ.get('STRICT') == '1'
A = json.load(open(f'{D}/tag.json')); B = json.load(open(f'{D}/main.json'))
DIFF = json.load(open(f'{D}/diff.json'))
def resolve(db, fq, seen=None):
    seen = seen or set()
    if fq in seen or fq not in db: return {}
    seen.add(fq); c = db[fq]; ms = {}
    for p in c.get('extends', []) + c.get('traits', []) + c.get('implements', []):
        for k, v in resolve(db, p, seen).items(): ms.setdefault(k, dict(v, owner=v.get('owner', p)))
    for k, v in (c['methods'] or {}).items(): ms[k] = dict(v, owner=fq)
    return ms
RA, RB = {}, {}
def ra(fq):
    if fq not in RA: RA[fq] = resolve(A, fq)
    return RA[fq]
def rb(fq):
    if fq not in RB: RB[fq] = resolve(B, fq)
    return RB[fq]
def consts(db, fq, seen=None):
    seen = seen or set()
    if fq in seen or fq not in db: return set()
    seen.add(fq); c = db[fq]; s = set((c['consts'] or {}).keys())
    for p in c.get('extends', []) + c.get('implements', []): s |= consts(db, p, seen)
    return s
def nt(t):
    if not t: return t
    return '|'.join(sorted(x.strip().lstrip('?').split('\\')[-1].lower() for x in t.replace('?', 'null|').split('|')))
def sig(m): return '(' + ', '.join(f"{p['type']+' ' if p['type'] else ''}{'...' if p['variadic'] else ''}${p['name']}{' = …' if p['default'] else ''}" for p in m['params']) + (f"): {m['return']}" if m['return'] else ')')
def callok(m, args):
    """Return list of problems calling method m with args."""
    probs = []; params = m['params']; names = [p['name'] for p in params]
    variadic = any(p['variadic'] for p in params)
    pos = [a for a in args if not a['name'] and not a['unpack']]
    named = [a['name'] for a in args if a['name']]
    unpack = any(a['unpack'] for a in args)
    for n in named:
        if n not in names and not variadic: probs.append(f'unknown named parameter ${n}')
    if not unpack:
        given = set(names[:len(pos)]) | set(named)
        for p in params:
            if not p['default'] and not p['variadic'] and p['name'] not in given: probs.append(f'missing required ${p["name"]}'); break
    return probs
def silent(catch):
    c = [x.lstrip('\\') for x in catch]
    return any(x in ('Throwable', 'Error', 'TypeError', 'ArgumentCountError') for x in c)
CAT_SILENT = lambda r: silent(r.get('catch', []))
rows = []
for app in sys.argv[1:]:
    try: refs = json.load(open(f'{D}/app-{app}.json'))
    except Exception: continue
    fileclasses = collections.defaultdict(set)
    for r in refs:
        if 'class' in r: fileclasses[r['file']].add(r['class'])
        for t in r.get('types', []): fileclasses[r['file']].add(t)
    classdefs = {(r['file'], r['self']): r['methods'] for r in refs if r['k'] == 'classdef'}
    for r in refs:
        k = r['k']; f = f"{r['file']}:{r.get('line', '')}"
        if k in ('new', 'static'):
            c = r['class']
            if c not in B:
                if c in A: rows.append((app, f, c, r['method'], 'class removed on main', '', '', CAT_SILENT(r), 'break'))
                continue
            mb = rb(c).get(r['method']); ma = ra(c).get(r['method'])
            if mb is None:
                if ma is not None or r['method'] != '__construct': rows.append((app, f, c, r['method'], 'method missing on main', sig(ma) if ma else '', '', CAT_SILENT(r), 'break'))
                continue
            pb = callok(mb, r['args']); pa = callok(ma, r['args']) if ma else ['?']
            if pb and (STRICT or not pa): rows.append((app, f, c, r['method'], '; '.join(pb), sig(ma), sig(mb), CAT_SILENT(r), 'break'))
        elif k == 'call':
            ts = [t for t in r['types'] if t in A or t in B]
            probable = False
            if not ts:
                cands = [c for c in fileclasses[r['file']] if c in DIFF and r['method'] in (DIFF[c].get('methods') or {})]
                if not cands: continue
                ts = cands; probable = True
            okb = False; probs = []; olds = ''; news = ''; hit = False
            for t in ts:
                mb = rb(t).get(r['method']); ma = ra(t).get(r['method'])
                if ma is None and mb is None: continue
                hit = True
                if mb is None: probs.append(f'{t.split(chr(92))[-1]}::{r["method"]} missing on main'); olds = sig(ma); continue
                pb = callok(mb, r['args']); pa = callok(ma, r['args']) if ma else []
                if pb and (STRICT or not pa): probs.append('; '.join(pb)); olds, news = sig(ma) if ma else '', sig(mb)
                elif not pb:
                    okb = True
                    if probable: continue
                    if ma and ma['return'] != mb['return'] and r.get('usesResult'):
                        rows.append((app, f, t, r['method'], f"return type {ma['return']} -> {mb['return']} (result used)", sig(ma), sig(mb), CAT_SILENT(r), 'review'))
                    if ma and [p['type'] for p in ma['params']] != [p['type'] for p in mb['params']] and [p['name'] for p in ma['params']] == [p['name'] for p in mb['params']]:
                        rows.append((app, f, t, r['method'], 'parameter type changed', sig(ma), sig(mb), CAT_SILENT(r), 'review'))
            if hit and probs and not okb:
                rows.append((app, f, '|'.join(ts), r['method'], '; '.join(sorted(set(probs))), olds, news, CAT_SILENT(r), 'probable' if probable else 'break'))
        elif k == 'const':
            c = r['class']
            if c in B and r['const'] not in consts(B, c) and r['const'] in consts(A, c): rows.append((app, f, c, r['const'], 'constant removed on main', '', '', False, 'break'))
            elif c not in B and c in A: rows.append((app, f, c, r['const'], 'class removed on main', '', '', False, 'break'))
        elif k in ('typehint', 'returntype', 'instanceof', 'catch', 'classref', 'string', 'extends', 'implements'):
            c = r['class']
            if c in A and c not in B:
                how = {'catch': 'catch never matches (class gone)', 'instanceof': 'instanceof always false', 'string': 'string class name resolves to nothing'}.get(k, 'class removed on main')
                rows.append((app, f, c, '', f'{k}: {how}', '', '', k in ('catch', 'instanceof', 'string'), 'break'))
            if k in ('implements', 'extends') and c in B:
                own = classdefs.get((r['file'], r['self']), {}) or {}
                for name, mb in rb(c).items():
                    if mb['vis'] == 'private': continue
                    ma = ra(c).get(name)
                    if name not in own:
                        if B[c]['kind'] == 'interface' or mb.get('abstract'):
                            if ma is None: rows.append((app, f, c, name, f'{k}: new abstract method on main not implemented by {r["self"]}', '', sig(mb), False, 'break'))
                        continue
                    if ma is not None and sig(ma) == sig(mb): continue
                    o = own[name]
                    issues = []
                    if len(o['params']) < len(mb['params']): issues.append(f'{r["self"]}::{name} takes {len(o["params"])} params, main declares {len(mb["params"])}')
                    oreq = sum(1 for p in o['params'] if not p['default'] and not p['variadic']); nreq = sum(1 for p in mb['params'] if not p['default'] and not p['variadic'])
                    if oreq > nreq: issues.append(f'{r["self"]}::{name} requires {oreq} params, main {nreq}')
                    if mb['return'] and not o['return']: issues.append(f'main declares return {mb["return"]}, {r["self"]}::{name} none')
                    elif mb['return'] and o['return'] and nt(mb['return']) != nt(o['return']) and (ma is None or nt(ma['return']) != nt(mb['return'])): issues.append(f'return {o["return"]} vs main {mb["return"]}')
                    for i, (x, y) in enumerate(zip(o['params'], mb['params'])):
                        if y['type'] and x['type'] and nt(x['type']) != nt(y['type']) and (ma is None or i >= len(ma['params']) or nt(ma['params'][i]['type']) != nt(y['type'])): issues.append(f'param {i+1} type {x["type"]} vs main {y["type"]}')
                    if issues: rows.append((app, f"{r['file']}:{o['line']}", c, name, f'{k}: incompatible declaration (fatal at class load): ' + '; '.join(issues), sig(ma) if ma else '', sig(mb), False, 'break'))
json.dump(rows, open(f'{D}/breaks-'+'-'.join(sys.argv[1:])+'.json', 'w'), indent=1)
c = collections.Counter((r[0], r[8]) for r in rows)
print(len(rows)); print(sorted(c.items()))
