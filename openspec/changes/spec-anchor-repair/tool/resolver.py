#!/usr/bin/env python3
"""Deterministic @spec anchor repointer.

For a broken `@spec openspec/...` target, resolve the INTENDED canonical target
using ONLY unambiguous, verifiable signals, or classify it for human review.

Categories (task spec a/b/c/d):
  REPOINT_ANCHOR  : exact requirement-heading text match in canonical specs/<cap>/spec.md  -> new anchor
  REPOINT_FILE    : canonical specs/<cap>/spec.md exists, exact requirement heading not found -> file-level (cap proven, no fragment)
  DANGLING        : capability not recoverable / canonical spec file does not exist -> flag for human
Only REPOINT_* are auto-applied. DANGLING is never guessed.
"""
import collections, os, re, glob as _glob

_TAG_SLUG = re.compile(r'[^a-z0-9]+')

def slugify(text):
    return _TAG_SLUG.sub('-', text.strip().lower()).strip('-')

def heading_slugs(md_path, _cache={}):
    if md_path in _cache:
        return _cache[md_path]
    hs = set()
    try:
        with open(md_path, encoding='utf-8', errors='replace') as f:
            for line in f:
                m = re.match(r'^\s*#{1,6}\s+(.+?)\s*$', line)
                if m:
                    hs.add(slugify(m.group(1)))
    except OSError:
        pass
    _cache[md_path] = hs
    return hs

_TASK_START = re.compile(r'^\s*-\s*\[.\]\s*\*{0,2}task-(\d+)\*{0,2}(?=[\s:(])')
_SECTION = re.compile(r'^\s*##\s+([a-z0-9][a-z0-9-]{2,})\s*$')
_CAP_TOK = re.compile(r'\b([a-z0-9][a-z0-9-]{2,})#')
_QUOTED = re.compile(r'"([^"]{4,})"')

def _strip_desc(desc):
    if not desc:
        return ''
    desc = re.sub(r'\s*\((?:retroactive annotation|cross-cap[^)]*)\)\s*$', '', desc)
    return desc.strip()

class _TaskBlockParser:
    """Collects {tasknum: (block_text, section_cap_or_None)} line by line, joining wrapped continuation lines."""

    def __init__(self):
        self.blocks = {}
        self.cur = None
        self.cur_section = None
        self.buf = []
        self.section = None

    def feed(self, line):
        sm = _SECTION.match(line)
        if sm and self.cur is None:
            self.section = sm.group(1)
        m = _TASK_START.match(line)
        if m:
            self._start(m, line)
        elif self.cur is not None:
            self._continue(line)

    def _start(self, m, line):
        self.finish()
        self.cur = int(m.group(1))
        self.cur_section = self.section
        self.buf = [line.strip()]

    def _continue(self, line):
        if re.match(r'^\s*-\s*\[', line) or re.match(r'^\s*#', line):
            self.finish()
            self.cur = None
            self.buf = []
            sm2 = _SECTION.match(line)
            if sm2:
                self.section = sm2.group(1)
        elif line.strip():
            self.buf.append(line.strip())

    def finish(self):
        """Close the open task block, if any."""
        if self.cur is not None:
            self.blocks[self.cur] = (' '.join(self.buf), self.cur_section)


