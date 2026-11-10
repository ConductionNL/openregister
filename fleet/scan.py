#!/usr/bin/env python3
"""Fleet breakage scanner: checks every fleet app's references to OpenRegister
against an OpenRegister checkout (default: ./or-main next to this script).

Read-only. Usage:
  python3 -I scan.py [--or PATH] [--apps-root PATH] [--server-root PATH] [--out DIR] [app ...]
App args are dir names under --apps-root (default: the fleet list below).
Writes <out>/<app>.json and <out>/scan-summary.json. INVENTORY.md is written by hand
from those files plus the report printed by --report.
"""
import argparse
import json
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
FLEET = {  # local dir -> app name (GitHub name)
    'opencatalogi': 'opencatalogi', 'openconnector': 'integriq', 'docudesk': 'filinq',
    'nldesign': 'thematiq', 'softwarecatalog': 'stackiq', 'launchpad': 'launchpad',
    'larpingapp': 'larpinq', 'procest': 'dossiq', 'pipelinq': 'pipelinq', 'shillinq': 'shillinq',
    'scholiq': 'learniq', 'portaliq': 'portaliq', 'decidesk': 'decidiq', 'openbuild': 'buildiq',
    'doriath': 'keepiq', 'hermiq': 'hermiq', 'hrmq': 'humaniq', 'app-versions': 'versioniq',
    'planix': 'planninq',
}
ORNS = 'OCA\\OpenRegister\\'
BUILTIN_TYPES = {'self', 'static', 'parent', 'array', 'string', 'int', 'float', 'bool', 'mixed', 'void',
                 'null', 'callable', 'iterable', 'object', 'never', 'false', 'true', 'integer', 'boolean'}
MOCK_FACTORIES = r'(?:createMock|createStub|createPartialMock|createConfiguredMock|getMockForAbstractClass|createMockForIntersectionOfInterfaces)'


# --------------------------------------------------------------------------- lexing
def strip_php(src):
    """Return (code_nc, code_ns): comments blanked; and comments + string contents blanked.
    Offsets and newlines are preserved in both."""
    n = len(src)
    nc = list(src)
    ns = list(src)
    i = 0

    def blank(arr, a, b):
        for k in range(a, b):
            if arr[k] != '\n':
                arr[k] = ' '

    while i < n:
        c = src[i]
        if c == '/' and src.startswith('//', i) or (c == '#' and not src.startswith('#[', i)):
            j = src.find('\n', i)
            j = n if j < 0 else j
            # a ?> ends a line comment, ignore that corner case
            blank(nc, i, j)
            blank(ns, i, j)
            i = j
        elif src.startswith('/*', i):
            j = src.find('*/', i + 2)
            j = n if j < 0 else j + 2
            blank(nc, i, j)
            blank(ns, i, j)
            i = j
        elif c in ('"', "'"):
            j = i + 1
            while j < n and src[j] != c:
                if src[j] == '\\':
                    j += 1
                j += 1
            j = min(j, n - 1)
            for k in range(i + 1, j):
                if ns[k] != '\n':
                    ns[k] = 'x'
            i = j + 1
        elif src.startswith('<<<', i):
            m = re.match(r'<<<[ \t]*([\'"]?)(\w+)\1\r?\n', src[i:])
            if not m:
                i += 3
                continue
            ident = m.group(2)
            endm = re.compile(r'\n[ \t]*' + re.escape(ident) + r'\b').search(src, i + m.end() - 1)
            j = endm.end() if endm else n
            for k in range(i + m.end(), j - len(ident)):
                if ns[k] != '\n':
                    ns[k] = 'x'
            i = j
        else:
            i += 1
    return ''.join(nc), ''.join(ns)


def match_paren(code, open_idx, o='(', c=')'):
    depth = 0
    for k in range(open_idx, len(code)):
        ch = code[k]
        if ch == o:
            depth += 1
        elif ch == c:
            depth -= 1
            if depth == 0:
                return k
    return -1


def split_top(s):
    parts, depth, cur = [], 0, []
    for ch in s:
        if ch in '([{':
            depth += 1
        elif ch in ')]}':
            depth -= 1
        if ch == ',' and depth == 0:
            parts.append(''.join(cur))
            cur = []
        else:
            cur.append(ch)
    last = ''.join(cur)
    if last.strip():
        parts.append(last)
    return [p for p in parts]


def lineno(code, idx):
    return code.count('\n', 0, idx) + 1


# --------------------------------------------------------------------------- php file model
USE_RE = re.compile(r'^\s*use\s+((?:function|const)\s+)?([\\\w]+)(?:\s*\{([^}]*)\})?(?:\s+as\s+(\w+))?\s*;', re.M)
CLASS_RE = re.compile(r'^[ \t]*((?:(?:abstract|final|readonly)\s+)*)(class|interface|trait|enum)\s+(\w+)'
                      r'(?:\s*:\s*\w+)?((?:\s+extends\s+[\\\w]+(?:\s*,\s*[\\\w]+)*)?)'
                      r'((?:\s+implements\s+[\\\w]+(?:\s*,\s*[\\\w]+)*)?)\s*\{', re.M)
METHOD_RE = re.compile(r'((?:(?:public|protected|private|static|abstract|final)\s+)*)function\s+&?(\w+)\s*\(')
CONST_RE = re.compile(r'\bconst\s+(?:[\w\\?|]+\s+)?(\w+)\s*=|^\s*case\s+(\w+)\s*[=;]', re.M)
PROP_RE = re.compile(r'(?:public|protected|private)(?:\s+(?:readonly|static))*\s+((?:\?)?[\\\w&|()]+)\s+\$(\w+)')


class PFile:
    def __init__(self, path, rel, src):
        self.path, self.rel, self.src = path, rel, src
        self.nc, self.ns = strip_php(src)
        m = re.search(r'^\s*namespace\s+([\\\w]+)\s*[;{]', self.ns, re.M)
        self.namespace = m.group(1) if m else ''
        self.uses = {}  # alias -> fqcn
        self.use_lines = {}
        # only top-level uses (before the first class) are imports
        first_class = CLASS_RE.search(self.ns)
        head = self.ns[:first_class.start()] if first_class else self.ns
        for um in USE_RE.finditer(head):
            if um.group(1):
                continue
            base = um.group(2).lstrip('\\')
            ln = lineno(self.ns, um.start(2))
            if um.group(3) is not None:
                for part in um.group(3).split(','):
                    part = part.strip()
                    if not part:
                        continue
                    pm = re.match(r'([\\\w]+)(?:\s+as\s+(\w+))?', part)
                    fq = base.rstrip('\\') + '\\' + pm.group(1)
                    al = pm.group(2) or pm.group(1).split('\\')[-1]
                    self.uses[al] = fq
                    self.use_lines[al] = ln
            else:
                al = um.group(4) or base.split('\\')[-1]
                self.uses[al] = base
                self.use_lines[al] = ln
        self.classes = []
        for cm in CLASS_RE.finditer(self.ns):
            ob = self.ns.find('{', cm.end() - 1)
            cb = match_paren(self.ns, ob, '{', '}')
            if cb < 0:
                cb = len(self.ns)
            ext = [self.resolve(x.strip()) for x in re.sub(r'^\s*extends\s+', '', cm.group(4)).split(',') if x.strip()] if cm.group(4).strip() else []
            imp = [self.resolve(x.strip()) for x in re.sub(r'^\s*implements\s+', '', cm.group(5)).split(',') if x.strip()] if cm.group(5).strip() else []
            ci = ClassInfo(self, cm.group(3), cm.group(2), cm.group(1), ext, imp, ob, cb, lineno(self.ns, cm.start(3)))
            self.classes.append(ci)

    def resolve(self, name):
        name = name.strip()
        if not name:
            return name
        if name.startswith('\\'):
            return name[1:]
        if name.lower() in BUILTIN_TYPES:
            return name.lower()
        first = name.split('\\')[0]
        if first in self.uses:
            return self.uses[first] + name[len(first):]
        return (self.namespace + '\\' + name) if self.namespace else name


class Param:
    __slots__ = ('name', 'optional', 'variadic', 'type', 'raw')

    def __init__(self, name, optional, variadic, type_, raw):
        self.name, self.optional, self.variadic, self.type, self.raw = name, optional, variadic, type_, raw

    def to_s(self):
        return self.raw


def parse_params(pf, plist):
    out = []
    for raw in split_top(plist):
        r = re.sub(r'#\[.*?\]', ' ', raw, flags=re.S).strip()
        if not r:
            continue
        m = re.search(r'(\.\.\.)?\s*\$(\w+)', r)
        if not m:
            continue
        before = r[:m.start()]
        after = r[m.end():]
        before = re.sub(r'\b(public|protected|private|readonly)\b', ' ', before).replace('&', ' ').strip()
        optional = '=' in after
        variadic = bool(m.group(1))
        out.append(Param(m.group(2), optional, variadic, norm_type(pf, before), ' '.join(raw.split())))
    return out


def norm_type(pf, t):
    t = t.strip()
    if not t:
        return ''
    nullable = t.startswith('?')
    t = t.lstrip('?')
    parts = re.split(r'[|&()]', t)
    res = sorted(set(pf.resolve(p) for p in parts if p.strip()))
    if nullable:
        res = sorted(set(res + ['null']))
    return '|'.join(res)


class Method:
    def __init__(self, cls, name, mods, params, ret, line, body):
        self.cls, self.name, self.mods, self.params, self.ret, self.line, self.body = cls, name, mods, params, ret, line, body
        self.static = 'static' in mods
        self.abstract = 'abstract' in mods or cls.kind == 'interface'
        self.visibility = 'private' if 'private' in mods else 'protected' if 'protected' in mods else 'public'

    def signature(self):
        return ('static ' if self.static else '') + self.name + '(' + ', '.join(p.raw for p in self.params) + ')' + (': ' + self.ret if self.ret else '')


