import sys
# Resolve conflicts in scripts/component-map.csv: union of rows, one row per file; main's (theirs) row wins.
p=sys.argv[1]; L=open(p).read().split('\n'); out=[]; i=0
while i<len(L):
    if L[i].startswith('<<<<<<< '):
        j=L.index('=======',i); k=next(x for x in range(j,len(L)) if L[x].startswith('>>>>>>> '))
        ours=L[i+1:j]; theirs=L[j+1:k]; tk={r.split(',')[0] for r in theirs}
        out+= [r for r in ours if r.split(',')[0] not in tk] + theirs; i=k+1
    else: out.append(L[i]); i+=1
# global duplicates (a key resolved in one hunk and present elsewhere): keep the last-seen? report
keys={}
for n,r in enumerate(out):
    if r and not r.startswith('file,'): keys.setdefault(r.split(',')[0],[]).append(n)
d={k:v for k,v in keys.items() if len(v)>1}
if d: print('duplicate rows:',list(d)[:10])
open(p,'w').write('\n'.join(out))
