import re, sys
# saveObject(...$arguments) where $arguments is built from release names in the same method -> main's form.
def add_use(s, fq):
    if re.search(r'^use ' + re.escape(fq) + r';', s, re.M): return s
    ms = list(re.finditer(r'^use [^;]+;[ \t]*$', s, re.M)); line = 'use ' + fq + ';'
    for m in ms:
        if m.group(0).lower() > line.lower(): return s[:m.start()] + line + '\n' + s[m.start():]
    return s[:ms[-1].end()] + '\n' + line + s[ms[-1].end():]
for p in sys.argv[1:]:
    s = open(p).read(); o = s
    for m in list(re.finditer(r'->saveObject\(\.\.\.\$(\w+)\)', s))[::-1]:
        var = m.group(1)
        before = s[:m.start()]
        fstart = before.rfind('function ')
        body = before[fstart:]
        keys = set(re.findall(r"'(\w+)'\s*=>", body[body.rfind('$' + var + ' = ['):] if ('$' + var + ' = [') in body else ''))
        keys |= set(re.findall(r'\$' + var + r"\['(\w+)'\]\s*=", body))
        allowed = {'object', 'register', 'schema', 'uuid', 'extend', '_rbac', '_multitenancy'}
        if not keys or not keys <= allowed or 'object' not in keys:
            print('SKIP', p, sorted(keys)); continue
        a = lambda k: f"${var}['{k}']" if k in ('object',) else f"(${var}['{k}'] ?? null)"
        opts = [f"target: new ObjectFindTarget(register: {a('register')}, schema: {a('schema')})"]
        if 'uuid' in keys: opts.append(f"uuid: {a('uuid')}")
        if 'extend' in keys: opts.append(f"extend: (${var}['extend'] ?? [])")
        call = f"->saveObject(\n\t\t\t\tobject: ${var}['object'],\n\t\t\t\toptions: new ObjectSaveOptions({', '.join(opts)})"
        for f in ('_rbac', '_multitenancy'):
            if f in keys: call += f",\n\t\t\t\t{f}: (${var}['{f}'] ?? true)"
        call += "\n\t\t\t)"
        s = s[:m.start()] + call + s[m.end():]
        print('SPREAD', p, sorted(keys))
    if s != o:
        s = add_use(s, 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectFindTarget')
        s = add_use(s, 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectSaveOptions')
        open(p, 'w').write(s)