class ClassInfo:
    def __init__(self, pf, name, kind, mods, extends, implements, ob, cb, line):
        self.pf, self.name, self.kind, self.mods = pf, name, kind, mods or ''
        self.fqcn = (pf.namespace + '\\' + name) if pf.namespace else name
        self.extends, self.implements = extends, implements
        if kind == 'interface':
            self.implements, self.extends = extends, []
        self.ob, self.cb, self.line = ob, cb, line
        self.final = 'final' in self.mods
        self.abstract_cls = 'abstract' in self.mods
        self.methods = {}
        self.traits = []
        self.consts = set()
        self.props = {}  # name -> type
        body = pf.ns[ob + 1:cb]
        # depth-1 scan: traits, consts, methods, props
        depth = 0
        k = 0
        seg_start = 0
        top = []  # list of (start,end) slices at depth 0 of the body
        for k, ch in enumerate(body):
            if ch == '{':
                if depth == 0:
                    top.append((seg_start, k))
                depth += 1
            elif ch == '}':
                depth -= 1
                if depth == 0:
                    seg_start = k + 1
        top.append((seg_start, len(body)))
        topcode = ''.join(body[a:b] + ' ' for a, b in top)
        for tm in re.finditer(r'(?:^|(?<=[;}]))\s*use\s+([\\\w\s,]+?)\s*[;{]', topcode):
            for t in tm.group(1).split(','):
                if t.strip():
                    self.traits.append(pf.resolve(t.strip()))
        for cm in CONST_RE.finditer(topcode):
            self.consts.add(cm.group(1) or cm.group(2))
        self.const_vals = {}
        topnc = pf.nc[ob + 1:cb]
        for cm in re.finditer(r'\bconst\s+(?:[\w\\?|]+\s+)?(\w+)\s*=\s*(?:([\'"])(OCA\\\\?OpenRegister[^\'"]+)\2|([\\\w]+)::class)\s*;', topnc):
            self.const_vals[cm.group(1)] = cm.group(3).replace('\\\\', '\\') if cm.group(3) else pf.resolve(cm.group(4))
        for pm in PROP_RE.finditer(topcode):
            self.props[pm.group(2)] = norm_type(pf, pm.group(1))
        base = ob + 1
        for mm in METHOD_RE.finditer(body):
            # only methods declared at depth 0 of the class body
            if body[:mm.start()].count('{') - body[:mm.start()].count('}') != 0:
                continue
            po = base + mm.end() - 1
            pc = match_paren(pf.ns, po)
            params = parse_params(pf, pf.nc[po + 1:pc])
            rest = pf.ns[pc + 1:pc + 300]
            rm = re.match(r'\s*:\s*(\??[\\\w|&()]+(?:\s*[|&]\s*\??[\\\w()]+)*)', rest)
            ret = norm_type(pf, rm.group(1)) if rm else ''
            bo = pf.ns.find('{', pc)
            semi = pf.ns.find(';', pc)
            if bo >= 0 and (semi < 0 or bo < semi):
                bc = match_paren(pf.ns, bo, '{', '}')
                bodyspan = (bo, bc)
            else:
                bodyspan = None
            meth = Method(self, mm.group(2), mm.group(1).split(), params, ret, lineno(pf.ns, base + mm.start(2)), bodyspan)
            self.methods[mm.group(2).lower()] = meth
            if mm.group(2) == '__construct':
                for p, raw in zip(params, split_top(pf.nc[po + 1:pc])):
                    if re.search(r'\b(public|protected|private)\b', raw):
                        self.props[p.name] = p.type


def load_pfiles(p, rel, src):
    """One PFile per namespace; a file with braced `namespace X { }` blocks is split into
    one virtual file per block (everything else blanked, so offsets and lines hold)."""
    _, ns = strip_php(src)
    blocks = []
    for m in re.finditer(r'^[ \t]*namespace\s*([\\\w]*)\s*\{', ns, re.M):
        ob = ns.find('{', m.end() - 1)
        cb = match_paren(ns, ob, '{', '}')
        blocks.append((m.start(), cb if cb > 0 else len(ns)))
    if not blocks:
        return [PFile(p, rel, src)]
    out = []
    for a, b in blocks:
        chars = [ch if (a <= k <= b or ch == '\n') else ' ' for k, ch in enumerate(src)]
        seg = ''.join(chars)
        seg = '<?php' + seg[5:] if src.startswith('<?php') else seg
        out.append(PFile(p, rel, seg))
    return out


