"""resig.py <file> <method> <newparams> <prologue> [--all]
Replace the parameter list of every (or first) `function <method>(` in <file> and insert <prologue>
(';'-separated statements, use {i} for the body indentation) at the top of the body. Skips methods
already carrying the new parameter list."""
import sys, re
f, m, newp, pro = sys.argv[1:5]
s = open(f).read(); out = []; i = 0; n = 0
for mt in re.finditer(r'function\s+' + re.escape(m) + r'\s*\(', s):
    if mt.start() < i: continue
    j = mt.end(); depth = 1
    while depth:
        c = s[j]; depth += (c == '(') - (c == ')'); j += 1
    params = s[mt.end():j - 1]
    if params.strip() == newp.strip(): continue
    k = s.index('{', j)
    # indentation of the function line
    ls = s.rfind('\n', 0, mt.start()) + 1
    ind = re.match(r'[ \t]*', s[ls:]).group(0)
    bi = ind + ('\t' if '\t' in ind or ind == '' else '    ')
    stm = ''.join(f'\n{bi}{x.strip()};' for x in pro.split(';') if x.strip())
    out.append(s[i:mt.end()] + newp + s[j - 1:k + 1] + stm)
    i = k + 1; n += 1
out.append(s[i:])
open(f, 'w').write(''.join(out))
print(f'{f}: {m} x{n}')
