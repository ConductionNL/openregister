import sys, os, collections
sys.path.insert(0, os.path.expanduser('/tmp/lanes/scan/checker/scripts/maintainability'))
import check_maintainability as cm
app = os.path.abspath(sys.argv[1])
scope = cm.Scope(app)
files = {f: l for f, l in cm.production_files(app, scope).items() if l == 'php'}
deps = cm.php_dependencies(app, files)
modules = sorted(deps)
loc = {m: len(cm.code_lines(cm.read_text(os.path.join(app, m)), files[m])) for m in modules}
inc = collections.defaultdict(set)
for s, ts in deps.items():
    for t in ts: inc[t].add(s)
root, comp = cm.component_of_factory(modules)
total = sum(loc.values())
exp = []
for m in modules:
    foreign = sorted({s for s in inc[m] if comp(s) != comp(m)})
    if foreign: exp.append((loc[m], m, foreign))
exp.sort(reverse=True)
print(f"root={root} total={total} exposed={sum(e[0] for e in exp)} hidden_share={1-sum(e[0] for e in exp)/total:.4f}")
n = int(sys.argv[2]) if len(sys.argv) > 2 else 60
for l, m, f in exp[:n]:
    print(f"{l:6d} {m}  <- {len(f)}: " + ", ".join(x.replace('lib/','') for x in f[:6]) + (' ...' if len(f) > 6 else ''))
by = collections.Counter(); only = collections.Counter()
for l, m, f in exp:
    comps = {comp(x) for x in f}
    for c in comps: by[f"{c}->{comp(m)}"] += l
    if len(comps) == 1: only[f"{list(comps)[0]}->{comp(m)}"] += l
print("\nexposed loc by caller->callee (overlapping):", by.most_common(20))
print("exposed loc where caller comp is the only one:", only.most_common(20))
