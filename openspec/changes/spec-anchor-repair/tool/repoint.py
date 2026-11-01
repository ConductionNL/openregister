#!/usr/bin/env python3
"""Deterministic @spec anchor repointer (comment-only, gate-46-verifiable).

Usage:
  repoint.py <app-root> [--apply]

- Scans lib/ src/ for @spec openspec/... tags (same file set + regex as gate-46).
- Resolves each BROKEN tag via resolver.resolve().
- REPOINT_ANCHOR / REPOINT_FILE  -> rewritten in place (only when --apply).
- DANGLING                        -> written to review list, never guessed.
- Rewrite goes through the @spec TAG regex, so ONLY tag targets can change.
Outputs a JSON report next to this script (<root-basename>.report.json) and a
markdown review list (<root-basename>.dangling.md).
"""
import collections
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import resolver

TAG = re.compile(r'@spec\s+(openspec/[^\s`\'"]+)')

def slugify(t): return resolver.slugify(t)

def heading_slugs(p): return resolver.heading_slugs(p)

def is_broken(root, target):
    """Brokenness EXACTLY as hydra gate-46 computes it.

    The fragment is compared VERBATIM against slugify(heading) — the fragment is
    NOT slugified first. Slugifying it here would be more lenient than the gate,
    which (a) hides genuinely-broken anchors carrying trailing punctuation or
    mixed case, and (b) makes the post-repoint sanity check below unable to
    reject a target the gate would still flag.
    """
    if '#' in target:
        path, frag = target.split('#', 1)
    else:
        path, frag = target, None
    absf = os.path.join(root, path)
    if not os.path.exists(absf):
        return True, path, frag
    if frag and absf.endswith('.md') and frag not in heading_slugs(absf):
        return True, path, frag
    return False, path, frag

def iter_files(root):
    for base in ('lib', 'src'):
        bdir = os.path.join(root, base)
        if not os.path.isdir(bdir):
            continue
        for dp, dns, fns in os.walk(bdir):
            if any(x in dp for x in ('/vendor/', '/node_modules/', '/dist/', '/build/')):
                continue
            for fn in fns:
                if fn.endswith(('.php', '.vue', '.js', '.ts', '.md')):
                    yield os.path.join(dp, fn)

def dangling_cause(note):
    """The review-list group a dangling resolver note belongs to."""
    if not note:
        return 'unknown'
    if 'has no canonical spec' in note:
        return 'C. capability archived/deleted — no canonical spec (requirement genuinely gone)'
    if 'not found in' in note:
        return 'D. change uses non-annotate tasks.md (no task-N: cap#REQ line) — needs spec delta read'
    if 'tasks.md not found' in note:
        return 'D. archived change dir not located'
    if 'unrecognised' in note:
        return 'D. non-tasks.md ref (decimal task / design.md / proposal.md / specs anchor re-headed)'
    if 'no-frag' in note or 'slug' in note:
        return 'D. no-fragment tasks.md ref, slug is not a canonical capability'
    return 'other: ' + note


def read_source(fp):
    """The file text, or None when it cannot be decoded losslessly.

    newline='' keeps CRLF endings verbatim on the round-trip (Python would
    otherwise normalise CRLF->LF on write and reformat the whole file), and
    errors='strict' means a file that does not decode is never rewritten
    (errors='replace' would burn U+FFFD into the source).
    """
    try:
        with open(fp, encoding='utf-8', errors='strict', newline='') as fh:
            return fh.read()
    except (OSError, UnicodeDecodeError):
        return None


def rewrite_tags(src, local):
    """src with every @spec tag target found in local replaced by its new target."""
    def _sub(mo):
        t = mo.group(1)
        if t in local:
            return mo.group(0).replace(t, local[t])
        return mo.group(0)
    return TAG.sub(_sub, src)


class RepointRun:
    """The state of one repoint pass over an app: counts, changed files and dangling tags."""

    def __init__(self, root, apply):
        self.root = root
        self.apply = apply
        self.cats = collections.Counter()
        self.files_changed = []
        self.dangling = []  # (file, target, note)
        self.repoint_map_global = {}  # target -> new (for report)

    def scan(self, fp):
        """Resolve the broken tags of one file and rewrite it when applying."""
        src = read_source(fp)
        if src is None:
            return
        local = {}  # old_target -> new_target
        for m in TAG.finditer(src):
            target = m.group(1)
            new_target = self.resolve_tag(fp, target)
            if new_target:
                local[target] = new_target
        if not local:
            return
        if not self.apply:
            self.files_changed.append(fp)
            return
        new = rewrite_tags(src, local)
        if new != src:
            with open(fp, 'w', encoding='utf-8', newline='') as fh:
                fh.write(new)
            self.files_changed.append(fp)

    def resolve_tag(self, fp, target):
        """The verified new target of a broken tag, or None (intact, or recorded as dangling)."""
        broken, path, frag = is_broken(self.root, target)
        if not broken:
            return None
        r = resolver.resolve(self.root, path, frag)
        self.cats[r['category']] += 1
        if not (r['category'].startswith('REPOINT') and r['new_target']):
            self.dangling.append((fp, target, r['note']))
            return None
        # sanity: the new target MUST itself resolve (gate-green) before we accept it
        nb, _, _ = is_broken(self.root, r['new_target'])
        if nb:
            self.cats['REPOINT_REJECTED_STILL_BROKEN'] += 1
            self.cats[r['category']] -= 1
            self.dangling.append((fp, target, 'repoint candidate still broken: ' + r['new_target']))
            return None
        self.repoint_map_global[target] = r['new_target']
        return r['new_target']

    def report(self):
        """The summary counts of the pass."""
        return {
            'root': self.root,
            'counts': dict(self.cats),
            'repoint_anchor': self.cats.get('REPOINT_ANCHOR', 0),
            'repoint_file': self.cats.get('REPOINT_FILE', 0),
            'dangling': len(self.dangling),
            'files_changed': len(self.files_changed),
        }

    def write_dangling(self, path):
        """Write the dangling review list, grouped by cause, largest group first."""
        name = os.path.basename(self.root)
        with open(path, 'w') as g:
            g.write(f"# Residual dangling @spec anchors — {name}\n\n")
            g.write(f"{len(self.dangling)} anchors could not be repointed unambiguously and need human triage.\n\n")
            by = collections.defaultdict(list)
            for fp, t, note in self.dangling:
                by[dangling_cause(note)].append((fp, t))
            for note, items in sorted(by.items(), key=lambda kv: -len(kv[1])):
                g.write(f"\n## {note} ({len(items)})\n")
                for fp, t in items[:15]:
                    g.write(f"- `{os.path.relpath(fp, self.root)}` → `{t}`\n")
                if len(items) > 15:
                    g.write(f"- … +{len(items)-15} more\n")


def main():
    root = os.path.abspath(sys.argv[1])
    run = RepointRun(root, '--apply' in sys.argv[2:])
    for fp in iter_files(root):
        run.scan(fp)
    report = run.report()
    base = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        os.path.basename(root))
    with open(base + '.report.json', 'w') as fh:
        json.dump({**report, 'changed_files': run.files_changed}, fh, indent=1)
    run.write_dangling(base + '.dangling.md')
    print(json.dumps(report, indent=1))

if __name__ == '__main__':
    main()