def _task_blocks(tasks_path, _cache={}):
    """Return {tasknum: block_text} for a tasks.md, joining wrapped continuation lines."""
    if tasks_path in _cache:
        return _cache[tasks_path]
    parser = _TaskBlockParser()
    try:
        with open(tasks_path, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except OSError:
        _cache[tasks_path] = parser.blocks
        return parser.blocks
    for line in lines:
        parser.feed(line)
    parser.finish()
    _cache[tasks_path] = parser.blocks
    return parser.blocks

def _find_archived_tasks(root, slug):
    """Locate the tasks.md for an archived/live change <slug>. Deterministic first,
    then a single unambiguous archive fallback by exact slug."""
    cands = [
        os.path.join(root, 'openspec/changes', slug, 'tasks.md'),
        os.path.join(root, 'openspec/changes/archive', slug, 'tasks.md'),
        os.path.join(root, 'openspec/archive', slug, 'tasks.md'),
    ]
    for c in cands:
        if os.path.isfile(c):
            return c
    # Deterministic date-prefixed archive convention: changes/archive/<YYYY-MM-DD>-<slug>
    for base in ('openspec/changes/archive', 'openspec/archive'):
        hits = sorted(_glob.glob(os.path.join(root, base, f'????-??-??-{slug}', 'tasks.md')))
        # require exact date-prefix + slug suffix (unambiguous)
        hits = [h for h in hits if re.fullmatch(r'\d{4}-\d{2}-\d{2}-' + re.escape(slug),
                                                os.path.basename(os.path.dirname(h)))]
        if len(hits) == 1:
            return hits[0]
    return None

def _parse_task(tasks_path, tasknum):
    """Return (cap, reqid_or_None, desc_title) for task-N, or None."""
    entry = _task_blocks(tasks_path).get(tasknum)
    if not entry:
        return None
    block, section = entry
    # strip the leading "- [x] **task-N**:" marker
    body = _TASK_START.sub('', block, count=1).lstrip(' :').strip()
    cm = _CAP_TOK.search(body)
    reqid = None
    if cm:
        cap = cm.group(1)
        rm = re.match(r'#(REQ-[A-Z0-9-]+|[A-Z0-9][A-Z0-9-]*)', body[cm.end() - 1:])
        if rm:
            reqid = rm.group(1)
    elif section:
        # No inline cap#REQ — capability comes from the `## <cap>` section heading.
        cap = section
    else:
        return None
    # title: prefer quoted string; else text after em/en dash
    qm = _QUOTED.search(body)
    if qm:
        title = qm.group(1)
    else:
        dm = re.search(r'[—–]\s*(.+)$', body)
        title = dm.group(1) if dm else ''
    return cap, reqid, _strip_desc(title)

def _canonical_anchor(root, parsed):
    """Return (relpath, anchor_or_None, category) for a parsed task's capability + requirement desc."""
    cap, _, desc = parsed
    rel = f'openspec/specs/{cap}/spec.md'
    absf = os.path.join(root, rel)
    if not os.path.isfile(absf):
        return None
    slugs = heading_slugs(absf)
    if desc:
        for cand in (f'requirement-{slugify(desc)}', slugify(desc)):
            if cand in slugs:
                return (rel, cand, 'REPOINT_ANCHOR')
    return (rel, None, 'REPOINT_FILE')

_Ref = collections.namedtuple('_Ref', 'root path frag')


def _dangling(note):
    return {'category': 'DANGLING', 'new_target': None, 'note': note}


def resolve(root, path, frag):
    """Given a broken target (path, frag) under app <root>, return a dict:
       {category, new_target|None, note}."""
    ref = _Ref(root, path, frag)
    for shape in (_resolve_tasks_ref, _resolve_change_spec_ref, _resolve_canonical_ref):
        result = shape(ref)
        if result is not None:
            return result
    # anything else (specs/* anchor genuinely re-headed, design.md, proposal.md,
    # OTHER) -> human review
    return _dangling('unrecognised shape')


def _resolve_tasks_ref(ref):
    """Shape 1: changes/<slug>/tasks.md[#task-N]; None when the path or fragment is another shape."""
    m = re.match(r'openspec/changes/([^/]+)/tasks\.md$', ref.path)
    if not m:
        return None
    if not ref.frag:
        return _resolve_tasks_file(ref.root, m.group(1))
    if not re.match(r'task-(\d+)$', ref.frag):
        return None
    return _resolve_task(ref, m.group(1))


def _resolve_task(ref, slug):
    """changes/<slug>/tasks.md#task-N -> the canonical requirement the task line names."""
    tnum = int(ref.frag.split('-', 1)[1])
    tp = _find_archived_tasks(ref.root, slug)
    if not tp:
        return _dangling(f'archived change {slug} tasks.md not found')
    parsed = _parse_task(tp, tnum)
    if not parsed:
        return _dangling(f'task-{tnum} not found in {slug}/tasks.md')
    cap, reqid, _ = parsed
    res = _canonical_anchor(ref.root, parsed)
    if res is None:
        return _dangling(f'capability {cap} has no canonical spec')
    rel, anchor, cat = res
    return {'category': cat, 'new_target': rel + (f'#{anchor}' if anchor else ''),
            'note': f'{cap}#{reqid}'}


def _resolve_tasks_file(root, slug):
    """Shape 1b: changes/<slug>/tasks.md with NO task fragment, where <slug> is itself
    a canonical capability name -> file-level repoint (unambiguous: slug == cap verbatim)."""
    rel = f'openspec/specs/{slug}/spec.md'
    if os.path.isfile(os.path.join(root, rel)):
        return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': f'slug==cap {slug}'}
    return _dangling(f'no-frag tasks.md, slug {slug} not a canonical cap')


def _resolve_change_spec_ref(ref):
    """Shape 2: changes/<slug>/specs/<cap>/spec.md[#anchor] -> the canonical spec of <cap>."""
    m = re.match(r'openspec/changes/([^/]+)/specs/([^/]+)/spec\.md$', ref.path)
    if not m:
        return None
    cap = m.group(2)
    rel = f'openspec/specs/{cap}/spec.md'
    absf = os.path.join(ref.root, rel)
    if not os.path.isfile(absf):
        return _dangling(f'capability {cap} has no canonical spec')
    frag = ref.frag
    if not frag:
        return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': cap}
    if slugify(frag) in heading_slugs(absf):
        # Emit the SLUGIFIED fragment, never the raw one. Gate-46 compares the
        # fragment verbatim against slugify(heading), so a raw fragment carrying
        # trailing punctuation (the @spec regex swallows a sentence-ending '.')
        # or mixed case would still be flagged broken after the repoint.
        return {'category': 'REPOINT_ANCHOR', 'new_target': f'{rel}#{slugify(frag)}', 'note': cap}
    # fragment lost — capability proven, drop to file-level
    return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': f'{cap} (anchor {frag} not in canonical)'}


def _resolve_canonical_ref(ref):
    """Shape 3: an ALREADY-canonical specs/<cap>/spec.md#frag whose fragment fails
    gate-46's verbatim compare but whose SLUGIFIED form is a real heading.

    The capability and the requirement are both already proven; only the fragment's
    punctuation/case is off (the @spec regex swallows a sentence-ending '.').
    Normalising it is unambiguous: it spells the target that is already there the
    way gate-46 reads it. This also self-heals the raw-fragment leak emitted by
    earlier tool rounds.
    """
    m = re.match(r'openspec/specs/([^/]+)/spec\.md$', ref.path)
    if not (m and ref.frag):
        return None
    absf = os.path.join(ref.root, ref.path)
    if not os.path.isfile(absf):
        return None
    slugs = heading_slugs(absf)
    if ref.frag not in slugs and slugify(ref.frag) in slugs:
        return {'category': 'REPOINT_ANCHOR',
                'new_target': f'{ref.path}#{slugify(ref.frag)}',
                'note': f'{m.group(1)} (fragment normalised to gate-46 form)'}
    return None
