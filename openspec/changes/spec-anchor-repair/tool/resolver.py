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
import os, re, glob as _glob

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

def _task_blocks(tasks_path, _cache={}):
    """Return {tasknum: block_text} for a tasks.md, joining wrapped continuation lines."""
    if tasks_path in _cache:
        return _cache[tasks_path]
    blocks = {}  # tasknum -> (block_text, section_cap_or_None)
    cur = None
    buf = []
    section = None
    try:
        with open(tasks_path, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except OSError:
        _cache[tasks_path] = blocks
        return blocks
    for line in lines:
        sm = _SECTION.match(line)
        if sm and cur is None:
            section = sm.group(1)
        m = _TASK_START.match(line)
        if m:
            if cur is not None:
                blocks[cur] = (' '.join(buf), cur_section)
            cur = int(m.group(1))
            cur_section = section
            buf = [line.strip()]
        elif cur is not None:
            if re.match(r'^\s*-\s*\[', line) or re.match(r'^\s*#', line):
                blocks[cur] = (' '.join(buf), cur_section); cur = None; buf = []
                sm2 = _SECTION.match(line)
                if sm2:
                    section = sm2.group(1)
            elif line.strip():
                buf.append(line.strip())
    if cur is not None:
        blocks[cur] = (' '.join(buf), cur_section)
    _cache[tasks_path] = blocks
    return blocks

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

def _canonical_anchor(root, cap, desc):
    """Return (relpath, anchor_or_None, category) for capability cap + requirement desc."""
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

def resolve(root, path, frag):
    """Given a broken target (path, frag) under app <root>, return a dict:
       {category, new_target|None, note}."""
    # Shape 1: changes/<slug>/tasks.md[#task-N]
    m = re.match(r'openspec/changes/([^/]+)/tasks\.md$', path)
    if m and frag and re.match(r'task-(\d+)$', frag or ''):
        slug = m.group(1)
        tnum = int(frag.split('-', 1)[1])
        tp = _find_archived_tasks(root, slug)
        if not tp:
            return {'category': 'DANGLING', 'new_target': None,
                    'note': f'archived change {slug} tasks.md not found'}
        parsed = _parse_task(tp, tnum)
        if not parsed:
            return {'category': 'DANGLING', 'new_target': None,
                    'note': f'task-{tnum} not found in {slug}/tasks.md'}
        cap, reqid, desc = parsed
        res = _canonical_anchor(root, cap, desc)
        if res is None:
            return {'category': 'DANGLING', 'new_target': None,
                    'note': f'capability {cap} has no canonical spec'}
        rel, anchor, cat = res
        return {'category': cat, 'new_target': rel + (f'#{anchor}' if anchor else ''),
                'note': f'{cap}#{reqid}'}
    # Shape 1b: changes/<slug>/tasks.md with NO task fragment, where <slug> is itself
    # a canonical capability name -> file-level repoint (unambiguous: slug == cap verbatim).
    if m and not frag:
        slug = m.group(1)
        rel = f'openspec/specs/{slug}/spec.md'
        if os.path.isfile(os.path.join(root, rel)):
            return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': f'slug==cap {slug}'}
        return {'category': 'DANGLING', 'new_target': None,
                'note': f'no-frag tasks.md, slug {slug} not a canonical cap'}
    # Shape 2: changes/<slug>/specs/<cap>/spec.md[#anchor]
    m = re.match(r'openspec/changes/([^/]+)/specs/([^/]+)/spec\.md$', path)
    if m:
        cap = m.group(2)
        rel = f'openspec/specs/{cap}/spec.md'
        absf = os.path.join(root, rel)
        if not os.path.isfile(absf):
            return {'category': 'DANGLING', 'new_target': None,
                    'note': f'capability {cap} has no canonical spec'}
        if not frag:
            return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': cap}
        if slugify(frag) in heading_slugs(absf):
            return {'category': 'REPOINT_ANCHOR', 'new_target': f'{rel}#{frag}', 'note': cap}
        # fragment lost — capability proven, drop to file-level
        return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': f'{cap} (anchor {frag} not in canonical)'}
    # anything else (specs/* anchor-nf, design.md, proposal.md, OTHER) -> human review
    return {'category': 'DANGLING', 'new_target': None, 'note': 'unrecognised shape'}
