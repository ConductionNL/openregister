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
import os, re, sys, json, collections
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

def main():
    root = os.path.abspath(sys.argv[1])
    apply = '--apply' in sys.argv[2:]
    cats = collections.Counter()
    files_changed = []
    dangling = []  # (file, target, note)
    repoint_map_global = {}  # target -> new (for report)
    for fp in iter_files(root):
        try:
            # newline='' -> universal-newline translation OFF, so CRLF files keep
            # their CRLF endings verbatim on the round-trip. Without this, Python
            # normalises CRLF->LF on write and silently reformats the whole file
            # (observed on procest: 5 CRLF files, 2462 non-@spec diff lines).
            with open(fp, encoding='utf-8', errors='strict', newline='') as fh:
                src = fh.read()
        except (OSError, UnicodeDecodeError):
            # errors='strict' + skip: never rewrite a file we cannot decode
            # losslessly (errors='replace' would burn U+FFFD into the source).
            continue
        local = {}  # old_target -> new_target
        for m in TAG.finditer(src):
            target = m.group(1)
            broken, path, frag = is_broken(root, target)
            if not broken:
                continue
            r = resolver.resolve(root, path, frag)
            cats[r['category']] += 1
            if r['category'].startswith('REPOINT') and r['new_target']:
                # sanity: the new target MUST itself resolve (gate-green) before we accept it
                nb, _, _ = is_broken(root, r['new_target'])
                if nb:
                    cats['REPOINT_REJECTED_STILL_BROKEN'] += 1
                    cats[r['category']] -= 1
                    dangling.append((fp, target, 'repoint candidate still broken: ' + r['new_target']))
                    continue
                local[target] = r['new_target']
                repoint_map_global[target] = r['new_target']
            else:
                dangling.append((fp, target, r['note']))
        if local and apply:
            def _sub(mo):
                t = mo.group(1)
                if t in local:
                    return mo.group(0).replace(t, local[t])
                return mo.group(0)
            new = TAG.sub(_sub, src)
            if new != src:
                with open(fp, 'w', encoding='utf-8', newline='') as fh:
                    fh.write(new)
                files_changed.append(fp)
        elif local:
            files_changed.append(fp)
    report = {
        'root': root,
        'counts': dict(cats),
        'repoint_anchor': cats.get('REPOINT_ANCHOR', 0),
        'repoint_file': cats.get('REPOINT_FILE', 0),
        'dangling': len(dangling),
        'files_changed': len(files_changed),
    }
    base = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        os.path.basename(root))
    json.dump({**report, 'changed_files': files_changed}, open(base + '.report.json', 'w'), indent=1)
    # dangling review list grouped by note-kind
    with open(base + '.dangling.md', 'w') as g:
        g.write(f"# Residual dangling @spec anchors — {os.path.basename(root)}\n\n")
        g.write(f"{len(dangling)} anchors could not be repointed unambiguously and need human triage.\n\n")
        def cause(note):
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
        by = collections.defaultdict(list)
        for fp, t, note in dangling:
            by[cause(note)].append((fp, t))
        for note, items in sorted(by.items(), key=lambda kv: -len(kv[1])):
            g.write(f"\n## {note} ({len(items)})\n")
            for fp, t in items[:15]:
                g.write(f"- `{os.path.relpath(fp, root)}` → `{t}`\n")
            if len(items) > 15:
                g.write(f"- … +{len(items)-15} more\n")
    print(json.dumps(report, indent=1))

if __name__ == '__main__':
    main()
