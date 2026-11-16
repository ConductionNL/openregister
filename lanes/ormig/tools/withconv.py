import re, sys, os
# usage: withconv.py <support-fqcn> <file> <method> <name1,name2,...> [<method> <names> ...]
# Rewrites ->method('M')...->with(a, b, c) into ->with(...LegacyObjectArgs::with(method: 'M', expected: [n1 => a, ...]))
support = sys.argv[1]; p = sys.argv[2]; specs = sys.argv[3:]
s = open(p).read()
def split_args(t):
    out, depth, cur, q = [], 0, '', None
    i = 0
    while i < len(t):
        c = t[i]
        if q:
            cur += c
            if c == '\\': cur += t[i+1]; i += 2; continue
            if c == q: q = None
        elif c in '\'"': q = c; cur += c
        elif c in '([{': depth += 1; cur += c
        elif c in ')]}': depth -= 1; cur += c
        elif c == ',' and depth == 0: out.append(cur); cur = ''
        else: cur += c
        i += 1
    if cur.strip(): out.append(cur)
    return [a.strip() for a in out]
n = 0
for k in range(0, len(specs), 2):
    meth, names = specs[k], specs[k+1].split(',')
    pos = 0
    while True:
        m = re.compile(r"->method\('" + meth + r"'\)\s*->with\(").search(s, pos)
        if not m: break
        start = m.end(); depth = 1; i = start; q = None
        while depth:
            c = s[i]
            if q:
                if c == '\\': i += 2; continue
                if c == q: q = None
            elif c in '\'"': q = c
            elif c == '(': depth += 1
            elif c == ')': depth -= 1
            i += 1
        inner = s[start:i-1]
        stmt = s[max(s.rfind(';', 0, m.start()), s.rfind('{', 0, m.start()), s.rfind('}', 0, m.start())) + 1:m.start()]
        recv = re.split(r'->(?:expects|method)\(', stmt.strip())[0].strip()
        if not re.search(os.environ.get('WITH_RECV', r'bj|^\$os$|^\$this->os$|^\$service$'), recv):
            print('WITHRECV', p, meth, recv); pos = i; continue
        if 'LegacyObjectArgs::with' in inner: pos = i; continue
        args = split_args(inner)
        if len(args) > len(names): print('TOO MANY', p, meth, len(args)); pos = i; continue
        ind = re.search(r'\n([ \t]*)[^\n]*$', s[:m.start()]).group(1)
        pairs = []
        for nm, a in zip(names, args):
            mm = re.match(r'^(\w+)\s*:(?!:)\s*(.*)$', a, re.S)
            if mm: nm, a = mm.group(1), mm.group(2)
            pairs.append((nm, a))
        body = ',\n'.join(f"{ind}\t\t'{nm}' => {a}" for nm, a in pairs)
        new = f"...LegacyObjectArgs::with(method: '{meth}', expected: [\n{body},\n{ind}\t])"
        s = s[:start] + new + s[i-1:]
        pos = start + len(new); n += 1
if n and not re.search(r'^use ' + re.escape(support) + ';', s, re.M):
    ms = list(re.finditer(r'^use [^;]+;[ \t]*$', s, re.M))
    s = s[:ms[-1].end()] + '\nuse ' + support + ';' + s[ms[-1].end():]
open(p, 'w').write(s)
print('WITH', p, n)
