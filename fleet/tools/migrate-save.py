#!/usr/bin/env python3
"""Rewrite ObjectService saveObject()/deleteObject()/saveObjects() calls to their value-object shapes.

saveObject   (openregister #9123)
  Old: saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent,
                  $_validation, $uploadedFiles, $currentUser, $failIfExists, $_unowned, $_dedupOverride, $_owner)
  New: saveObject($object, ObjectSaveOptions $options, $_rbac, $_multitenancy)
deleteObject (openregister #9099)
  Old: deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent)
  New: deleteObject(ObjectDeletion $deletion, $_rbac, $_multitenancy, $_retentionSweep)
saveObjects  (openregister #9108)
  Old: saveObjects($objects, $register, $schema, $_rbac, $_multitenancy, $validation, $events,
                   $deduplicateIds, $enrich, $_audit)
  New: saveObjects($objects, BulkSaveOptions $options, $_rbac, $_multitenancy)

usage: migrate-save.py [--write] [--only=saveObject,deleteObject,saveObjects] <app-dir> [<app-dir> ...]
Without --write it is a dry run. Every candidate call prints one line:
  CHANGE <file>:<line> <method> | SKIP <file>:<line> <method> <reason>
Calls already in the new shape (or that need no change) print nothing.
"""
import os
import re
import sys

NS = 'OCA\\OpenRegister\\Service\\Object\\'
MAX_LINE = 150

# A field is (constructor parameter, old parameter) or (constructor parameter, nested class, [fields]).
SPECS = {
    'saveObject': {
        'pos': ['object', 'extend', 'register', 'schema', 'uuid', '_rbac', '_multitenancy', 'silent', '_validation',
                'uploadedFiles', 'currentUser', 'failIfExists', '_unowned', '_dedupOverride', '_owner'],
        'outside': 'object',
        'keep': ['_rbac', '_multitenancy'],
        'dead': [],
        'param': 'options',
        'cls': 'ObjectSaveOptions',
        'always': False,
        'fields': [
            ('target', 'ObjectFindTarget', [('register', 'register'), ('schema', 'schema')]),
            ('uuid', 'uuid'),
            ('extend', 'extend'),
            ('uploadedFiles', 'uploadedFiles'),
            ('policy', 'SavePolicy', [('silent', 'silent'), ('validation', '_validation'),
                                      ('failIfExists', 'failIfExists'), ('dedupOverride', '_dedupOverride')]),
            ('actor', 'SaveActor', [('currentUser', 'currentUser'), ('owner', '_owner'), ('unowned', '_unowned')]),
        ],
        'default': {'extend': '[]', 'register': 'null', 'schema': 'null', 'uuid': 'null', 'silent': 'false',
                    '_validation': 'true', 'uploadedFiles': 'null', 'currentUser': 'null', 'failIfExists': 'false',
                    '_unowned': 'false', '_dedupOverride': 'false', '_owner': 'null'},
    },
    'deleteObject': {
        'pos': ['uuid', 'register', 'schema', '_rbac', '_multitenancy', '_retentionSweep', 'currentUser', 'permanent'],
        'outside': None,
        'keep': ['_rbac', '_multitenancy', '_retentionSweep'],
        'dead': [],
        'param': 'deletion',
        'cls': 'ObjectDeletion',
        'always': True,
        'fields': [
            ('uuid', 'uuid'),
            ('target', 'ObjectFindTarget', [('register', 'register'), ('schema', 'schema')]),
            ('currentUser', 'currentUser'),
            ('permanent', 'permanent'),
        ],
        'default': {'register': 'null', 'schema': 'null', 'currentUser': 'null', 'permanent': 'false'},
    },
    'saveObjects': {
        'pos': ['objects', 'register', 'schema', '_rbac', '_multitenancy', 'validation', 'events', 'deduplicateIds',
                'enrich', '_audit'],
        'outside': 'objects',
        'keep': ['_rbac', '_multitenancy'],
        # Accepted and never read by openregister before #9108; the new signature drops them.
        'dead': ['deduplicateIds', 'enrich'],
        'param': 'options',
        'cls': 'BulkSaveOptions',
        'always': False,
        'fields': [
            ('defaults', 'BulkBatchDefaults', [('register', 'register'), ('schema', 'schema')]),
            ('validation', 'validation'),
            ('events', 'events'),
            ('audit', '_audit'),
        ],
        'default': {'register': 'null', 'schema': 'null', 'validation': 'false', 'events': 'false', '_audit': 'true'},
    },
}
NEW_CLASSES = ['ObjectSaveOptions', 'ObjectFindTarget', 'SavePolicy', 'SaveActor', 'ObjectDeletion',
               'BulkSaveOptions', 'BulkBatchDefaults']
