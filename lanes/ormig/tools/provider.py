import re, sys
# IntegrationProvider (release positional) -> RefIntegrationProvider (ObjectRef) for list/get/create/update/delete.
sys.path.insert(0, '/tmp/lanes/ormig/tools')
def add_use(s, fq):
    if re.search(r'^use ' + re.escape(fq) + r';', s, re.M): return s
    ms = list(re.finditer(r'^use [^;]+;[ \t]*$', s, re.M)); line = 'use ' + fq + ';'
    for m in ms:
        if m.group(0).lower() > line.lower(): return s[:m.start()] + line + '\n' + s[m.start():]
    return s[:ms[-1].end()] + '\n' + line + s[ms[-1].end():]
for p in sys.argv[1:]:
    s = open(p).read(); o = s
    if not re.search(r'^(?:final |abstract )?class \w+[^{]*\bimplements\b[^{]*\bIntegrationProvider\b', s, re.M): continue
    n = 0
    for meth in ['list', 'get', 'create', 'update', 'delete']:
        m = re.search(r'(public function ' + meth + r'\()string \$(\w+),\s*string \$(\w+),\s*string \$(\w+)(,\s*[^)]*)?(\)\s*:\s*\??\w+\s*\{[ \t]*\n)', s)
        if not m: continue
        reg, sch, oid = m.group(2), m.group(3), m.group(4)
        rest = m.group(5) or ''
        ind = re.search(r'\n([ \t]*)[^\n]*public function ' + meth + r'\(', s[:m.end()]).group(1)
        bi = ind + ('    ' if ind.startswith(' ') else '\t')
        # body of the method: up to the matching closing brace at the method's indent
        bend = s.find('\n' + ind + '}', m.end())
        body = s[m.end():bend]
        pro = ''
        for var, acc in ((reg, '(string) $ref->register'), (sch, '(string) $ref->schema'), (oid, '$ref->id')):
            if re.search(r'\$' + var + r'\b', body):
                pro += f"{bi}${var} = {acc};\n"
        s = s[:m.start()] + m.group(1) + 'ObjectRef $ref' + rest + m.group(6) + pro + s[m.end():]
        # docblock: replace the three @param lines
        dm = list(re.finditer(r'/\*\*(?:(?!\*/).)*?\*/\s*public function ' + meth + r'\(ObjectRef', s, re.S))
        if dm:
            d = dm[-1]; doc = s[d.start():d.end()]
            lines = doc.split('\n'); out = []; done = False
            for l in lines:
                if re.match(r'\s*\*\s*@param\s+\S+\s+\$(' + reg + '|' + sch + '|' + oid + r')\b', l):
                    if not done:
                        out.append(re.match(r'(\s*\*)', l).group(1) + ' @param ObjectRef $ref The register, schema and id of the object.'); done = True
                    continue
                out.append(l)
            s = s[:d.start()] + '\n'.join(out) + s[d.end():]
        n += 1
    if n:
        s = re.sub(r'(\bimplements\b[^{]*?)\bIntegrationProvider\b', r'\g<1>RefIntegrationProvider', s, count=1)
        s = add_use(s, 'OCA\\OpenRegister\\Kernel\\Contracts\\ObjectRef')
        s = add_use(s, 'OCA\\OpenRegister\\Service\\Integration\\RefIntegrationProvider')
        if not re.search(r'\bIntegrationProvider\b', re.sub(r'^use [^;]+;$', '', s, flags=re.M)):
            s = re.sub(r'^use OCA\\OpenRegister\\Service\\Integration\\IntegrationProvider;\n', '', s, flags=re.M)
        print('MIGRATED', p, n)
    if s != o: open(p, 'w').write(s)
