import json,sys
r=json.load(open(sys.argv[1]))
R=r['rating']; print('overall',R['overall']); print('subs',R['subcharacteristics'])
for k,v in R['properties'].items(): print(f"  {k:24} {v['rating']}  ", {l:(x['rating'],x['loc']) for l,x in v['languages'].items()})
for l,d in r['languages'].items():
  print(l,'unit_loc',d.get('unit_loc'))
  for p in ('unit_size','unit_complexity','unit_interfacing'): print('  ',p,[(x['above'],round(x['share'],4),x.get('loc')) for x in d[p]])
  if d.get('duplication'): print('   dup',{k:d['duplication'][k] for k in ('share','code_lines','redundant_lines')})
for l,s in (r.get('structure') or {}).items():
  if not s: continue
  print(l,'module_loc',s['module_loc'],'coupling',[(x['above'],round(x['share'],4)) for x in s['module_coupling']],'ind',s['component_independence'].get('hidden_share'),'ent',s['component_entanglement']['violation_degree'])
for lv in R['levers'][:8]: print('  lever',lv['property'],lv['language'],lv['fixable_loc'],lv['gain_per_kloc'],lv['loc_for_half_star'])
