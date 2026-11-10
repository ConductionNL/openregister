#!/usr/bin/env python3
"""Rewrite ObjectService find()/findSilent() calls to the ObjectFindOptions shape.

Old: find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit)
New: find($id, ?ObjectFindOptions $options, $_rbac, $_multitenancy)

usage: migrate-find.py [--write] <app-dir> [<app-dir> ...]
Without --write it is a dry run. Every candidate call prints one line:
  CHANGE <file>:<line> | SKIP <file>:<line> <reason>
Calls already in the new shape (or that need no change) print nothing.
"""
import bisect
import os
import re
import sys

POS = ['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit']
OPT = {'register': 'register', 'schema': 'schema', '_extend': 'extend', 'files': 'files', '_render': 'render', '_audit': 'audit'}
DEFAULT = {'extend': '[]', 'files': 'false', 'render': 'true', 'audit': 'true'}
FQCN = 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectFindOptions'
USE = 'use ' + FQCN + ';'
# Receiver must name the object service explicitly; anything else is not touched.
OR_TYPES = ('ObjectService', 'ObjectServiceInterface')
SCALARS = ('null', 'array', 'string', 'int', 'float', 'bool', 'false', 'true', 'mixed', 'object', 'iterable', 'callable',
           'self', 'static', 'void', 'never')
RECEIVER = re.compile(r'((objectservice|objectoperations)\s*(\(\s*\))?|getOpenRegisters\s*\(\s*\)|(\$|->\s*)openRegisters?)\s*$', re.I)
PROP = re.compile(r'\$this\s*\??->\s*(\w+)\s*$')
CALL = re.compile(r'(\??->)\s*(find|findSilent)\s*\(')


def skip_string(s, j):
    q = s[j]
    j += 1
    while j < len(s) and s[j] != q:
        if s[j] == '\\':
            j += 1
        j += 1
    return j


def split_args(s, i):
    """Top-level argument spans of the call whose '(' is at i-1; returns (spans, close_index, has_comment)."""
    depth = 0
    j = i
    cur = i
    spans = []
    comment = False
    while j < len(s):
        c = s[j]
        if c in '\'"':
            j = skip_string(s, j)
        elif s.startswith('//', j) or s.startswith('/*', j) or (c == '#' and not s.startswith('#[', j)):
            comment = True
            end = s.find('*/', j) + 2 if s.startswith('/*', j) else s.find('\n', j)
            j = end if end > j else len(s)
            continue
        elif c in '([{':
            depth += 1
        elif c in ')]}':
            if depth == 0:
                spans.append((cur, j))
                return spans, j, comment
            depth -= 1
        elif c == ',' and depth == 0:
            spans.append((cur, j))
            cur = j + 1
        j += 1
    return None, None, comment


def receiver_of(s, start):
    """The expression text just before '->' (same statement, up to 120 chars)."""
    k = start
    depth = 0
    while k > 0 and start - k < 120:
        c = s[k - 1]
        if c in ')]':
            depth += 1
        elif c in '([':
            if depth == 0:
                break
            depth -= 1
        elif depth == 0 and not (c.isalnum() or c in '_$>-:\\?' or c.isspace()):
            break
        k -= 1
    return s[k:start].strip()


def code_mask(s):
    """Sorted (start, end) spans of `s` that are not PHP code: strings, heredocs, comments, inline HTML."""
    spans = []
    n = len(s)
    k = s.find('<?')
    k = n if k < 0 else k
    if k > 0:
        spans.append((0, k))
    i = k
    heredoc = re.compile(r'<<<[ \t]*(["\']?)([A-Za-z_]\w*)\1\r?\n')
    while i < n:
        c = s[i]
        if s.startswith('?>', i):
            k = s.find('<?', i + 2)
            k = n if k < 0 else k
            spans.append((i + 2, k))
            i = k + 2
            continue
        if c in '\'"`':
            j = min(skip_string(s, i) + 1, n)
        elif s.startswith('<<<', i) and heredoc.match(s, i):
            h = heredoc.match(s, i)
            e = re.compile(r'\n[ \t]*' + h.group(2) + r'\b').search(s, h.end() - 1)
            j = e.end() if e else n
        elif s.startswith('/*', i):
            j = s.find('*/', i + 2)
            j = n if j < 0 else j + 2
        elif s.startswith('//', i) or (c == '#' and not s.startswith('#[', i)):
            j = s.find('\n', i)
            j = n if j < 0 else j
        else:
            i += 1
            continue
        spans.append((i, j))
        i = j
    return spans