# Receiver must name the object service explicitly; anything else is not touched.
RECEIVER = re.compile(r'(objectservice|objectoperations)\s*(\(\s*\))?\s*$', re.I)
CALL = re.compile(r'(\??->)\s*(saveObjects|saveObject|deleteObject)\s*\(')


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


def norm(v):
    v = v.strip()
    return v.lower() if v.lower() in ('true', 'false', 'null') else v


def is_new_object(v):
    return re.match(r'^new\s+\\?(\w+\\)*(' + '|'.join(NEW_CLASSES) + r')\b', v) is not None


def build(fields, vals, spec, used):
    """Constructor arguments for one value object; adds every class it builds to `used`."""
    out = []
    for f in fields:
        if len(f) == 3:
            inner = build(f[2], vals, spec, used)
            if inner:
                used.add(f[1])
                out.append((f[0], f[1], inner))
        elif f[1] in vals and spec['default'].get(f[1]) != norm(vals[f[1]]):
            out.append('%s: %s' % (f[0], vals[f[1]]))
    return out


def plan(s, m, spec):
    """Return (new_text, end, used_classes), None when nothing to do, or a skip reason string."""
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
    pos = spec['pos']
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
            if seen_named or k >= len(pos):
                return 'positional argument after named or past the old list'
            args.append((pos[k], t, False))
    names = [a[0] for a in args]
    if spec['param'] in names:
        return None
    slot = 1 if spec['outside'] else 0
    if len(args) > slot and not args[slot][2]:
        v = args[slot][1]
        if is_new_object(v):
            return None
        hint = 'option' if spec['outside'] else 'deletion'
        if re.match(r'^\$\w*' + hint, v, re.I):
            return 'positional %s may already be an %s' % (v, spec['cls'])
    unknown = [n for n in names if n not in pos]
    if unknown:
        return 'unknown parameter ' + ','.join(unknown)
    if len(set(names)) != len(names):
        return 'duplicate parameter'
    required = spec['outside'] or 'uuid'
    if required not in names:
        return 'no %s argument' % required
    for a in args:
        if a[0] in spec['dead'] and '(' in a[1]:
            return 'dropped argument %s has a call in it' % a[0]
    lead = (spec['outside'],) if spec['outside'] else ()
    moved = [a for a in args if a[0] not in lead + tuple(spec['keep'])]
    if not moved and all(a[2] or a[0] in lead for a in args):
        return None
    vals = {a[0]: a[1] for a in args}
    used = set()
    ctor = build(spec['fields'], vals, spec, used)
    out = []
    for a in args:
        if a[0] == spec['outside']:
            out.append(('%s: %s' % (a[0], a[1])) if a[2] else a[1])
    if ctor or spec['always']:
        used.add(spec['cls'])
        out.append((spec['param'], spec['cls'], ctor))
    out += ['%s: %s' % (a[0], a[1]) for a in args if a[0] in spec['keep']]
    return render(s, m, end, out, trailing), end, used


def indent_unit(s):
    return '\t' if re.search(r'^\t', s, re.M) else '    '


def render(s, m, end, out, trailing):
    inner = s[m.end():end]
    unit = indent_unit(s)
    if '\n' in inner:
        ind = re.search(r'\n([ \t]*)\S', inner).group(1)
        close = re.search(r'\n([ \t]*)$', inner)
        close = close.group(1) if close else ''
        return multiline(out, ind, unit, close, trailing)
    flat = ', '.join(flatten(o) for o in out)
    line_start = s.rfind('\n', 0, m.start()) + 1
    line_end = s.find('\n', end)
    line_end = len(s) if line_end < 0 else line_end
    if len((s[line_start:m.end()] + flat + s[end:line_end]).expandtabs(4)) <= MAX_LINE:
        return flat
    base = re.match(r'[ \t]*', s[line_start:]).group(0)
    return multiline(out, base + unit, unit, base, '')


def flatten(o):
    """One argument on one line; o is a string or (name, class, [arguments])."""
    if isinstance(o, str):
        return o
    return '%s: new %s(%s)' % (o[0], o[1], ', '.join(flatten(c) for c in o[2]))


def expand(o, ind, unit, top):
    """One argument at indent `ind`; a value object goes multi-line when it is top-level or too long."""
    one = ind + flatten(o)
    if isinstance(o, str) or not o[2] or (not top and len(one.expandtabs(4)) <= MAX_LINE):
        return one
    body = ',\n'.join(expand(c, ind + unit, unit, False) for c in o[2])
    return '%s%s: new %s(\n%s\n%s)' % (ind, o[0], o[1], body, ind)


def multiline(out, ind, unit, close, trailing):
    parts = [expand(o, ind, unit, True) for o in out]
    return '\n' + ',\n'.join(parts) + trailing + '\n' + close


