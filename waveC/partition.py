import json, collections
fu = json.load(open('free-units.json'))
W = lambda u: u['nloc'] * (1 + (u['ccn'] > 10) + (u['ccn'] > 25))
fw = {f: sum(W(u) for u in v if not u['held']) for f, v in fu.items()}
fw = {f: w for f, w in fw.items() if w > 0}
def grp(f):
    p = f.split('/'); return '/'.join(p[:3]) if len(p) > 3 else '/'.join(p[:2])
groups = collections.defaultdict(list)
for f in fw: groups[grp(f)].append(f)
K = 8; avg = sum(fw.values()) / K
atoms = []
for g, fs in groups.items():
    gw = sum(fw[f] for f in fs)
    if gw > avg * 0.6:  # a big directory splits into files
        atoms += [(fw[f], [f]) for f in fs]
    else:
        atoms.append((gw, sorted(fs)))
atoms.sort(key=lambda a: -a[0])
lanes = [[0, []] for _ in range(K)]
for w, fs in atoms:
    l = min(lanes, key=lambda x: x[0]); l[0] += w; l[1] += fs
lanes.sort(key=lambda l: min(l[1]))
scopes = {f'C{i+1:02d}': sorted(l[1]) for i, l in enumerate(lanes)}
json.dump(scopes, open('../scopes-c.json', 'w'), indent=1)
for k, l in scopes.items():
    us = [u for f in l for u in fu[f]]; free = [u for u in us if not u['held']]
    print(k, len(l), 'o5', sum(u['nloc'] for u in free), 'o10', sum(u['nloc'] for u in free if u['ccn'] > 10),
          'w', sum(W(u) for u in free), 'units', len(free), 'heldnloc', sum(u['nloc'] for u in us if u['held']), sorted({grp(f) for f in l}))