def in_mask(spans, pos):
    k = bisect.bisect_right(spans, (pos, float('inf'))) - 1
    return k >= 0 and spans[k][0] <= pos < spans[k][1]


def imports(s):
    """Map of short name to imported class for every top-level `use`."""
    out = {}
    for m in re.finditer(r'^use\s+([^;]+);', s, re.M):
        t = m.group(1).strip()
        if t.startswith(('function ', 'const ')) or '{' in t:
            continue
        alias = re.split(r'\s+as\s+', t)
        out[alias[1] if len(alias) > 1 else alias[0].split('\\')[-1]] = alias[0].lstrip('\\')
    return out


def resolve(s, imp, t):
    """Fully qualified names of the classes in the type expression `t`."""
    ns = re.search(r'^namespace\s+([^;{\s]+)', s, re.M)
    ns = ns.group(1) + '\\' if ns else ''
    out = []
    for part in re.split(r'[|&]', t.replace('?', '').replace('(', '').replace(')', '')):
        part = part.strip()
        if not part or part.lower() in SCALARS:
            continue
        if part.startswith('\\'):
            out.append(part[1:])
        else:
            head, _, rest = part.partition('\\')
            out.append(imp[head] + ('\\' + rest if rest else '') if head in imp else ns + part)
    return out


def is_foreign(s, imp, t):
    """True when `t` names only classes outside openregister (PHPUnit mock types do not count)."""
    fq = [f for f in resolve(s, imp, t) if not f.startswith('PHPUnit\\')]
    return bool(fq) and not any(f.startswith('OCA\\OpenRegister\\') for f in fq)


def is_or_service(s, imp, t):
    """True when the type expression `t` names openregister's ObjectService or ObjectServiceInterface."""
    return any(f.startswith('OCA\\OpenRegister\\') and f.split('\\')[-1] in OR_TYPES for f in resolve(s, imp, t))


TYPE = r'(\??[\\\w]+(?:\s*[|&]\s*\??[\\\w]+)*)'


def typed_receivers(s, mask):
    """Receiver patterns this file types as openregister's object service.

    A property typed ObjectService(Interface) by declaration or constructor promotion, a property assigned
    from a constructor parameter of that type, a method returning that type, and `$this->objects` unless
    it is declared with another type. Also returns the properties declared with a class from outside
    openregister, which are never the object service whatever their name.
    """
    imp = imports(s)
    props, other, foreign = set(), set(), {}
    for m in re.finditer(r'(?:\b(?:public|protected|private|readonly|var|static)\s+)+' + TYPE + r'\s+(?:&\s*)?\$(\w+)', s):
        if not in_mask(mask, m.start()):
            (props if is_or_service(s, imp, m.group(1)) else other).add(m.group(2))
            if is_foreign(s, imp, m.group(1)):
                foreign[m.group(2)] = m.group(1)
    for m in re.finditer(r'\bfunction\s+__construct\s*\(', s):
        if in_mask(mask, m.start()):
            continue
        spans, _, _ = split_args(s, m.end())
        for a, b in spans or []:
            p = re.match(r'^\s*(?:#\[[^\]]*\]\s*)*' + TYPE + r'\s+(?:&\s*)?\$(\w+)', s[a:b])
            if p and is_or_service(s, imp, p.group(1)):
                for q in re.finditer(r'\$this->(\w+)\s*=\s*\$' + p.group(2) + r'\s*;', s):
                    props.add(q.group(1))
    methods = {m.group(1) for m in re.finditer(r'\bfunction\s+(\w+)\s*\([^)]*\)\s*:\s*' + TYPE, s)
               if is_or_service(s, imp, m.group(2))}
    if 'objects' not in other:
        props.add('objects')
    pats = [r'\$this\s*\??->\s*' + p + r'\s*$' for p in sorted(props)]
    pats += [r'\$this\s*\??->\s*' + f + r'\s*\(\s*\)\s*$' for f in sorted(methods)]
    return (re.compile('|'.join(pats)) if pats else None), {k: v for k, v in foreign.items() if k not in props}