def add_use(s, short):
    fqcn = NS + short
    use = 'use ' + fqcn + ';'
    if re.search(r'^use\s+' + re.escape(fqcn) + r'\s*;', s, re.M):
        return s
    lines = s.split('\n')
    idx = [i for i, l in enumerate(lines) if re.match(r'^use [A-Za-z\\]', l)]
    if not idx:
        ns = [i for i, l in enumerate(lines) if l.startswith('namespace ')]
        if not ns:
            return None
        lines[ns[0] + 1:ns[0] + 1] = ['', use]
        return '\n'.join(lines)
    pos = next((i for i in idx if lines[i].lower() > use.lower()), idx[-1] + 1)
    lines.insert(pos, use)
    return '\n'.join(lines)


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


def name_clash(s, short):
    """Another class imported, aliased or declared as `short`."""
    imp = imports(s)
    if short in imp and imp[short] != NS + short:
        return True
    return re.search(r'\b(class|interface|trait|enum)\s+' + short + r'\b', s) is not None


def foreign_service(s):
    """A non-openregister class whose name says object service, so the receiver may be a fake."""
    for short, fqcn in imports(s).items():
        if re.search(r'objectservice|objectoperations', fqcn.split('\\')[-1], re.I) and \
                not fqcn.startswith('OCA\\OpenRegister\\'):
            return fqcn
    m = re.search(r'\b(class|interface)\s+(\w*(ObjectService|ObjectOperations)\w*)\b', s, re.I)
    ns = re.search(r'^namespace\s+([^;]+);', s, re.M)
    if m and not (ns and ns.group(1).startswith('OCA\\OpenRegister')):
        return m.group(2)
    return None


def process(path, write, only, report):
    s = open(path, encoding='utf-8', errors='surrogateescape').read()
    if not any('->' + meth in s or '?->' + meth in s for meth in only):
        return 0
    edits = []
    for m in CALL.finditer(s):
        meth = m.group(2)
        if meth not in only:
            continue
        recv = receiver_of(s, m.start())
        if not RECEIVER.search(recv):
            continue
        line = s.count('\n', 0, m.start()) + 1
        r = plan(s, m, SPECS[meth])
        if r is None:
            continue
        if isinstance(r, str):
            report.append('SKIP %s:%d %s %s' % (path, line, meth, r))
            continue
        edits.append((m.end(), r[1], r[0], line, meth, r[2]))
    if not edits:
        return 0
    nested = set()
    for a in edits:
        for b in edits:
            if a is not b and a[0] <= b[0] < a[1]:
                nested.update((a[3], b[3]))
    if nested:
        for e in edits:
            if e[3] in nested:
                report.append('SKIP %s:%d %s nested call inside another migrated call' % (path, e[3], e[4]))
        edits = [e for e in edits if e[3] not in nested]
        if not edits:
            return 0
    fake = foreign_service(s)
    if fake:
        for e in edits:
            report.append('SKIP %s:%d %s receiver may be %s, not openregister' % (path, e[3], e[4], fake))
        return 0
    used = set().union(*(e[5] for e in edits))
    clash = sorted(c for c in used if name_clash(s, c))
    if clash:
        for e in edits:
            report.append('SKIP %s:%d %s another %s is in scope' % (path, e[3], e[4], ','.join(clash)))
        return 0
    for start, end, new, line, meth, u in sorted(edits, reverse=True):
        s = s[:start] + new + s[end:]
    for short in sorted(used):
        if s is not None and re.search(r'new ' + short + r'\(', s):
            s = add_use(s, short)
    if s is None:
        for e in edits:
            report.append('SKIP %s:%d %s no namespace to import into' % (path, e[3], e[4]))
        return 0
    for e in sorted(edits, key=lambda e: e[3]):
        report.append('CHANGE %s:%d %s' % (path, e[3], e[4]))
    if write:
        open(path, 'w', encoding='utf-8', errors='surrogateescape').write(s)
    return len(edits)


def main(argv):
    write = '--write' in argv
    only = list(SPECS)
    dirs = []
    for a in argv:
        if a.startswith('--only='):
            only = [x for x in a[len('--only='):].split(',') if x]
            bad = [x for x in only if x not in SPECS]
            if bad:
                print('unknown method ' + ','.join(bad))
                return 2
        elif a != '--write':
            dirs.append(a)
    if not dirs:
        print(__doc__)
        return 2
    report = []
    for d in dirs:
        for dp, dn, fs in os.walk(d):
            dn[:] = sorted(x for x in dn if x not in ('vendor', 'node_modules', '.git'))
            for f in sorted(fs):
                if f.endswith('.php'):
                    process(os.path.join(dp, f), write, only, report)
    for r in report:
        print(r)
    for meth in only:
        ch = sum(1 for r in report if r.startswith('CHANGE') and r.endswith(' ' + meth))
        sk = sum(1 for r in report if r.startswith('SKIP') and (' %s ' % meth) in r)
        print('# %s %s: %d changed, %d skipped' % ('written' if write else 'dry run', meth, ch, sk))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
