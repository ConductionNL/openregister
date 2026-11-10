import sys, os, json, re, collections
sys.path.insert(0, os.path.expanduser('~/sig-lanes/doc3/m/lib'))
import check_sig_maintainability as m
app = os.path.abspath('main-clone')
fh = json.load(open('free-held.json'))
lz, err = m.load_lizard(); reader = m.UnitReader(lz)
IGNORE = {'7643', '7746'}
def hunks(n, path):
    p = f'diffs/{n}.diff'
    if not os.path.exists(p): return None
    d = open(p, errors='replace').read()
    out = []
    for part in re.split(r'^diff --git ', d, flags=re.M):
        if not part.startswith(f'a/{path} '): continue
        for h in re.split(r'^(?=@@ )', part, flags=re.M)[1:]:
            mm = re.match(r'@@ -(\d+)', h); start = int(mm.group(1))
            body = h.split('\n')[1:]
            rem = [l[1:].strip() for l in body if l.startswith('-') and len(l[1:].strip()) > 12]
            ctx = [l[1:].strip() for l in body if l.startswith(' ') and len(l[1:].strip()) > 12]
            out.append((start, rem, ctx))
    return out
res = {}
for f, prs in fh.items():
    text = m.read_text(os.path.join(app, f)); L = [l.strip() for l in text.split('\n')]
    us = [u for u in reader.units(f, 'php', text)]
    def unit_at(i):
        c = [u for u in us if u['start'] <= i <= u['end']]
        return min(c, key=lambda u: u['end'] - u['start']) if c else None
    held = collections.defaultdict(set)
    for n in prs:
        if n in IGNORE: continue
        hs = hunks(n, f)
        if hs is None: continue
        for start, rem, ctx in hs:
            anchors = rem or ctx
            for a in anchors:
                occ = [i + 1 for i, l in enumerate(L) if l == a]
                if not occ: continue
                i = min(occ, key=lambda x: abs(x - start))
                u = unit_at(i)
                if u: held[(u['name'], u['start'])].add(n)
    res[f] = [dict(name=u['name'], start=u['start'], end=u['end'], nloc=u['nloc'], ccn=u['ccn'], params=u['params'],
                   held=sorted(held.get((u['name'], u['start']), [])))
              for u in us if u['ccn'] > 5]
json.dump(res, open('free-units.json', 'w'), indent=0)
tot = sum(u['nloc'] for v in res.values() for u in v); h = sum(u['nloc'] for v in res.values() for u in v if u['held'])
print('units>5', sum(len(v) for v in res.values()), 'nloc', tot, 'held nloc', h)
