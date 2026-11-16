import re, sys, subprocess
# Move imports of main's value classes added by the rewriters into the import block.
for p in sys.argv[1:]:
    s = open(p).read()
    added = re.findall(r'^use OCA\\OpenRegister\\Kernel\\Contracts\\Value\\\w+;\n', s, re.M)
    if not added: continue
    lines = s.split('\n')
    keep = [l for l in lines if not re.match(r'^use OCA\\OpenRegister\\Kernel\\Contracts\\Value\\\w+;$', l)]
    s2 = '\n'.join(keep)
    s2 = re.sub(r'\n\n\n+', '\n\n', s2) if s2.count('\n\n\n') > s.count('\n\n\n') else s2
    uses = sorted(set(a.strip() for a in added))
    m = list(re.finditer(r'^use [A-Za-z0-9_\\]+(\s+as\s+\w+)?;[ \t]*$', s2, re.M))
    if m:
        pos = m[-1].end()
        s2 = s2[:pos] + '\n' + '\n'.join(uses) + s2[pos:]
    else:
        mm = re.search(r'^namespace [^;]+;[ \t]*\n', s2, re.M)
        s2 = s2[:mm.end()] + '\n' + '\n'.join(uses) + '\n' + s2[mm.end():]
    open(p, 'w').write(s2)
