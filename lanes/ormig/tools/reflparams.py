import re, sys
# (new ReflectionMethod(X::class, $method))->getParameters() -> LegacyObjectArgs::parameters(X::class, $method)
for p in sys.argv[1:]:
    s = open(p).read()
    s2 = re.sub(r"\(new \\?ReflectionMethod\(([\w\\]+::class|\$\w+), (\$\w+|'\w+')\)\)->getParameters\(\)", r"LegacyObjectArgs::parameters(\1, \2)", s)
    if s2 != s:
        open(p, 'w').write(s2); print('REFL', p)
