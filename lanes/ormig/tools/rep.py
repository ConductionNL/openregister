"""rep.py <file> <oldfile> <newfile> [use FQCN...]: replace one exact occurrence, add sorted use lines."""
import sys, re
p, old, new = sys.argv[1], open(sys.argv[2]).read(), open(sys.argv[3]).read()
s = open(p).read()
assert s.count(old) == 1, (p, s.count(old))
s = s.replace(old, new)
for u in sys.argv[4:]:
    line = f"use {u};\n"
    if line in s: continue
    ms = list(re.finditer(r'^use [^;]+;\n', s, re.M))
    pos = next((m.start() for m in ms if m.group(0).lower() > line.lower()), ms[-1].end())
    s = s[:pos] + line + s[pos:]
open(p, 'w').write(s)
