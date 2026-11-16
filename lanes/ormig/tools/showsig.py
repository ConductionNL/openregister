"""showsig.py <app> <file:line> <method> : call context plus tag/main signatures of that method name in openregister."""
import json, sys
D = '/tmp/lanes/ormig/signatures'
A = json.load(open(f'{D}/tag.json')); B = json.load(open(f'{D}/main.json'))
app, loc, m = sys.argv[1:4]
f, n = loc.rsplit(':', 1); n = int(n)
src = open(f'/tmp/lanes/ormig/{app}/lib/{f}').read().splitlines()
print(f'--- {app} {loc}')
for i in range(max(0, n - 4), min(len(src), n + 5)): print(f'{i+1:5} {src[i]}')
def sig(v): return '(' + ', '.join(f"{(p['type'] or '').split(chr(92))[-1]} ${p['name']}{'=' if p['default'] else ''}" for p in v['params']) + ')'
for lab, db in (('tag', A), ('main', B)):
    for fq, c in db.items():
        v = (c.get('methods') or {}).get(m)
        if v and fq.startswith('OCA\\OpenRegister\\'):
            other = (A if lab == 'main' else B).get(fq, {}).get('methods', {}) or {}
            if lab == 'main' and json.dumps(other.get(m, {}).get('params')) == json.dumps(v['params']): continue
            if lab == 'tag' and json.dumps((B.get(fq, {}).get('methods') or {}).get(m, {}).get('params')) == json.dumps(v['params']): continue
            print(f'  {lab:4} {fq.split(chr(92), 2)[-1]}::{m}{sig(v)}')
