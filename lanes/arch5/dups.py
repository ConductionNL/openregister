import sys, os, json, collections
sys.path.insert(0, os.path.expanduser('/tmp/lanes/scan/checker/scripts/maintainability'))
import check_maintainability as cm
app=os.path.abspath(sys.argv[1]); want=sys.argv[2]; n=int(sys.argv[3]) if len(sys.argv)>3 else 50
scope=cm.Scope(app); files={f:l for f,l in cm.production_files(app,scope).items() if l==want}
seen={}; red=collections.defaultdict(set); pair=collections.Counter(); keyfreq=collections.Counter(); keyfiles=collections.defaultdict(set); sample={}
for rel in sorted(files):
  text=cm.read_text(os.path.join(app,rel)); L=text.splitlines(); code=cm.code_lines(text,want)
  for i,key,nums in cm.dup_windows(L,code,want):
    if key in seen and seen[key]!=(rel,i):
      new=set(nums)-red[rel]; red[rel].update(nums); pair[(seen[key][0],rel)]+=len(new)
      keyfiles[key].add(rel); keyfiles[key].add(seen[key][0]); sample.setdefault(key,(rel,nums[0]))
    else: seen.setdefault(key,(rel,i))
tot=sum(len(v) for v in red.values()); print('redundant',tot)
print('-- top files'); 
for f,v in sorted(red.items(),key=lambda x:-len(x[1]))[:n]: print(len(v),f)
print('-- top pairs (first -> dup)')
for (a,b),c in pair.most_common(n): print(c,a,'->',b)
# common windows across many files
print('-- windows shared by most files')
for k,fs in sorted(keyfiles.items(),key=lambda x:-len(x[1]))[:15]: print(len(fs),sample[k],' | '.join(k)[:200])
