import json, sys
A = json.load(open(sys.argv[1])); B = json.load(open(sys.argv[2]))
def resolve(db, fq, seen=None):
    seen = seen or set()
    if fq in seen or fq not in db: return {}
    seen.add(fq); c = db[fq]; ms = {}
    for p in c.get('extends', []) + c.get('traits', []) + c.get('implements', []):
        for k, v in resolve(db, p, seen).items():
            ms.setdefault(k, dict(v, inherited=p))
    for k, v in (c['methods'] or {}).items(): ms[k] = v
    return ms
def consts(db, fq, seen=None):
    seen = seen or set()
    if fq in seen or fq not in db: return set()
    seen.add(fq); c = db[fq]; s = {k for k, v in (c['consts'] or {}).items() if v == 'public'}
    for p in c.get('extends', []) + c.get('implements', []): s |= consts(db, p, seen)
    return s
def sig(m): return ', '.join(f"{p['type']+' ' if p['type'] else ''}{'...' if p['variadic'] else ''}${p['name']}{' = …' if p['default'] else ''}" for p in m['params']) + (f"): {m['return']}" if m['return'] else ')')
out = {}
for fq, c in A.items():
    if c['file'].startswith('Migration/'): continue
    if fq not in B:
        out[fq] = {'removed': True, 'kind': c['kind']}; continue
    ma, mb = resolve(A, fq), resolve(B, fq); d = {}
    for name, m in ma.items():
        if m['vis'] != 'public': continue
        if name not in mb: d[name] = {'change': 'removed', 'old': sig(m)}; continue
        n = mb[name]; why = []
        if n['vis'] != 'public': why.append('no longer public')
        if n['static'] != m['static']: why.append('static changed')
        on = [p['name'] for p in m['params']]; nn = [p['name'] for p in n['params']]
        if on != nn: why.append(f'param names/order {on} -> {nn}')
        oreq = sum(1 for p in m['params'] if not p['default'] and not p['variadic']); nreq = sum(1 for p in n['params'] if not p['default'] and not p['variadic'])
        if nreq > oreq: why.append(f'required params {oreq} -> {nreq}')
        ot = [p['type'] for p in m['params']]; nt = [p['type'] for p in n['params']]
        for i, (x, y) in enumerate(zip(m['params'], n['params'])):
            if x['name'] == y['name'] and x['type'] != y['type']: why.append(f"type of ${x['name']} {x['type']} -> {y['type']}")
        if len(n['params']) < len(m['params']) and not any(p['variadic'] for p in n['params']): why.append(f'params {len(m["params"])} -> {len(n["params"])}')
        if m['return'] != n['return']: why.append(f"return {m['return']} -> {n['return']}")
        if n.get('abstract') and not m.get('abstract'): why.append('became abstract')
        if why: d[name] = {'change': '; '.join(why), 'old': sig(m), 'new': sig(n)}
    ca, cb = consts(A, fq), consts(B, fq)
    rc = sorted(ca - cb)
    extra = {}
    if c['kind'] == 'interface':
        added = [k for k in resolve(B, fq) if k not in ma]
        if added: extra['interface_added_methods'] = added
    if c['kind'] == 'class' and not c.get('final') and B[fq].get('final'): extra['became_final'] = True
    if c.get('extends') != B[fq].get('extends'): extra['extends'] = [c.get('extends'), B[fq].get('extends')]
    if d or rc or extra:
        out[fq] = {'kind': c['kind'], 'file': c['file'], 'methods': d, 'removed_consts': rc, **extra}
json.dump(out, open(sys.argv[3], 'w'), indent=1)
print(len(out), 'classes changed;', sum(1 for v in out.values() if v.get('removed')), 'removed;', sum(len(v.get('methods', {})) for v in out.values()), 'method changes')
