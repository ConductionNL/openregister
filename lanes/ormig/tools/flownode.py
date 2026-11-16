import re, sys
# IFlowNode positional execute() -> ICallFlowNode execute(FlowNodeCall); BulkActionInterface apply(...) -> StepBulkActionInterface apply(BulkStep);
# LifecycleActionInterface execute(...) -> ContextLifecycleActionInterface execute(LifecycleActionContext).
def add_use(s, fq):
    if re.search(r'^use ' + re.escape(fq) + r';', s, re.M): return s
    ms = list(re.finditer(r'^use [^;]+;[ \t]*$', s, re.M))
    line = 'use ' + fq + ';'
    for m in ms:
        if m.group(0).lower() > line.lower():
            return s[:m.start()] + line + '\n' + s[m.start():]
    return s[:ms[-1].end()] + '\n' + line + s[ms[-1].end():]
SPECS = [
  ('IFlowNode', 'ICallFlowNode', 'OCA\\OpenRegister\\Service\\Flow\\', 'execute', r'array \$(\w+),\s*array \$(\w+),\s*array \$(\w+)', 'FlowNodeCall $call', 'OCA\\OpenRegister\\Service\\Flow\\FlowNodeCall', ['$call->items', '$call->config', '$call->context']),
  ('LifecycleActionInterface', 'ContextLifecycleActionInterface', 'OCA\\OpenRegister\\Lifecycle\\', 'execute', r'array \$(\w+),\s*array \$(\w+),\s*array \$(\w+),\s*string \$(\w+)', 'LifecycleActionContext $context', 'OCA\\OpenRegister\\Lifecycle\\LifecycleActionContext', ['$context->objectData', '$context->previousData', '$context->parameters', '$context->actionName']),
  ('BulkActionInterface', 'StepBulkActionInterface', 'OCA\\OpenRegister\\BulkAction\\', 'apply', r'ObjectEntity \$(\w+),\s*array \$(\w+),\s*bool \$(\w+),\s*\?IUser \$(\w+)(?:\s*=\s*null)?', 'BulkStep $step', 'OCA\\OpenRegister\\BulkAction\\BulkStep', ['$step->object', '$step->parameters', '$step->commit', '$step->actor']),
]
for p in sys.argv[1:]:
    s = open(p).read(); o = s
    for old, new, ns, meth, prx, newparam, newfq, accs in SPECS:
        if not re.search(r'^(?:final |abstract )?class \w+[^{]*\bimplements\b[^{]*\b' + old + r'\b', s, re.M): continue
        m = re.search(r'(public function ' + meth + r'\()\s*' + prx + r'\s*(\)\s*:\s*\w+\s*\{[ \t]*\n)', s)
        if not m:
            print('NOSIG', p, old); continue
        names = m.groups()[1:-1]
        ind = re.search(r'\n([ \t]*)[^\n]*public function ' + meth, s[:m.end()]).group(1)
        body_ind = ind + ('    ' if ind.startswith(' ') else '\t')
        selfvar = newparam.split('$')[1]
        bend = s.find('\n' + ind + '}', m.end())
        body = s[m.end():bend]
        pro = ''.join(f"{body_ind}${n} = {a};\n" for n, a in zip(names, accs) if n != selfvar and re.search(r'\$' + n + r'\b', body))
        if selfvar in names:
            # the request object would shadow a same-named old parameter
            print('SHADOW', p, old); continue
        s = s[:m.start()] + m.group(1) + newparam + m.groups()[-1] + pro + s[m.end():]
        s = re.sub(r'(\bimplements\b[^{]*?)\b' + old + r'\b', r'\g<1>' + new, s, count=1)
        s = add_use(s, ns + new)
        s = add_use(s, newfq)
        if not re.search(r'\b' + old + r'\b', re.sub(r'^use [^;]+;$', '', s, flags=re.M)):
            s = re.sub(r'^use ' + re.escape(ns + old) + r';\n', '', s, flags=re.M)
        print('MIGRATED', p, old)
    if s != o: open(p, 'w').write(s)

# Docblock fix-up pass: replace the @param lines of migrated methods.
DOCS = {'FlowNodeCall $call': ('FlowNodeCall', '$call', 'The items, the step config and the run context.'),
        'LifecycleActionContext $context': ('LifecycleActionContext', '$context', 'The payloads, parameters and action name.'),
        'BulkStep $step': ('BulkStep', '$step', 'The member object, the parameters, commit or rehearse, and the actor.')}
for p in sys.argv[1:]:
    s = open(p).read(); o = s
    for sigp, (t, v, d) in DOCS.items():
        for m in list(re.finditer(r'(/\*\*(?:(?!\*/).)*?\*/)(\s*public function (?:execute|apply)\(' + re.escape(sigp) + r'\))', s, re.S))[::-1]:
            doc = m.group(1)
            lines = doc.split('\n')
            idx = [i for i, l in enumerate(lines) if re.match(r'\s*\*\s*@param\b', l)]
            if not idx or any(v in lines[i] for i in idx): continue
            ind = re.match(r'(\s*\*)', lines[idx[0]]).group(1)
            # drop continuation lines of removed params too
            drop = set(idx)
            for i in idx:
                j = i + 1
                while j < len(lines) and re.match(r'\s*\*\s{3,}\S', lines[j]) and not re.match(r'\s*\*\s*@', lines[j]):
                    drop.add(j); j += 1
            new = [l for i, l in enumerate(lines) if i not in drop or i == idx[0]]
            k = new.index(lines[idx[0]])
            new[k] = f'{ind} @param {t} {v} {d}'
            s = s[:m.start(1)] + '\n'.join(new) + s[m.end(1):]
    if s != o: open(p, 'w').write(s)
