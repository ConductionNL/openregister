"""Per-file unit complexity of a clean main clone, counted by the scorer's own UnitReader."""
import sys, os, json
sys.path.insert(0, os.path.expanduser('~/sig-lanes/doc3/m/lib'))
import check_sig_maintainability as m
app = os.path.expanduser(sys.argv[1] if len(sys.argv) > 1 else '~/sig-lanes/kc')
out_path = sys.argv[2] if len(sys.argv) > 2 else 'perfile-c.json'
files = m.production_files(app, m.Scope(app))
lz, err = m.load_lizard()
assert lz, err
reader = m.UnitReader(lz)
out, tot = {}, {}
for rel, lang in sorted(files.items()):
    us = reader.units(rel, lang, m.read_text(os.path.join(app, rel)))
    t = tot.setdefault(lang, dict(unit_loc=0, o5=0, o10=0, o25=0))
    d = dict(lang=lang, unit_loc=sum(u['nloc'] for u in us),
             o5=sum(u['nloc'] for u in us if u['ccn'] > 5), o10=sum(u['nloc'] for u in us if u['ccn'] > 10),
             o25=sum(u['nloc'] for u in us if u['ccn'] > 25),
             units=[dict(name=u['name'], start=u['start'], nloc=u['nloc'], ccn=u['ccn'], params=u['params'])
                    for u in us if u['ccn'] > 5 or u['params'] > 2])
    for k in t:
        t[k] += d[k]
    out[rel] = d
json.dump(dict(files=out, totals=tot, skipped=reader.skipped), open(out_path, 'w'))
for lang, t in tot.items():
    print(lang, t, {k: round(t[k] / t['unit_loc'], 4) for k in ('o5', 'o10', 'o25') if t['unit_loc']})