SLUG = re.compile(r'^(\$this\s*->\s*|\$|[\\\w]+::)\w*?(register|schema)\w*$', re.I)


def misplaced(args):
    """WARN reason when a positional argument may follow another parameter order than the one mapped."""
    for name, val, named in args:
        if named:
            continue
        if name.lstrip('_') == 'extend' and val.strip() != '[]':
            return 'WARN positional %s lands in extend' % val
        kind = SLUG.match(val.strip())
        if kind and kind.group(2).lower() != name:
            return 'WARN positional %s looks like a %s but lands in %s' % (val, kind.group(2).lower(), name)
    return None


def plan(s, m):
    """Return (new_text, end) for the call, None when nothing to do, or a skip reason string."""
    spans, end, comment = split_args(s, m.end())
    if spans is None:
        return 'unbalanced'
    raw = [s[a:b] for a, b in spans]
    trailing = ''
    if raw and raw[-1].strip() == '':
        raw = raw[:-1]
        trailing = ','
    if not raw:
        return None
    if comment:
        return 'comment inside the argument list'
    args = []
    seen_named = False
    for k, r in enumerate(raw):
        t = r.strip()
        if t.startswith('...'):
            return 'spread argument'
        nm = re.match(r'^([A-Za-z_]\w*)\s*:(?!:)\s*(.*)$', t, re.S)
        if nm:
            seen_named = True
            args.append((nm.group(1), nm.group(2).strip(), True))
        else:
            if seen_named or k >= len(POS):
                return 'positional argument after named or past the old list'
            args.append((POS[k], t, False))
    names = [a[0] for a in args]
    if 'options' in names:
        return None
    unknown = [n for n in names if n not in POS]
    if unknown:
        return 'unknown parameter ' + ','.join(unknown)
    if len(set(names)) != len(names):
        return 'duplicate parameter'
    if 'id' not in names:
        return 'no id argument'
    needs = any(n in OPT for n in names) or any((not a[2]) and a[0] not in ('id',) for a in args)
    if not needs:
        return None
    if len(args) >= 2 and not args[1][2] and re.match(r'^(new\s+\\?\w*ObjectFindOptions\b|null$)', args[1][1]):
        return None
    warn = misplaced(args)
    if warn:
        return warn
    opts = []
    for key in ['register', 'schema', '_extend', 'files', '_render', '_audit']:
        for a in args:
            if a[0] == key:
                o = OPT[key]
                if DEFAULT.get(o) == a[1]:
                    continue
                opts.append('%s: %s' % (o, a[1]))
    out = []
    for a in args:
        if a[0] == 'id':
            out.append('id: ' + a[1] if a[2] else a[1])
    if opts:
        out.append('options: new ObjectFindOptions(' + ', '.join(opts) + ')')
    out += ['%s: %s' % (a[0], a[1]) for a in args if a[0] in ('_rbac', '_multitenancy')]
    inner = s[m.end():end]
    if '\n' in inner:
        ind = re.search(r'\n([ \t]*)\S', inner).group(1)
        close = re.search(r'\n([ \t]*)$', inner)
        new = '\n' + ',\n'.join(ind + o for o in out) + trailing + '\n' + (close.group(1) if close else '')
    else:
        new = ', '.join(out)
    return new, end


