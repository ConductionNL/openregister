import re, sys
# Add methods main's ObjectServiceInterface declares to test classes that implement it but lack them.
iface = open('/tmp/lanes/ormig/openregister/lib/Contract/ObjectServiceInterface.php').read()
decls = {m.group(1): m.group(0) for m in re.finditer(r'public function (\w+)\((?:[^;{]|\n)*?\)\s*(?::\s*[^;]+)?;', iface)}
uses = re.findall(r'^use ([^;]+);', iface, re.M)
for p in sys.argv[1:]:
    s = open(p).read()
    for cm in re.finditer(r'\n(\t*)(?:final |abstract )?class (\w+)[^{\n]*\bimplements\b[^{\n]*\bObjectServiceInterface\b[^{\n]*\{', s):
        if 'abstract class' in cm.group(0): continue
        ind = cm.group(1)
        # class body end: next line that is ind + '}'
        start = cm.end()
        m_end = re.compile(r'\n' + re.escape(ind) + r'\}').search(s, start)
        body = s[start:m_end.start()]
        missing = [n for n in decls if not re.search(r'function ' + n + r'\(', body)]
        if not missing: continue
        add = ''
        for n in missing:
            d = re.sub(r'\s+', ' ', decls[n])[:-1]
            d = re.sub(r'\b(ObjectSaveOptions|ObjectFindOptions|ObjectDeletion|ObjectPatch|LockRequest|UnlockRequest|BulkSaveOptions|ObjectValidationVerdict)\b', r'\\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\\1', d)
            d = re.sub(r'\bObjectEntityInterface\b', r'\\OCA\\OpenRegister\\Contract\\ObjectEntityInterface', d)
            d = re.sub(r'\?IUser\b', r'?\\OCP\\IUser', d)
            d = d.replace('\\\\', '\\')
            add += f"\n{ind}\t/**\n{ind}\t * Not used by this double.\n{ind}\t */\n{ind}\t{d} {{\n{ind}\t\tthrow new \\LogicException('{n}() is not modelled by this double');\n{ind}\t}}\n"
        s = s[:m_end.start()] + add + s[m_end.start():]
        print('ADDED', p, cm.group(2), ','.join(missing))
        break
    open(p, 'w').write(s)