# --------------------------------------------------------------------------- OR index
class ORIndex:
    def __init__(self, root):
        self.root = root
        self.classes = {}
        files = subprocess.run(['git', '-C', root, 'ls-files', 'lib'], capture_output=True, text=True).stdout.split()
        for f in files:
            if not f.endswith('.php'):
                continue
            p = os.path.join(root, f)
            try:
                src = open(p, encoding='utf-8', errors='replace').read()
            except OSError:
                continue
            for pf in load_pfiles(p, f, src):
                for c in pf.classes:
                    self.classes[c.fqcn] = c
        self.lower = {k.lower(): k for k in self.classes}
        am = json.load(open(os.path.join(root, 'appinfo', 'class-aliases.json')))['aliases']
        self.aliases = am
        ALIASES.update({k: v['class'] for k, v in am.items()})
        self.method_names = {n for c in self.classes.values() for n in c.methods}
        self.sha = subprocess.run(['git', '-C', root, 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip()
        self.routes = load_routes(os.path.join(root, 'appinfo', 'routes.php'))

    def dispatch_status(self, fq):
        """Is the event class constructed anywhere in OR main lib/? -> constructed|referenced|never"""
        if not hasattr(self, '_disp'):
            self._disp = {}
        if fq in self._disp:
            return self._disp[fq]
        ci = self.classes.get(fq)
        short = fq.split('\\')[-1]
        own = ci.pf.rel if ci else ''
        r = subprocess.run(['git', '-C', self.root, 'grep', '-l', '-E', r'new\s+(\\?[A-Za-z_\\]*\\)?' + short + r'\s*\(', '--', 'lib'],
                           capture_output=True, text=True).stdout.split()
        st = 'constructed' if [x for x in r if x != own] else None
        if st is None:
            r = subprocess.run(['git', '-C', self.root, 'grep', '-l', '-w', short, '--', 'lib'], capture_output=True, text=True).stdout.split()
            st = 'referenced' if [x for x in r if x != own and not x.endswith('ClassAliasMap.php')] else 'never'
        self._disp[fq] = st
        return st

    def lookup(self, fq):
        """-> (status, ClassInfo|None, alias_entry|None). status: exists|alias|missing"""
        fq = fq.lstrip('\\')
        if fq in self.classes:
            return 'exists', self.classes[fq], None
        if fq in self.aliases:
            a = self.aliases[fq]
            return 'alias', self.classes.get(a['class']), a
        if fq.lower() in self.lower:
            return 'exists', self.classes[self.lower[fq.lower()]], None
        return 'missing', None, None

    def find_method(self, ci, name, seen=None, fleet=None):
        """-> (Method|None, flag) flag in {'', 'external', 'magic'}"""
        seen = seen or set()
        if ci is None or ci.fqcn in seen:
            return None, ''
        seen.add(ci.fqcn)
        m = ci.methods.get(name.lower())
        if m:
            return m, ''
        flag = ''
        for t in ci.traits:
            tc = self.get(t, fleet)
            if tc is None:
                flag = 'external'
                continue
            m, f = self.find_method(tc, name, seen, fleet)
            if m:
                return m, ''
            flag = flag or f
        for parent in ci.extends + (ci.implements if ci.kind == 'interface' or ci.abstract_cls else []):
            pc = self.get(parent, fleet)
            if pc is None:
                if not parent.startswith(ORNS):
                    flag = 'external'
                continue
            m, f = self.find_method(pc, name, seen, fleet)
            if m:
                return m, ''
            flag = flag or f
        if '__call' in ci.methods or ('__callstatic' in ci.methods):
            flag = 'magic'
        return None, flag

    def get(self, fq, fleet=None):
        st, ci, _ = self.lookup(fq)
        if ci is None and fleet is not None:
            ci = fleet.get(fq)
        return ci

    def has_const(self, ci, name, seen=None):
        seen = seen or set()
        if ci is None or ci.fqcn in seen:
            return None
        seen.add(ci.fqcn)
        if name in ci.consts:
            return True
        unknown = False
        for p in ci.extends + ci.implements + ci.traits:
            pc = self.get(p)
            if pc is None:
                unknown = True
                continue
            r = self.has_const(pc, name, seen)
            if r:
                return True
            if r is None:
                unknown = True
        return None if unknown else False


def load_routes(path):
    src = open(path).read()
    routes = []
    rm = re.search(r"'resources'\s*=>\s*\[(.*?)\]\s*,\s*'routes'", src, re.S)
    if rm:
        for m in re.finditer(r"'url'\s*=>\s*'([^']+)'", rm.group(1)):
            u = m.group(1).strip('/')
            for verb, suf in (('GET', ''), ('POST', ''), ('GET', '/{id}'), ('PUT', '/{id}'), ('PATCH', '/{id}'), ('DELETE', '/{id}')):
                routes.append((verb, '/' + u + suf, {}))
    for m in re.finditer(r"\[\s*'name'\s*=>\s*'[^']+'\s*,\s*'url'\s*=>\s*'([^']+)'(.*?)\]\s*,", src, re.S):
        rest = m.group(2)
        vm = re.search(r"'verb'\s*=>\s*'(\w+)'", rest)
        reqs = dict(re.findall(r"'(\w+)'\s*=>\s*'([^']*)'", rest.split("'requirements'", 1)[1])) if "'requirements'" in rest else {}
        routes.append(((vm.group(1) if vm else 'GET').upper(), m.group(1), reqs))
    compiled = []
    for verb, url, reqs in routes:
        if not url.startswith('/'):
            url = '/' + url
        segs = [s for s in url.strip('/').split('/')]
        compiled.append((verb, url, segs, reqs))
    return compiled


def route_matches(path, routes, prefix_only):
    """path segments may contain '*' wildcards. prefix_only: path is a static prefix."""
    psegs = [s for s in path.strip('/').split('/')]
    hits = []
    for verb, url, segs, reqs in routes:
        if url == '/{path}':
            continue
        ok = True
        greedy = False
        for i, ps in enumerate(psegs):
            if i >= len(segs):
                ok = greedy
                break
            rs = segs[i]
            if ps == '*' or ps == '':
                if ps == '' and i == len(psegs) - 1:
                    continue
                if '{' in rs:
                    pn = re.findall(r'\{(\w+)\}', rs)
                    greedy = any(reqs.get(n, '') in ('.+', '.*') for n in pn)
                    continue
                if prefix_only and i == len(psegs) - 1:
                    continue
                ok = False if ps == '' else True
                if ps == '*':
                    continue
                break
            if '{' in rs:
                pat = '^' + re.sub(r'\\\{(\w+)\\\}', lambda mm: '(?:' + (reqs.get(mm.group(1)) or '[^/]+') + ')', re.escape(rs)) + '$'
                pn = re.findall(r'\{(\w+)\}', rs)
                greedy = any(reqs.get(n, '') in ('.+', '.*') for n in pn)
                if ps.endswith('*') or '*' in ps:
                    continue
                try:
                    if not re.match(pat, ps):
                        ok = False
                        break
                except re.error:
                    continue
            elif rs != ps:
                if '*' in ps:
                    pre = ps.split('*')[0]
                    if rs.startswith(pre):
                        continue
                if prefix_only and i == len(psegs) - 1 and rs.startswith(ps):
                    continue
                ok = False
                break
        if not ok:
            continue
        if len(psegs) < len(segs) and not prefix_only:
            continue
        hits.append((verb, url))
    return hits


# --------------------------------------------------------------------------- fleet scan
class FleetApp:
    def __init__(self, root, name, orx):
        self.root, self.name, self.orx = root, name, orx
        self.files = []
        self.classes = {}
        r = subprocess.run(['git', '-C', root, 'ls-files', '--', 'lib', 'tests', 'appinfo'], capture_output=True, text=True).stdout.split()
        for f in r:
            if not f.endswith('.php'):
                continue
            p = os.path.join(root, f)
            try:
                src = open(p, encoding='utf-8', errors='replace').read()
            except OSError:
                continue
            for pf in load_pfiles(p, f, src):
                self.files.append(pf)
                for c in pf.classes:
                    self.classes.setdefault(c.fqcn, c)

        self.method_names = {n for c in self.classes.values() for n in c.methods}

    def get(self, fq):
        return self.classes.get(fq.lstrip('\\'))


NC_METHODS = set()


def load_nc_methods(server_root):
    if not server_root or not os.path.isdir(os.path.join(server_root, '.git')):
        print('no --server-root: Nextcloud method names are not recognised, dynamic-name-nowhere may over-report', file=sys.stderr)
        server_root = None
    out = '' if server_root is None else subprocess.run(['git', '-C', server_root, 'grep', '-h', '-o', '-I', '-E', r'function &?\w+', '--', 'lib', 'apps/*/lib', '3rdparty'],
                         capture_output=True, text=True).stdout
    NC_METHODS.update(x.split()[-1].lstrip('&').lower() for x in out.splitlines())
    # PHP builtin object methods commonly probed
    NC_METHODS.update({'jsonserialize', '__tostring', 'getiterator', 'count', 'offsetget', '__invoke', 'tostring', 'toarray'})


def is_or(fq):
    return fq.startswith(ORNS)


def pick_or_type(t):
    if not t:
        return None
    cands = [x for x in t.split('|') if is_or(x)]
    return cands[0] if len(cands) == 1 else None


def arg_kind(a):
    a = a.strip()
    if a.startswith('...'):
        return 'spread', None
    m = re.match(r'^([A-Za-z_]\w*)\s*:(?!:)', a)
    if m:
        return 'named', m.group(1)
    return 'pos', None


def literal_kind(a):
    a = re.sub(r'^[A-Za-z_]\w*\s*:(?!:)', '', a.strip()).strip()
    if a.startswith('[') or a.startswith('array('):
        return 'array'
    if a[:1] in ('"', "'"):
        return 'string'
    if re.match(r'^-?\d', a):
        return 'int'
    if a.lower() in ('true', 'false'):
        return 'bool'
    if a.lower() == 'null':
        return 'null'
    return None


def check_call(meth, args_src, static_call, call_desc):
    """-> list of problem strings (empty = ok); None = cannot judge"""
    args = split_top(args_src) if args_src.strip() else []
    kinds = [arg_kind(a) for a in args]
    if any(k == 'spread' for k, _ in kinds):
        return None
    npos = sum(1 for k, _ in kinds if k == 'pos')
    named = [n for k, n in kinds if k == 'named']
    params = meth.params
    variadic = any(p.variadic for p in params)
    fixed = [p for p in params if not p.variadic]
    probs = []
    if npos > len(fixed) and not variadic:
        probs.append(f'SILENT-EXTRA-ARGS: {npos} positional args, method takes {len(fixed)} (extra args are silently dropped)')
    names = {p.name: i for i, p in enumerate(params)}
    for n in named:
        if n not in names:
            if not variadic:
                probs.append(f'UNKNOWN-NAMED-ARG: `{n}:` (Error: Unknown named parameter)')
        elif names[n] < npos:
            probs.append(f'NAMED-OVERWRITES-POSITIONAL: `{n}:`')
    for i, p in enumerate(fixed):
        if not p.optional and i >= npos and p.name not in named:
            probs.append(f'MISSING-REQUIRED-ARG: `${p.name}` (ArgumentCountError)')
    # literal type checks against class-typed params
    pi = 0
    for a, (k, n) in zip(args, kinds):
        if k == 'pos':
            p = fixed[pi] if pi < len(fixed) else None
            pi += 1
        else:
            p = params[names[n]] if n in names else None
        if p is None or not p.type:
            continue
        lk = literal_kind(a)
        if lk is None:
            continue
        tparts = p.type.split('|')
        scalar_ok = {'array': {'array', 'iterable', 'mixed'}, 'string': {'string', 'mixed', 'callable'},
                     'int': {'int', 'float', 'mixed', 'string'}, 'bool': {'bool', 'false', 'true', 'mixed'},
                     'null': {'null', 'mixed'}}[lk]
        if lk in ('int', 'string') and any(t in ('int', 'float', 'string', 'bool') for t in tparts):
            continue
        if not (set(tparts) & scalar_ok) and all('\\' in t or t in BUILTIN_TYPES for t in tparts):
            probs.append(f'TYPE: passes {lk} literal `{a.strip()[:60]}` to `${p.name}` typed `{p.type}` (TypeError)')
    if static_call and not meth.static and meth.name.lower() != '__construct':
        probs.append('STATIC-CALL-ON-INSTANCE-METHOD')
    return probs


def iter_functions(ci):
    for m in ci.methods.values():
        if m.body:
            yield m


RESOLVER_ASSIGN = re.compile(r'\$(\w+)\s*=\s*(?:\$this\s*->\s*\w+\s*->\s*\w+|\$this\s*->\s*\w+|\$\w+\s*->\s*\w+|\\?OC::\$server\s*->\s*\w+|[\\\w]+::\w+)\s*\(\s*(?:\w+\s*:\s*)?((?:self|static|[\\\w]+)::\w+|[\'"]OCA\\\\?OpenRegister[^\'"]+[\'"])\s*\)\s*;')


def class_expr(pf, ci, e):
    e = e.strip()
    if e[:1] in ('"', "'"):
        return unescape(e[1:-1])
    m = re.match(r'([\\\w]+)::(\w+)$', e)
    if not m:
        return None
    if m.group(2) == 'class':
        return pf.resolve(m.group(1))
    if m.group(1) in ('self', 'static'):
        return ci.const_vals.get(m.group(2))
    return None


ASSIGN_GET = re.compile(r'\$(\w+)\s*=\s*(?:\$this\s*->\s*\w+|\$\w+|\\?OC::\$server|\\?OCP\\Server|Server|\\OC\\Server)\s*(?:->|::)\s*(?:get|query|resolve)\s*\(\s*([\\\w]+)::class\s*\)')
ASSIGN_GET_STR = re.compile(r'\$(\w+)\s*=\s*[^;]*?->\s*(?:get|query)\s*\(\s*([\'"])(OCA\\\\?OpenRegister[^\'"]+)\2\s*\)')
ASSIGN_NEW = re.compile(r'\$(\w+)\s*=\s*new\s+([\\\w]+)\s*\(')
ASSIGN_MOCK = re.compile(r'\$(\w+)\s*=\s*\$this\s*->\s*' + MOCK_FACTORIES + r'\s*\(\s*([\\\w]+)::class')
ASSIGN_MOCKB = re.compile(r'\$(\w+)\s*=\s*\$this\s*->\s*getMockBuilder\s*\(\s*([\\\w]+)::class\s*\)')
PROP_RESOLVER = re.compile(r'\$this\s*->\s*(\w+)\s*=\s*(?:\$this\s*->\s*\w+\s*->\s*\w+|\$this\s*->\s*\w+|\$\w+\s*->\s*\w+|\\?OC::\$server\s*->\s*\w+|[\\\w]+::\w+)\s*\(\s*(?:\w+\s*:\s*)?((?:self|static|[\\\w]+)::\w+|[\'"]OCA\\\\?OpenRegister[^\'"]+[\'"])\s*\)\s*;')
PROP_ASSIGN_MOCK = re.compile(r'\$this\s*->\s*(\w+)\s*=\s*\$this\s*->\s*(?:' + MOCK_FACTORIES[3:-1] + r'|getMockBuilder)\s*\(\s*([\\\w]+)::class')
PROP_ASSIGN_NEW = re.compile(r'\$this\s*->\s*(\w+)\s*=\s*new\s+([\\\w]+)\s*\(')
INLINE_VAR = re.compile(r'@var\s+([\\\w|&]+)\s+\$(\w+)')
CALL_RE = re.compile(r'(\$this\s*->\s*(\w+)|\$(\w+)|\bparent|\bself|\bstatic|(?<![\w\\$>])\\?[A-Z][\\\w]*)\s*(\?->|->|::)\s*(\w+)\s*\(')
CONST_USE_RE = re.compile(r'(?<![\w\\$>])(\\?[A-Z][\\\w]*)::([A-Z_][A-Z0-9_]*)\b(?!\s*\()')


def unescape(s):
    return s.replace('\\\\', '\\')


def scan_app(app, orx):
    res = {'imports': [], 'string_lookups': [], 'dynamic': [], 'instanceof_catch': [], 'named_calls': 0,
           'implements': [], 'listeners': [], 'routes': [], 'mismatches': [], 'unresolved': [], 'calls_checked': 0,
           'or_refs': 0, 'type_positions': []}
    mism = res['mismatches']
    seen_m = set()

    def add_m(pf, idx_or_line, cat, what, ornow, fix, silent=False, extra=None):
        line = idx_or_line if isinstance(idx_or_line, int) and idx_or_line < 10 ** 6 and extra == 'line' else lineno(pf.ns, idx_or_line)
        key = (pf.rel, line, what)
        if key in seen_m:
            return
        seen_m.add(key)
        stubbed = any(c in app.classes for c in re.findall(r'OCA\\OpenRegister\\[\w\\]+', what + ' ' + ornow))
        mism.append({'file': pf.rel, 'line': line, 'scope': 'tests' if pf.rel.startswith('tests') else 'lib', 'category': cat,
                     'app_calls': what, 'or_main': ornow, 'fix': fix, 'silent': silent, 'test_stub_exists': stubbed})

    def cls_desc(fq):
        st, ci, al = orx.lookup(fq)
        if st == 'exists':
            return ci.pf.rel
        if st == 'alias':
            return f"moved to {al['class']} (alias, eager={al.get('eager')}, service={al.get('service', True)})"
        return 'MISSING'

    for pf in app.files:
        if 'OpenRegister' not in pf.src and 'openregister' not in pf.src:
            continue
        code = pf.ns
        # ---- collect OR names used in this file
        or_aliases = {al: fq for al, fq in pf.uses.items() if is_or(fq)}
        res['or_refs'] += len(re.findall(r'OCA\\\\?OpenRegister', pf.nc))
        # (a) imports
        for al, fq in or_aliases.items():
            st, ci, a = orx.lookup(fq)
            used_lines = [lineno(code, m.start()) for m in re.finditer(r'(?<![\w\\$])' + re.escape(al) + r'(?![\w])', code)
                          if lineno(code, m.start()) != pf.use_lines[al]]
            res['imports'].append({'file': pf.rel, 'line': pf.use_lines[al], 'class': fq, 'status': st,
                                   'alias_to': a['class'] if a else None, 'eager': a.get('eager') if a else None, 'used_lines': used_lines[:20]})
            if st == 'missing' and app.get(fq) is not None:
                if not pf.rel.startswith('tests'):
                    add_m(pf, pf.use_lines[al], 'class-missing', f'use {fq} (only a test stub in this app declares it)',
                          'class does not exist on OR main (no alias)', 'find the class it became on OR main; update the stub too', extra='line')
                continue
            if st == 'missing':
                if used_lines:
                    add_m(pf, pf.use_lines[al], 'class-missing', f'use {fq} (used at lines {", ".join(map(str, used_lines[:8]))})',
                          'class does not exist on OR main (no alias)', 'find the class it became on OR main and re-point the import', extra='line')
                else:
                    res['unresolved'].append({'file': pf.rel, 'line': pf.use_lines[al], 'what': f'unused or docblock-only import of missing {fq}'})
        # fully-qualified names in code
        fq_names = set()
        for m in re.finditer(r'\\?(OCA\\OpenRegister\\[\w\\]+)', code):
            fq_names.add((m.group(1).rstrip('\\'), m.start()))
        for fq, idx in fq_names:
            if code[idx - 4:idx].strip().startswith('use') or re.match(r'\s*(use|namespace)\s', code[code.rfind('\n', 0, idx) + 1:idx]):
                continue
            st, ci, a = orx.lookup(fq)
            if st == 'missing':
                # namespace-only reference (e.g. \OCA\OpenRegister\Db\ in a string compare)
                if any(k.startswith(fq + '\\') for k in orx.classes) or app.get(fq) is not None:
                    continue
                add_m(pf, idx, 'class-missing', f'\\{fq}', 'class does not exist on OR main (no alias)', 'find the new FQCN on OR main')
        # (b) string lookups
        for m in re.finditer(r'([\'"])(OCA\\\\?OpenRegister\\\\?[\w\\]+)\1', pf.nc):
            fq = unescape(m.group(2)).rstrip('\\')
            pre = pf.nc[max(0, m.start() - 80):m.start()]
            ctx = 'container-get' if re.search(r'(get|query|resolve)\s*\(\s*$', pre) else \
                'class_exists' if re.search(r'(class_exists|interface_exists|is_a|is_subclass_of|method_exists)\s*\(\s*[^,]*$', pre) else 'string'
            st, ci, a = orx.lookup(fq)
            if st == 'missing' and any(k.startswith(fq + '\\') for k in orx.classes):
                continue
            if st == 'missing' and pf.rel.startswith('tests') and re.search(r'Fake|Missing|NonExist|DoesNotExist|Nope|Bogus|Unknown|Absent|NotInstalled|Probe', fq):
                continue
            if st == 'missing' and app.get(fq) is not None:
                st = 'fleet-stub'
            res['string_lookups'].append({'file': pf.rel, 'line': lineno(pf.nc, m.start()), 'class': fq, 'context': ctx, 'status': st})
            if st == 'missing':
                add_m(pf, m.start(), 'string-lookup-missing', f"{ctx}('{fq}')", 'class does not exist on OR main (no alias)',
                      'replace with the new FQCN (prefer ::class so static analysis sees it)', silent=ctx in ('class_exists', 'string', 'container-get'))
            elif st == 'alias' and a.get('service') is False and ctx == 'container-get':
                add_m(pf, m.start(), 'alias-service-false', f"container get('{fq}')", f"alias to {a['class']} with service:false: container autowires a SECOND instance",
                      f"use {a['class']}::class", silent=True)
        for m in re.finditer(r'(?:->|::)\s*(?:get|query)\s*\(\s*([\\\w]+)::class\s*\)', code):
            fq = pf.resolve(m.group(1))
            if is_or(fq):
                st, ci, a = orx.lookup(fq)
                res['string_lookups'].append({'file': pf.rel, 'line': lineno(code, m.start()), 'class': fq, 'context': 'container-get ::class', 'status': st})
                if st == 'alias' and a.get('service') is False:
                    add_m(pf, m.start(), 'alias-service-false', f'container get({m.group(1)}::class)', f"alias to {a['class']} with service:false: container autowires a SECOND instance",
                          f"import {a['class']}", silent=True)
        # ::CONST on OR classes
        for m in CONST_USE_RE.finditer(code):
            fq = pf.resolve(m.group(1))
            if not is_or(fq) or m.group(2) == 'class':
                continue
            st, ci, a = orx.lookup(fq)
            if ci is None:
                continue
            hc = orx.has_const(ci, m.group(2))
            if hc is False:
                add_m(pf, m.start(), 'constant-missing', f'{m.group(1)}::{m.group(2)}', f'{ci.fqcn} ({ci.pf.rel}) has no constant/case {m.group(2)}',
                      'find the constant/case it became on OR main')
        # (d) instanceof / catch
        for m in re.finditer(r'\binstanceof\s+([\\\w]+)|\bcatch\s*\(([^)$]+)', code):
            names = [m.group(1)] if m.group(1) else [x.strip() for x in m.group(2).split('|')]
            for nm in names:
                fq = pf.resolve(nm)
                if not is_or(fq):
                    continue
                st, ci, a = orx.lookup(fq)
                res['instanceof_catch'].append({'file': pf.rel, 'line': lineno(code, m.start()), 'class': fq, 'status': st})
                if st == 'missing':
                    add_m(pf, m.start(), 'instanceof-catch-missing', f'{"instanceof" if m.group(1) else "catch"} {fq}', 'class does not exist on OR main: check is SILENTLY false',
                          'point at the new class', silent=True)
                elif st == 'alias' and not a.get('eager'):
                    add_m(pf, m.start(), 'instanceof-catch-lazy-alias', f'{"instanceof" if m.group(1) else "catch"} {fq}', f"moved to {a['class']}, alias is LAZY: check is silently false unless the old name was autoloaded",
                          f"import {a['class']}", silent=True)
        # type positions with lazy aliases (params / properties / returns)
        for al, fq in or_aliases.items():
            st, ci, a = orx.lookup(fq)
            if st != 'alias' or a.get('eager'):
                continue
            for m in re.finditer(r'(?:[(,]\s*(?:(?:public|protected|private|readonly)\s+)*\??|(?:public|protected|private)\s+(?:readonly\s+|static\s+)*\??|\)\s*:\s*\??|[|&])' + re.escape(al) + r'\b(?=[\s|&]*(?:\$|\{|;|\|))', code):
                res['type_positions'].append({'file': pf.rel, 'line': lineno(code, m.start())})
                add_m(pf, m.start(), 'type-decl-lazy-alias', f'type declaration {al} (= {fq})', f"moved to {a['class']}, alias is LAZY: PHP does not autoload for type checks, TypeError",
                      f"import {a['class']}", silent=False)
        # (g) listeners
        for m in re.finditer(r'(registerEventListener|addServiceListener|addListener|registerEventHandler)\s*\(\s*(?:event\s*:\s*)?([\\\w]+)::class|(registerEventListener|addServiceListener|addListener)\s*\(\s*([\'"])(OCA[^\'"]+)\4', code if True else pf.nc):
            nm = m.group(2)
            if nm:
                fq = pf.resolve(nm)
            else:
                mm = re.search(r'(registerEventListener|addServiceListener|addListener)\s*\(\s*([\'"])(OCA[^\'"]+)\2', pf.nc[m.start():m.end() + 200])
                fq = unescape(mm.group(3)) if mm else ''
            if not is_or(fq):
                continue
            st, ci, a = orx.lookup(fq)
            disp = orx.dispatch_status(a['class'] if st == 'alias' else fq) if st != 'missing' else 'n/a'
            res['listeners'].append({'file': pf.rel, 'line': lineno(code, m.start()), 'event': fq, 'status': st, 'dispatch': disp})
            if disp == 'never':
                add_m(pf, m.start(), 'listener-event-never-dispatched', f'listener registered for {fq}', 'OR main lib/ never constructs this event (no `new` anywhere, no reference outside its own file): listener never fires',
                      'find the event OR main dispatches for this moment', silent=True)
            if st == 'missing':
                add_m(pf, m.start(), 'listener-event-missing', f'listener registered for {fq}', 'event class does not exist on OR main: listener NEVER fires',
                      'register for the event class OR main dispatches', silent=True)
            elif st == 'alias':
                add_m(pf, m.start(), 'listener-event-moved', f'listener registered for {fq}', f"moved to {a['class']}: OR dispatches by the NEW class name, listener on the old name never fires",
                      f"register for {a['class']}::class", silent=True)
        # (c) dynamic calls: a literal method name that exists nowhere in OR main, this app or NC server
        for m in re.finditer(r'(?:method_exists|is_callable)\s*\(\s*\[?\s*(\$[\w>-]+)\s*,\s*([\'"])(\w+)\2|\[\s*(\$[\w>-]+)\s*,\s*([\'"])(\w+)\5\s*\]', pf.nc):
            recv, nm = (m.group(1), m.group(3)) if m.group(3) else (m.group(4), m.group(6))
            lname = nm.lower()
            if pf.rel.startswith('tests'):
                continue
            if lname in orx.method_names or lname in app.method_names or lname in NC_METHODS:
                continue
            ctx = pf.nc[max(0, m.start() - 400):m.end() + 100]
            if not re.search(r'OpenRegister|openregister|objectService|ObjectService|[Rr]egister|[Ss]chema|Mapper', ctx):
                continue
            add_m(pf, m.start(), 'dynamic-name-nowhere', f"{pf.nc[m.start():m.end()]} (receiver {recv})",
                  f'no method named {nm} exists in any OR main class (nor in this app or NC server): the guarded branch never runs',
                  'find what replaced it on OR main and call it directly', silent=True)
        # (c) dynamic calls
        for m in re.finditer(r'\bmethod_exists\s*\(|\bis_callable\s*\(|\bcall_user_func(?:_array)?\s*\(|->\s*\{\s*\$|->\s*\$\w+\s*\(|::\s*\$\w+\s*\(|\[\s*\$[\w>-]+\s*,\s*[\'"]\w+[\'"]\s*\]', code):
            ln = lineno(code, m.start())
            line_src = pf.src.splitlines()[ln - 1].strip() if ln - 1 < len(pf.src.splitlines()) else ''
            res['dynamic'].append({'file': pf.rel, 'line': ln, 'src': line_src[:200]})

        # ---- per-class: implements/extends + typed calls
        for ci in pf.classes:
            fleet_ci = ci
            # (f) implements / extends OR
            or_parents = [x for x in ci.implements + ci.extends if is_or(x)]
            for parent in or_parents:
                st, pci, a = orx.lookup(parent)
                kind = 'implements' if parent in ci.implements else 'extends'
                res['implements'].append({'file': pf.rel, 'line': ci.line, 'class': ci.fqcn, 'kind': kind, 'or_class': parent, 'status': st})
                if st == 'missing':
                    add_m(pf, ci.line, 'parent-missing', f'{ci.name} {kind} {parent}', 'does not exist on OR main: class fails to load (fatal on autoload)',
                          'implement/extend the new contract', extra='line')
                    continue
                if st == 'alias':
                    # class_alias'd parents still work at declaration time (autoload triggers); note only
                    pass
                if pci is None:
                    continue
                if kind == 'extends' and pci.final:
                    add_m(pf, ci.line, 'extends-final', f'{ci.name} extends {parent}', f'{pci.fqcn} is final on OR main ({pci.pf.rel})', 'compose instead of extend', extra='line')
                check_contract(orx, app, pf, ci, pci, kind, add_m)
            # typed call checks
            for meth in iter_functions(ci):
                check_function_calls(orx, app, pf, ci, meth, res, add_m)
    # fleet-declared stubs of OR classes: drift from the real class
    res['stubs'] = []
    for fq, sci in app.classes.items():
        if not is_or(fq):
            continue
        st, oci, a = orx.lookup(fq)
        entry = {'file': sci.pf.rel, 'line': sci.line, 'class': fq, 'status': st, 'drift': []}
        if oci is not None:
            for lname, sm in sci.methods.items():
                om, flag = orx.find_method(oci, lname)
                if om is None:
                    if not flag:
                        entry['drift'].append(f'{sm.name}(): not on OR main')
                    continue
                sp = [p.name for p in sm.params]
                op_ = [p.name for p in om.params]
                if sp != op_ or sum(not p.optional and not p.variadic for p in sm.params) != sum(not p.optional and not p.variadic for p in om.params):
                    entry['drift'].append(f'{sm.name}({", ".join("$" + x for x in sp)}) vs OR main {om.name}({", ".join("$" + x for x in op_)}) [{om.cls.pf.rel}:{om.line}]')
        res['stubs'].append(entry)
    # (h) routes from src + lib (any text file)
    scan_routes(app, orx, res)
    return res


def collect_abstract(orx, app, ci, out, seen):
    if ci is None or ci.fqcn in seen:
        return
    seen.add(ci.fqcn)
    for m in ci.methods.values():
        if m.abstract and m.name.lower() not in out:
            out[m.name.lower()] = m
    for p in ci.implements + ci.extends:
        collect_abstract(orx, app, orx.get(p, app), out, seen)


def find_impl(orx, app, ci, name, seen=None):
    seen = seen or set()
    if ci is None or ci.fqcn in seen:
        return None
    seen.add(ci.fqcn)
    m = ci.methods.get(name)
    if m and not m.abstract:
        return m
    for t in ci.traits:
        r = find_impl(orx, app, orx.get(t, app), name, seen)
        if r:
            return r
    for p in ci.extends:
        pc = orx.get(p, app)
        if pc is None:
            return 'external'
        r = find_impl(orx, app, pc, name, seen)
        if r:
            return r
    return None


ALIASES = {}


def _alias_norm(t):
    return '|'.join(sorted(set(ALIASES.get(x, x) for x in t.split('|')))) if t else t


def types_compatible(child_t, parent_t, param):
    """param=True: child must accept parent type (contravariant)."""
    child_t, parent_t = _alias_norm(child_t), _alias_norm(parent_t)
    if child_t == parent_t:
        return True
    if param:
        if not child_t or child_t == 'mixed':
            return True
        if not parent_t:
            return child_t in ('mixed',)
        return set(parent_t.split('|')) <= set(child_t.split('|')) or 'mixed' in child_t.split('|')
    if not parent_t:
        return True
    if not child_t:
        return parent_t in ('mixed',) and False
    if parent_t == 'mixed':
        return True
    cs, ps = set(child_t.split('|')), set(parent_t.split('|'))
    if cs <= ps:
        return True
    if 'static' in cs and ('self' in ps or 'static' in ps):
        return True
    return None  # unknown (subclassing), do not flag


def check_contract(orx, app, pf, ci, pci, kind, add_m):
    required = {}
    collect_abstract(orx, app, pci, required, set())
    for lname, am in required.items():
        impl = find_impl(orx, app, ci, lname)
        if impl == 'external':
            continue
        if impl is None:
            if ci.abstract_cls or ci.kind != 'class':
                continue
            add_m(pf, ci.line, 'contract-method-missing', f'{ci.name} {kind} {pci.fqcn} but has no {am.name}()',
                  f'{am.cls.fqcn}::{am.signature()} ({am.cls.pf.rel}:{am.line})', 'add the method (class fails to load: fatal)', silent=True, extra='line')
            continue
        compare_override(pf, ci, impl, am, add_m, 'contract')
    # overrides of concrete parent methods (extends)
    if kind == 'extends':
        for lname, own in ci.methods.items():
            if lname == '__construct':
                continue
            pm, flag = orx.find_method(pci, lname)
            if pm is None or pm.abstract or pm.visibility == 'private':
                continue
            compare_override(pf, ci, own, pm, add_m, 'override')


def compare_override(pf, ci, impl, am, add_m, what):
    if impl.cls is not ci and impl.cls.pf is not pf:
        return  # inherited from elsewhere, checked there
    probs = []
    ip, ap = impl.params, am.params
    ivar = any(p.variadic for p in ip)
    if len([p for p in ip if not p.variadic]) < len([p for p in ap if not p.variadic]) and not ivar:
        probs.append(f'takes {len(ip)} params, OR declares {len(ap)}')
    ireq = sum(1 for p in ip if not p.optional and not p.variadic)
    areq = sum(1 for p in ap if not p.optional and not p.variadic)
    if ireq > areq:
        probs.append(f'requires {ireq} params, OR requires {areq}')
    for i, (a, b) in enumerate(zip(ip, ap)):
        if types_compatible(a.type, b.type, True) is False:
            probs.append(f'param {i + 1} `${a.name}` typed `{a.type or "none"}`, OR has `{b.type or "none"}`')
    rc = types_compatible(impl.ret, am.ret, False)
    if rc is False:
        probs.append(f'return `{impl.ret or "none"}`, OR has `{am.ret}`')
    if impl.static != am.static:
        probs.append('static-ness differs')
    if probs:
        add_m(pf, impl.line, f'{what}-signature', f'{ci.name}::{impl.signature()}', f'{am.cls.fqcn}::{am.signature()} ({am.cls.pf.rel}:{am.line})',
              'align the signature with OR main (fatal at class load): ' + '; '.join(probs), silent=True, extra='line')
    else:
        names_a = [p.name for p in ap]
        names_i = [p.name for p in ip[:len(ap)]]
        # renamed params only matter for named-arg callers: not a mismatch


def var_types_for_function(orx, app, pf, ci, meth):
    """local var -> OR fqcn (only when unambiguous)."""
    body = pf.ns[meth.body[0]:meth.body[1]]
    bodync = pf.nc[meth.body[0]:meth.body[1]]
    types = {}
    bad = set()

    def put(v, fq):
        if not fq or not is_or(fq):
            if v in types:
                bad.add(v)
            return
        if v in types and types[v] != fq:
            bad.add(v)
        types[v] = fq

    for p in meth.params:
        t = pick_or_type(p.type)
        if t:
            put(p.name, t)
    for m in RESOLVER_ASSIGN.finditer(bodync):
        if m.group(1) != 'this':
            put(m.group(1), class_expr(pf, ci, m.group(2)))
    for m in ASSIGN_NEW.finditer(body):
        fq = pf.resolve(m.group(2))
        if is_or(fq):
            put(m.group(1), fq)
    for m in list(ASSIGN_MOCK.finditer(body)) + list(ASSIGN_MOCKB.finditer(body)):
        fq = pf.resolve(m.group(2))
        if is_or(fq):
            types['__mock__' + m.group(1)] = fq
            put(m.group(1), fq)
    for m in INLINE_VAR.finditer(pf.src[meth.body[0]:meth.body[1]]):
        t = pick_or_type(norm_type(pf, m.group(1)))
        if t:
            put(m.group(2), t)
    # return-type propagation: $x = $this->prop->m(...) / $x = $var->m(...)
    for m in re.finditer(r'\$(\w+)\s*=\s*(\$this\s*->\s*(\w+)|\$(\w+))\s*->\s*(\w+)\s*\(', body):
        recv = prop_type(orx, app, ci, m.group(3)) if m.group(3) else types.get(m.group(4))
        if not recv:
            continue
        rci = orx.get(recv, app)
        mm, _ = orx.find_method(rci, m.group(5)) if rci else (None, '')
        if mm and mm.ret:
            t = pick_or_type(mm.ret)
            if t and t not in ('self', 'static'):
                put(m.group(1), t)
    for b in bad:
        types.pop(b, None)
    return types


_prop_cache = {}


def prop_type(orx, app, ci, prop, seen=None):
    key = (ci.fqcn, prop)
    if key in _prop_cache:
        return _prop_cache[key]
    t = pick_or_type(ci.props.get(prop, ''))
    if not t:
        code = ci.pf.ns[ci.ob:ci.cb]
        codenc = ci.pf.nc[ci.ob:ci.cb]
        cands = set()
        for m in PROP_RESOLVER.finditer(codenc):
            if m.group(1) == prop:
                fq = class_expr(ci.pf, ci, m.group(2))
                if fq:
                    cands.add(fq)
        for rx in (PROP_ASSIGN_MOCK, PROP_ASSIGN_NEW):
            for m in rx.finditer(code):
                if m.group(1) == prop:
                    cands.add(ci.pf.resolve(m.group(2)))
        # docblock @var on the property
        dm = re.search(r'@var\s+([\\\w|&]+)[^\n]*\n(?:\s*\*[^\n]*\n)*\s*\*/\s*(?:public|protected|private)[^;$]*\$' + re.escape(prop) + r'\b', ci.pf.src[ci.ob:ci.cb])
        if dm:
            tt = pick_or_type(norm_type(ci.pf, dm.group(1)))
            if tt:
                cands.add(tt)
        cands = {c for c in cands if is_or(c)}
        if len(cands) == 1:
            t = cands.pop()
    if not t:
        seen = seen or set()
        seen.add(ci.fqcn)
        for p in ci.extends:
            pc = app.get(p)
            if pc and pc.fqcn not in seen:
                t = prop_type(orx, app, pc, prop, seen)
                if t:
                    break
    _prop_cache[key] = t
    return t


def entity_chain(orx, ci, seen=None):
    seen = seen or set()
    if ci is None or ci.fqcn in seen:
        return False
    seen.add(ci.fqcn)
    for p in ci.extends:
        if p in ('OCP\\AppFramework\\Db\\Entity', 'OCP\\AppFramework\\Db\\SnowflakeAwareEntity'):
            return True
        if entity_chain(orx, orx.get(p), seen):
            return True
    return False


def entity_has_prop(orx, ci, name, seen=None):
    seen = seen or set()
    if ci is None or ci.fqcn in seen:
        return False
    seen.add(ci.fqcn)
    if name in ci.props:
        return True
    if re.search(r'(?:public|protected|private)[^;=]*\$' + re.escape(name) + r'\b', ci.pf.ns[ci.ob:ci.cb]):
        return True
    return any(entity_has_prop(orx, orx.get(p), name, seen) for p in ci.extends + ci.traits)


def is_mock_prop(ci, prop):
    t = ci.props.get(prop, '')
    if 'MockObject' in t or 'Stub' in t:
        return True
    code = ci.pf.ns[ci.ob:ci.cb]
    return bool(re.search(r'\$this\s*->\s*' + re.escape(prop) + r'\s*=\s*\$this\s*->\s*(?:' + MOCK_FACTORIES[3:-1] + r'|getMockBuilder)', code))


def check_function_calls(orx, app, pf, ci, meth, res, add_m):
    body_start, body_end = meth.body
    code = pf.ns
    locs = var_types_for_function(orx, app, pf, ci, meth)
    seg = code[body_start:body_end]
    or_parent = next((p for p in ci.extends if is_or(p)), None)
    for m in CALL_RE.finditer(seg):
        idx = body_start + m.start()
        recv_full, prop, var, op, mname = m.group(1), m.group(2), m.group(3), m.group(4), m.group(5)
        static_call = op == '::'
        target = None
        recv_desc = recv_full
        is_mock = False
        if prop is not None:
            # $this->prop->m(  OR  $this->m( handled below
            target = prop_type(orx, app, ci, prop)
            is_mock = is_mock_prop(ci, prop)
        elif var is not None:
            if var == 'this':
                continue
            target = locs.get(var)
            is_mock = ('__mock__' + var) in locs
        elif recv_full in ('parent',):
            if not or_parent:
                continue
            target = or_parent
        elif recv_full in ('self', 'static'):
            continue
        else:
            fq = pf.resolve(recv_full)
            if not is_or(fq):
                continue
            target = fq
            if not static_call:
                continue
        if not target:
            # an untyped receiver that looks like an OR service
            if prop and re.search(r'(objectService|registerMapper|schemaMapper|objectEntityMapper|registerService|schemaService|openRegister|orService|fileService|auditTrail)', prop, re.I):
                res['unresolved'].append({'file': pf.rel, 'line': lineno(code, idx), 'what': f'{recv_full}->{mname}(): receiver type not resolvable (untyped/mixed property)'})
            continue
        if is_mock and mname in ('method', 'expects', 'willReturn', 'with', 'willReturnCallback', 'willReturnMap', 'willThrowException', 'onlyMethods', 'getMock', 'disableOriginalConstructor', 'setConstructorArgs', 'addMethods'):
            continue
        st, tci, a = orx.lookup(target)
        if tci is None:
            continue  # missing class reported elsewhere
        po = body_start + m.end() - 1
        pc = match_paren(code, po)
        if pc < 0:
            continue
        args_src = pf.ns[po + 1:pc]
        args_show = pf.nc[po + 1:pc]
        res['calls_checked'] += 1
        if re.search(r'(?:^|,)\s*[A-Za-z_]\w*\s*:(?!:)', args_src):
            res['named_calls'] += 1
        tm, flag = orx.find_method(tci, mname)
        if tm is None and mname in MOCK_API:
            continue  # a PHPUnit double held in a property typed as the OR class
        if tm is None:
            if flag:
                am = re.match(r'(get|set|is|has)([A-Z]\w*)$', mname)
                if am and entity_chain(orx, tci):
                    pname = am.group(2)[0].lower() + am.group(2)[1:]
                    if pname == 'id' or entity_has_prop(orx, tci, pname):
                        res.setdefault('entity_accessors', 0)
                        res['entity_accessors'] += 1
                        continue
                    add_m(pf, idx, 'entity-accessor-missing', f'{recv_desc}{op}{mname}() on {target}', f'{tci.fqcn} ({tci.pf.rel}) declares no property ${pname} and no method {mname}: Entity::__call throws BadFunctionCallException',
                          'use the accessor/property that replaced it')
                    continue
                res['unresolved'].append({'file': pf.rel, 'line': lineno(code, idx), 'what': f'{recv_full}->{mname}() on {tci.fqcn}: method not declared in OR, comes from {flag} parent/magic'})
                continue
            add_m(pf, idx, 'method-missing', f'{recv_desc}{op}{mname}() on {target}', f'{tci.fqcn} ({tci.pf.rel}) has no method {mname}',
                  'find the replacement method on OR main', silent=False)
            continue
        if tm.visibility != 'public' and not (recv_full == 'parent'):
            add_m(pf, idx, 'method-not-public', f'{recv_desc}{op}{mname}()', f'{tci.fqcn}::{tm.signature()} is {tm.visibility} ({tm.cls.pf.rel}:{tm.line})', 'use the public API that replaced it')
            continue
        if is_mock:
            continue  # args on a mock still route to the configured double; arity is not enforced
        probs = check_call(tm, args_src, static_call and recv_full != 'parent', recv_full)
        if probs is None:
            res['unresolved'].append({'file': pf.rel, 'line': lineno(code, idx), 'what': f'{recv_full}->{mname}(...spread) on {tci.fqcn}'})
            continue
        if probs:
            silent = all(p.startswith('SILENT') for p in probs)
            add_m(pf, idx, 'call-shape', f'{recv_desc}{op}{mname}({" ".join(args_show.split())[:160]})',
                  f'{tm.cls.fqcn}::{tm.signature()} ({tm.cls.pf.rel}:{tm.line})', '; '.join(probs), silent=silent)
    # mock ->method('x') on OR doubles
    for m in re.finditer(r'(\$this\s*->\s*(\w+)|\$(\w+))\s*(?:->\s*expects\s*\((?:[^()]|\([^()]*\))*\)\s*)?->\s*method\s*\(\s*([\'"])(\w+)\4', pf.nc[body_start:body_end]):
        prop, var, mname = m.group(2), m.group(3), m.group(5)
        target = prop_type(orx, app, ci, prop) if prop else locs.get(var)
        if not target:
            continue
        st, tci, a = orx.lookup(target)
        if tci is None:
            continue
        tm, flag = orx.find_method(tci, mname)
        if tm is None and not flag:
            add_m(pf, body_start + m.start(), 'mock-method-missing', f"mock of {target}->method('{mname}')", f'{tci.fqcn} ({tci.pf.rel}) has no method {mname}: PHPUnit errors when configuring it',
                  'mock the replacement method')
    for m in re.finditer(r'getMockBuilder\s*\(\s*([\\\w]+)::class\s*\)[^;]*?(?:onlyMethods|addMethods|setMethods)\s*\(\s*\[([^\]]*)\]', pf.nc[body_start:body_end], re.S):
        fq = pf.resolve(m.group(1))
        if not is_or(fq):
            continue
        st, tci, a = orx.lookup(fq)
        if tci is None:
            continue
        for nm in re.findall(r'[\'"](\w+)[\'"]', m.group(2)):
            tm, flag = orx.find_method(tci, nm)
            if tm is None and not flag:
                add_m(pf, body_start + m.start(), 'mock-method-missing', f"getMockBuilder({m.group(1)})->onlyMethods(['{nm}'])", f'{tci.fqcn} has no method {nm}', 'mock the replacement method')
    # new OR(...)
    for m in re.finditer(r'\bnew\s+([\\\w]+)\s*\(', seg):
        fq = pf.resolve(m.group(1))
        if not is_or(fq):
            continue
        st, tci, a = orx.lookup(fq)
        if tci is None:
            continue
        ctor, flag = orx.find_method(tci, '__construct')
        if ctor is None:
            continue
        po = body_start + m.end() - 1
        pc = match_paren(code, po)
        args_src = pf.ns[po + 1:pc]
        res['calls_checked'] += 1
        probs = check_call(ctor, args_src, False, 'new')
        if probs:
            add_m(pf, body_start + m.start(), 'constructor-shape', f'new {m.group(1)}({" ".join(pf.nc[po + 1:pc].split())[:160]})', f'{ctor.cls.fqcn}::{ctor.signature()} ({ctor.cls.pf.rel}:{ctor.line})',
                  '; '.join(probs), silent=all(p.startswith('SILENT') for p in probs))
    # method_exists($typed, 'm') with a resolvable OR receiver: silent false when gone
    for m in re.finditer(r'method_exists\s*\(\s*(\$this\s*->\s*(\w+)|\$(\w+))\s*,\s*([\'"])(\w+)\4', pf.nc[body_start:body_end]):
        target = prop_type(orx, app, ci, m.group(2)) if m.group(2) else locs.get(m.group(3))
        if not target:
            continue
        st, tci, a = orx.lookup(target)
        if tci is None:
            continue
        tm, flag = orx.find_method(tci, m.group(5))
        if tm is None and not flag:
            add_m(pf, body_start + m.start(), 'dynamic-method-gone', f"method_exists({m.group(1)}, '{m.group(5)}') on {target}", f'{tci.fqcn} ({tci.pf.rel}) has no {m.group(5)}: branch silently skipped',
                  'call the replacement method directly', silent=True)
    # $this->m() into an OR parent class
    if or_parent:
        pci = orx.get(or_parent, app)
        for m in re.finditer(r'\$this\s*->\s*(\w+)\s*\(', seg):
            nm = m.group(1).lower()
            if find_impl(orx, app, ci, nm) not in (None,) and ci.methods.get(nm):
                continue
            if ci.methods.get(nm):
                continue
            tm, flag = orx.find_method(pci, nm) if pci else (None, 'external')
            if tm is None:
                if not flag:
                    add_m(pf, body_start + m.start(), 'inherited-method-missing', f'$this->{m.group(1)}() (inherited from {or_parent})', f'{or_parent} has no method {m.group(1)}', 'find the replacement method')
                continue
            po = body_start + m.end() - 1
            pc = match_paren(code, po)
            probs = check_call(tm, pf.ns[po + 1:pc], False, '$this')
            if probs:
                add_m(pf, body_start + m.start(), 'call-shape', f'$this->{m.group(1)}({" ".join(pf.nc[po + 1:pc].split())[:160]}) (inherited)', f'{tm.cls.fqcn}::{tm.signature()} ({tm.cls.pf.rel}:{tm.line})',
                      '; '.join(probs), silent=all(p.startswith('SILENT') for p in probs))


MOCK_API = {'method', 'expects', 'willReturn', 'with', 'willReturnCallback', 'willReturnMap', 'willThrowException', 'willReturnSelf', 'willReturnArgument', 'willReturnOnConsecutiveCalls', 'withConsecutive', 'getMock', 'onlyMethods', 'disableOriginalConstructor', 'setConstructorArgs', 'addMethods', 'expects', 'any', '__phpunit_getInvocationHandler'}
TEXT_EXT = ('.js', '.ts', '.vue', '.json', '.php', '.mjs', '.cjs', '.tsx', '.jsx')


def scan_routes(app, orx, res):
    r = subprocess.run(['git', '-C', app.root, 'grep', '-n', '-I', '-E', r'/apps/openregister/', '--', 'src', 'lib', 'appinfo'],
                       capture_output=True, text=True).stdout
    for line in r.splitlines():
        try:
            f, ln, text = line.split(':', 2)
        except ValueError:
            continue
        if not f.endswith(TEXT_EXT):
            continue
        if f.endswith('.spec.js') or '/__tests__/' in f:
            continue
        if re.match(r'\s*(\*|//|#|/\*)', text):
            continue  # documentation, not a call
        for m in re.finditer(r'/apps/openregister(/[^\s\'"`?#)\[,]*)', text):
            path = m.group(1)
            tail = text[m.end():m.end() + 6]
            # ${...} and @placeholders and :params are wildcards
            path = re.sub(r'\$\{[^}]*\}', '*', path)
            path = re.sub(r'\$\{[^/]*', '*', path)
            path = re.sub(r'@\w+|:\w+|\{\w+\}', '*', path)
            path = path.replace('**', '*')
            if '...' in path:
                continue
            if path.startswith('/js/') or path.startswith('/img/') or path.startswith('/css/') or path.startswith('/l10n/'):
                continue
            ends_concat = bool(re.match(r'\s*[\'"`]\s*\+', tail)) or path.endswith('/')
            prefix_only = ends_concat
            if not path.startswith('/api'):
                # SPA routes are served by the catch-all dashboard route
                res['routes'].append({'file': f, 'line': int(ln), 'path': path, 'status': 'page (catch-all)'})
                continue
            path = path.rstrip('.')
            hits = route_matches(path.rstrip('/') if not prefix_only else path, orx.routes, prefix_only)
            status = 'ok' if hits else 'NO-ROUTE'
            if not hits and route_matches(path.rstrip('/') + '/', orx.routes, True):
                status = 'prefix-ok (base url, completed elsewhere)'
            res['routes'].append({'file': f, 'line': int(ln), 'path': path, 'status': status, 'prefix': prefix_only})


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--or', dest='orroot', default=os.path.join(HERE, 'or-main'))
    ap.add_argument('--apps-root', default=os.environ.get('FLEET_APPS_ROOT', os.getcwd()),
                    help='directory holding one clone per app (default: $FLEET_APPS_ROOT or the current directory)')
    ap.add_argument('--out', default=HERE)
    ap.add_argument('--server-root', default=os.environ.get('NC_SERVER_ROOT', ''),
                    help='optional nextcloud/server git clone, used to recognise Nextcloud method names in dynamic calls')
    ap.add_argument('--report', action='store_true', help='only (re)write INVENTORY.md from the json files')
    ap.add_argument('apps', nargs='*')
    a = ap.parse_args()
    if a.report:
        write_report(a.out, list(FLEET.items()))
        return
    orx = ORIndex(a.orroot)
    load_nc_methods(a.server_root)
    print(f'OR main {orx.sha}: {len(orx.classes)} classes, {len(orx.aliases)} aliases, {len(orx.routes)} routes', file=sys.stderr)
    summary = {'or_sha': orx.sha, 'apps': {}}
    for d in (a.apps or list(FLEET)):
        root = os.path.join(a.apps_root, d)
        name = FLEET.get(d, d)
        if not os.path.exists(os.path.join(root, '.git')):
            summary['apps'][name] = {'missing': True}
            continue
        br = subprocess.run(['git', '-C', root, 'rev-parse', '--abbrev-ref', 'HEAD'], capture_output=True, text=True).stdout.strip()
        sha = subprocess.run(['git', '-C', root, 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip()
        _prop_cache.clear()
        app = FleetApp(root, name, orx)
        r = scan_app(app, orx)
        r.update({'app': name, 'dir': d, 'branch': br, 'sha': sha, 'or_sha': orx.sha})
        r['mismatches'].sort(key=lambda x: (not x['silent'], x['file'], x['line']))
        with open(os.path.join(a.out, f'{name}.json'), 'w') as fh:
            json.dump(r, fh, indent=1)
        s = {'branch': br, 'sha': sha[:10], 'or_refs': r['or_refs'], 'mismatches': len(r['mismatches']),
             'silent': sum(1 for x in r['mismatches'] if x['silent']), 'unresolved': len(r['unresolved']),
             'implements': len(r['implements']), 'dynamic': len(r['dynamic']), 'calls_checked': r['calls_checked'],
             'routes': len([x for x in r['routes'] if x['status'] != 'page (catch-all)']),
             'no_route': len([x for x in r['routes'] if x['status'] == 'NO-ROUTE']),
             'stubs': len(r['stubs']), 'stub_drift': sum(1 for x in r['stubs'] if x['drift'] or x['status'] == 'missing')}
        summary['apps'][name] = s
        print(name, s, file=sys.stderr)
    sp = os.path.join(a.out, 'scan-summary.json')
    if a.apps and os.path.exists(sp):
        old = json.load(open(sp))
        old['apps'].update(summary['apps'])
        old['or_sha'] = summary['or_sha']
        summary = old
    with open(sp, 'w') as fh:
        json.dump(summary, fh, indent=1)



# --------------------------------------------------------------------------- report
KNOWN = [  # breaks reported by hand before this scanner existed
    ('portaliq', 'DestinationDraft.php', 'findDraft', 'FormSubmitService::findDraft(destination:,subject:,owner:,match:) vs findDraft(array,FormSubmitter,array)'),
    ('buildiq', 'VersionPromotionService', 'getLockInfo', 'VersionPromotionService getLockInfo missing'),
    ('decidiq', 'AgendaService', 'updateMeetingEvent', 'AgendaService updateMeetingEvent missing'),
    ('decidiq', 'MailVoteReplyProcessor', 'createNotification', 'MailVoteReplyProcessor createNotification missing'),
    ('filinq', 'EmlBackend', 'anonymizeEmlStructured', 'EmlBackend anonymizeEmlStructured missing'),
    ('integriq', 'EndpointService', 'extend:', 'EndpointService find(extend:) vs $_extend'),
    ('integriq', 'EndpointService', 'findAllPaginated', 'EndpointService findAllPaginated missing'),
    ('pipelinq', 'SlaPolicyController', 'uuid:', 'SlaPolicyController find(uuid:) vs $id'),
    ('pipelinq', 'PosTransaction', 'SchemaMapper::find', 'PosTransaction* SchemaMapper::find 5 args'),
    ('shillinq', '', 'AuditTrailService', 'AuditTrailService missing (compliance audit silently not recorded)'),
    ('shillinq', 'InitializeSettings', 'ScheduledWorkflowMapper', 'InitializeSettings ScheduledWorkflowMapper removed'),
    ('shillinq', 'WidgetAuthService', 'updateObject', 'WidgetAuthService updateObject named args'),
    ('stackiq', 'SettingsService', 'findAll', 'SettingsService OrganisationMapper::findAll 9 args'),
]
CAT_RANK = ['listener-event-never-dispatched', 'listener-event-moved', 'listener-event-missing', 'contract-method-missing', 'contract-signature',
            'override-signature', 'parent-missing', 'extends-final', 'dynamic-method-gone', 'dynamic-name-nowhere', 'string-lookup-missing',
            'alias-service-false', 'instanceof-catch-missing', 'instanceof-catch-lazy-alias', 'type-decl-lazy-alias', 'call-shape',
            'constructor-shape', 'method-missing', 'entity-accessor-missing', 'method-not-public', 'inherited-method-missing',
            'constant-missing', 'class-missing', 'mock-method-missing']


def md_esc(t):
    return str(t).replace('|', '\\|').replace('\n', ' ')


def rank(m):
    return (not m['silent'], CAT_RANK.index(m['category']) if m['category'] in CAT_RANK else 99, m['file'], m['line'])


def write_report(out, apps_order):
    summ = json.load(open(os.path.join(out, 'scan-summary.json')))
    data = {}
    for d, name in apps_order:
        p = os.path.join(out, f'{name}.json')
        if os.path.exists(p):
            data[name] = json.load(open(p))
    L = []
    w = L.append
    import datetime
    w('# Fleet breakage inventory against OpenRegister main\n')
    w(f'- OpenRegister main scanned: `{summ["or_sha"]}`')
    w(f'- Report generated: {datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M UTC")}')
    w('- Re-run: `python3 -I scan.py --or <openregister clone> --apps-root <dir holding the app clones> [app ...]`, then `python3 -I scan.py --report` (about 3 minutes, read-only on every app clone).')
    w('- Each app was scanned at its CURRENT local checkout (no branch switch); branch and sha are in the table. Machine-readable lists: `<app>.json` next to this file.')
    w('')
    w('## How to read this\n')
    w('- **lib** findings break the running app. **tests** findings break or falsify the app\'s test suite. A tests finding marked *stub* hits a class the app also declares as a test stub, so the suite currently passes against the stub while the real OR class has moved on.')
    w('- **Silent** means no error surfaces: a `method_exists` guard that is now always false, a container lookup inside a `try/catch` that now always fails, a listener that never fires, positional arguments PHP silently drops, or a class that fails to load only when first autoloaded.')
    w('- Call shapes are checked where the receiver type is known (typed property, constructor injection, typed param, `::class`/string container lookup, `new`, mocks, one hop of return-type propagation). Everything else is in the *unresolved* count, not checked.')
    w('- Out of scope of the scanner: argument TYPE changes when the app passes a variable (only literal-vs-class mismatches are flagged), return-shape changes (an array key that moved), and calls through untyped receivers.\n')
    w('## Summary\n')
    w('| app | local dir | branch @ sha | OR refs | calls checked | lib breaks (silent) | test breaks (stub) | unresolved | implements/extends OR | stub classes drifted | JS routes / no route | lib dynamic sites |')
    w('|---|---|---|---|---|---|---|---|---|---|---|---|')
    for d, name in apps_order:
        if name not in data:
            w(f'| {name} | {d} | MISSING | | | | | | | | | |')
            continue
        r = data[name]
        ms = r['mismatches']
        lib = [m for m in ms if m['scope'] == 'lib']
        tst = [m for m in ms if m['scope'] == 'tests']
        routes = [x for x in r['routes'] if x['status'] != 'page (catch-all)']
        w(f"| {name} | {d} | {r['branch']} @ {r['sha'][:9]} | {r['or_refs']} | {r['calls_checked']} | {len(lib)} ({sum(m['silent'] for m in lib)}) | "
          f"{len(tst)} ({sum(m['test_stub_exists'] for m in tst)}) | {len(r['unresolved'])} | {len(r['implements'])} | "
          f"{sum(1 for x in r['stubs'] if x['drift'] or x['status'] == 'missing')}/{len(r['stubs'])} | {len(routes)} / {sum(1 for x in routes if x['status'] == 'NO-ROUTE')} | "
          f"{sum(1 for x in r['dynamic'] if x['file'].startswith('lib'))} |")
    w('')
    # known items
    w('## Already-known items (reported by hand before this scan)\n')
    w('| app | known item | found by this scan |')
    w('|---|---|---|')
    for app, fsub, needle, desc in KNOWN:
        r = data.get(app)
        hit = None
        if r:
            for m in r['mismatches']:
                if fsub in m['file'] and (needle in m['app_calls'] or needle in m['or_main'] or needle in m['fix']):
                    hit = m
                    break
        w(f"| {app} | {md_esc(desc)} | {('yes: `' + hit['file'] + ':' + str(hit['line']) + '`') if hit else 'NOT FOUND by the scan (untyped receiver or not on this checkout): see the app section'} |")
    w('')
    # fleet-wide ranked lib list
    w('## Fleet-wide lib breaks, silent first\n')
    w('| # | app | file:line | category | silent | what the app does |')
    w('|---|---|---|---|---|---|')
    allm = [(name, m) for name, r in data.items() for m in r['mismatches'] if m['scope'] == 'lib']
    allm.sort(key=lambda x: rank(x[1]))
    for i, (name, m) in enumerate(allm, 1):
        w(f"| {i} | {name} | `{m['file']}:{m['line']}` | {m['category']} | {'SILENT' if m['silent'] else 'loud'} | {md_esc(m['app_calls'][:140])} |")
    w('')
    for d, name in apps_order:
        w(f'## {name}\n')
        if name not in data:
            w(f'Clone `{d}` missing; skipped.\n')
            continue
        r = data[name]
        w(f"Local dir `{d}`, branch `{r['branch']}`, sha `{r['sha']}`.\n")
        lib = sorted([m for m in r['mismatches'] if m['scope'] == 'lib'], key=rank)
        tst = sorted([m for m in r['mismatches'] if m['scope'] == 'tests'], key=rank)
        w(f'### Runtime breaks (lib): {len(lib)}\n')
        if lib:
            w('| file:line | category | app calls | OR main now | fix |')
            w('|---|---|---|---|---|')
            for m in lib:
                w(f"| `{m['file']}:{m['line']}` | {m['category']}{' (SILENT)' if m['silent'] else ''} | {md_esc(m['app_calls'][:220])} | {md_esc(m['or_main'][:260])} | {md_esc(m['fix'][:260])} |")
            w('')
        w(f'### Test-suite breaks (tests): {len(tst)}\n')
        if tst:
            w('| file:line | category | stub | app calls | OR main now | fix |')
            w('|---|---|---|---|---|---|')
            for m in tst:
                w(f"| `{m['file']}:{m['line']}` | {m['category']} | {'stub' if m['test_stub_exists'] else ''} | {md_esc(m['app_calls'][:160])} | {md_esc(m['or_main'][:200])} | {md_esc(m['fix'][:200])} |")
            w('')
        drift = [x for x in r['stubs'] if x['drift'] or x['status'] == 'missing']
        if drift:
            w(f'### Test stubs of OR classes that drifted from OR main: {len(drift)} of {len(r["stubs"])}\n')
            for x in drift:
                det = '; '.join(x['drift'][:6]) if x['drift'] else 'class no longer exists on OR main'
                w(f"- `{x['file']}:{x['line']}` `{x['class']}` ({x['status']}): {md_esc(det)}{' ...' if len(x['drift']) > 6 else ''}")
            w('')
        impl = r['implements']
        if impl:
            w(f'### Implements / extends OR classes: {len(impl)}\n')
            for x in impl:
                w(f"- `{x['file']}:{x['line']}` {x['class'].split(chr(92))[-1]} {x['kind']} `{x['or_class']}` ({x['status']})")
            w('')
        lst = r['listeners']
        if lst:
            c = {}
            for x in lst:
                c.setdefault((x['event'], x['status'], x.get('dispatch')), []).append(f"{x['file']}:{x['line']}")
            w(f'### OR event listeners: {len(lst)} registrations\n')
            for (ev, st, disp), where in sorted(c.items()):
                w(f"- `{ev}` ({st}, OR main {disp}): {len(where)}x, e.g. `{where[0]}`")
            w('')
        nr = [x for x in r['routes'] if x['status'] == 'NO-ROUTE']
        if nr:
            w(f'### JS/manifest URLs with no matching OR main route: {len(nr)}\n')
            for x in nr:
                w(f"- `{x['file']}:{x['line']}` `/apps/openregister{x['path']}`")
            w('')
        dyn = [x for x in r['dynamic'] if x['file'].startswith('lib')]
        if dyn:
            w(f'<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): {len(dyn)}. Always suspect: a renamed OR method makes these silently skip.</summary>\n')
            for x in dyn:
                w(f"- `{x['file']}:{x['line']}` `{md_esc(x['src'][:160]).replace('`', '')}`")
            w('\n</details>\n')
        w(f"Unresolved call sites (receiver type unknown, magic, or spread args): {len(r['unresolved'])}, listed in `{name}.json`.\n")
    open(os.path.join(out, 'INVENTORY.md'), 'w').write('\n'.join(L) + '\n')


if __name__ == '__main__':
    main()