def add_use(s):
    if re.search(r'^use\s+' + re.escape(FQCN) + r'\s*;', s, re.M):
        return s
    lines = s.split('\n')
    idx = [i for i, l in enumerate(lines) if re.match(r'^use [A-Za-z\\]', l)]
    if not idx:
        ns = [i for i, l in enumerate(lines) if l.startswith('namespace ')]
        if not ns:
            return None
        lines[ns[0] + 1:ns[0] + 1] = ['', USE]
        return '\n'.join(lines)
    pos = next((i for i in idx if lines[i].lower() > USE.lower()), idx[-1] + 1)
    lines.insert(pos, USE)
    return '\n'.join(lines)


def name_clash(s):
    """Another class imported or declared as ObjectFindOptions."""
    for m in re.finditer(r'^use\s+([^;]+);', s, re.M):
        t = m.group(1).strip()
        alias = re.split(r'\s+as\s+', t)
        short = alias[1] if len(alias) > 1 else alias[0].split('\\')[-1]
        if short == 'ObjectFindOptions' and alias[0].lstrip('\\') != FQCN:
            return True
    return re.search(r'\b(class|interface|trait|enum)\s+ObjectFindOptions\b', s) is not None


def process(path, write, report):
    s = open(path, encoding='utf-8', errors='surrogateescape').read()
    if '->find' not in s and '->findSilent' not in s:
        return 0
    mask = code_mask(s)
    typed, foreign = typed_receivers(s, mask)
    edits = []
    for m in CALL.finditer(s):
        if in_mask(mask, m.start()):
            continue
        recv = receiver_of(s, m.start())
        if not (RECEIVER.search(recv) or (typed and typed.search(recv))):
            continue
        line = s.count('\n', 0, m.start()) + 1
        r = plan(s, m)
        if r is None:
            continue
        prop = PROP.search(recv)
        if prop and prop.group(1) in foreign:
            report.append('SKIP %s:%d receiver is typed %s, not openregister' % (path, line, foreign[prop.group(1)]))
            continue
        if isinstance(r, str) and r.startswith('WARN '):
            report.append('WARN %s:%d %s' % (path, line, r[5:]))
            continue
        if isinstance(r, str):
            report.append('SKIP %s:%d %s' % (path, line, r))
            continue
        edits.append((m.end(), r[1], r[0], line))
    if not edits:
        return 0
    if name_clash(s):
        for e in edits:
            report.append('SKIP %s:%d another ObjectFindOptions is in scope' % (path, e[3]))
        return 0
    for start, end, new, line in sorted(edits, reverse=True):
        s = s[:start] + new + s[end:]
    s2 = add_use(s) if 'new ObjectFindOptions(' in s else s
    if s2 is None:
        for e in edits:
            report.append('SKIP %s:%d no namespace to import into' % (path, e[3]))
        return 0
    for e in sorted(edits, key=lambda e: e[3]):
        report.append('CHANGE %s:%d' % (path, e[3]))
    if write:
        open(path, 'w', encoding='utf-8', errors='surrogateescape').write(s2)
    return len(edits)


def main(argv):
    write = '--write' in argv
    dirs = [a for a in argv if a != '--write']
    if not dirs:
        print(__doc__)
        return 2
    report = []
    for d in dirs:
        for dp, dn, fs in os.walk(d):
            dn[:] = [x for x in dn if x not in ('vendor', 'node_modules', '.git')]
            for f in sorted(fs):
                if f.endswith('.php'):
                    process(os.path.join(dp, f), write, report)
    for r in report:
        print(r)
    ch = sum(1 for r in report if r.startswith('CHANGE'))
    sk = sum(1 for r in report if r.startswith('SKIP'))
    wn = sum(1 for r in report if r.startswith('WARN'))
    print('# %s: %d changed, %d skipped, %d warned' % ('written' if write else 'dry run', ch, sk, wn))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
