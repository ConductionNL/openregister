import json,re,os,sys
app,f=sys.argv[1],sys.argv[2]
rows=json.load(open(f)); tot=sum(u['nloc'] for u in rows)
gain=[0,0,0]; n=0; cand=[]
for u in rows:
  if u['vis']!='ctor' or u['params']<3: continue
  L=open(os.path.join(app,u['file']),errors='replace').read().split('\n')[u['start']-1:u['end']]
  body=sum(1 for l in L if re.match(r'\s*\$this->\w+\s*=\s*\$\w+;\s*$',l))
  if body: n+=1; cand.append((body,u['file']))
  for i,b in enumerate((3,5,7)):
    if u['params']>=b: gain[i]+=body
print(app,'ctors with plain assigns',n,'removable lines per check',gain,[round(g/tot*100,2) for g in gain])
