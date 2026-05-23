#!/usr/bin/env python3
"""Sync skill-creator from upstream anthropics/skills via 3-way merge.

Replaces the older rsync + local-mods.patch approach. Treats the three trees:

    base    = upstream snapshot at .upstream-version
    theirs  = upstream HEAD
    ours    = current local skill-creator/

and merges them per-file using ``git merge-file``. Local edits, local additions,
and local deletions are preserved automatically. Conflicts are reported clearly
and (in --apply mode) written as standard ``<<<<<<<`` markers so you can resolve
them with your editor.

Files matching LOCAL_META are never touched by the merge — they are this script,
its wrapper, and the version-pin file itself.
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

UPSTREAM_REPO = "https://github.com/anthropics/skills.git"
UPSTREAM_SUBPATH = "skills/skill-creator"

# Files in the skill dir that are local-only metadata. Never compared, never
# overwritten, never fed into the merge.
LOCAL_META = {".upstream-version", "update.sh", "update_from_upstream.py", "local-mods.patch"}

# Path components to skip entirely when scanning the local tree.
SKIP_PARTS = {".git", "__pycache__"}


def run(cmd: list[str], **kw) -> subprocess.CompletedProcess:
    return subprocess.run(cmd, check=True, capture_output=True, text=True, **kw)


def run_ok(cmd: list[str], **kw) -> Optional[subprocess.CompletedProcess]:
    try:
        return run(cmd, **kw)
    except subprocess.CalledProcessError:
        return None


# ---------------------------------------------------------------------------
# Tree access helpers
# ---------------------------------------------------------------------------


def list_upstream_files(repo_dir: Path, commit: str) -> set[str]:
    """List relative paths (under UPSTREAM_SUBPATH) at a given commit."""
    out = run(["git", "-C", str(repo_dir), "ls-tree", "-r", "--name-only", commit, "--", UPSTREAM_SUBPATH]).stdout
    prefix = UPSTREAM_SUBPATH + "/"
    return {line[len(prefix):] for line in out.splitlines() if line.startswith(prefix)}


def read_upstream_blob(repo_dir: Path, commit: str, rel: str) -> bytes:
    return run(
        ["git", "-C", str(repo_dir), "show", f"{commit}:{UPSTREAM_SUBPATH}/{rel}"],
    ).stdout.encode("utf-8", errors="surrogateescape")


def read_upstream_blob_raw(repo_dir: Path, commit: str, rel: str) -> bytes:
    """Read a blob preserving binary content (no decode)."""
    proc = subprocess.run(
        ["git", "-C", str(repo_dir), "show", f"{commit}:{UPSTREAM_SUBPATH}/{rel}"],
        check=True,
        capture_output=True,
    )
    return proc.stdout


def list_local_files(skill_dir: Path) -> set[str]:
    files: set[str] = set()
    for p in skill_dir.rglob("*"):
        if not p.is_file():
            continue
        rel = p.relative_to(skill_dir)
        if any(part in SKIP_PARTS for part in rel.parts):
            continue
        if len(rel.parts) == 1 and rel.name in LOCAL_META:
            continue
        files.add(rel.as_posix())
    return files


# ---------------------------------------------------------------------------
# Per-file merge
# ---------------------------------------------------------------------------


@dataclass
class FileOp:
    rel: str
    action: str        # 'unchanged' | 'add' | 'modify' | 'delete' | 'conflict' | 'keep'
    detail: str        # short human-readable summary
    new_bytes: Optional[bytes] = None  # content to write (None for unchanged/delete/keep)


def is_binary(data: bytes) -> bool:
    return b"\x00" in data[:8192]


def merge_file(rel: str, base: Optional[bytes], theirs: Optional[bytes], ours: Optional[bytes]) -> FileOp:
    # Cases by presence: 8 combinations.
    if base is None and theirs is None and ours is None:
        return FileOp(rel, "unchanged", "(unreachable)")

    if base is None and theirs is not None and ours is None:
        return FileOp(rel, "add", "added upstream", new_bytes=theirs)

    if base is None and theirs is None and ours is not None:
        return FileOp(rel, "keep", "local-only addition")

    if base is None and theirs is not None and ours is not None:
        if theirs == ours:
            return FileOp(rel, "unchanged", "added in both, identical")
        return _three_way(rel, b"", theirs, ours)

    if base is not None and theirs is None and ours is None:
        return FileOp(rel, "unchanged", "deleted in both")

    if base is not None and theirs is None and ours is not None:
        if base == ours:
            return FileOp(rel, "delete", "removed upstream, local unchanged")
        return FileOp(rel, "conflict", "removed upstream but locally modified — keeping local copy")

    if base is not None and theirs is not None and ours is None:
        # locally deleted; if upstream unchanged, leave deleted; if upstream modified, conflict
        if base == theirs:
            return FileOp(rel, "unchanged", "locally deleted, upstream unchanged")
        return FileOp(rel, "conflict", "locally deleted but modified upstream — leaving deleted")

    # All three present
    if base == theirs == ours:
        return FileOp(rel, "unchanged", "identical in all three")
    if base == theirs:
        # only ours changed
        return FileOp(rel, "unchanged", "only local changes")
    if base == ours:
        # only theirs changed
        return FileOp(rel, "modify", "upstream-only change", new_bytes=theirs)
    if theirs == ours:
        return FileOp(rel, "unchanged", "local and upstream made same change")
    return _three_way(rel, base, theirs, ours)


def _three_way(rel: str, base: bytes, theirs: bytes, ours: bytes) -> FileOp:
    """Run git merge-file on three blobs, returning a FileOp."""
    if is_binary(base) or is_binary(theirs) or is_binary(ours):
        return FileOp(rel, "conflict", "binary file with divergent content — keeping local copy")

    with tempfile.TemporaryDirectory() as td:
        td_p = Path(td)
        b_path = td_p / "base"
        t_path = td_p / "theirs"
        o_path = td_p / "ours"
        b_path.write_bytes(base)
        t_path.write_bytes(theirs)
        o_path.write_bytes(ours)
        proc = subprocess.run(
            ["git", "merge-file", "-p", "--diff3",
             "-L", "ours", "-L", "base", "-L", "upstream",
             str(o_path), str(b_path), str(t_path)],
            capture_output=True,
        )
        merged = proc.stdout
        if proc.returncode == 0:
            if merged == ours:
                return FileOp(rel, "unchanged", "merged cleanly, no change to local")
            return FileOp(rel, "modify", "3-way merge clean", new_bytes=merged)
        if proc.returncode > 0:
            return FileOp(rel, "conflict", f"{proc.returncode} merge conflict(s)", new_bytes=merged)
        return FileOp(rel, "conflict", f"merge-file failed (exit {proc.returncode})")


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------


def plan(skill_dir: Path, repo_dir: Path, pin: str, head: str) -> list[FileOp]:
    pin_files = list_upstream_files(repo_dir, pin)
    head_files = list_upstream_files(repo_dir, head)
    ours_files = list_local_files(skill_dir)
    all_paths = sorted(pin_files | head_files | ours_files)

    ops: list[FileOp] = []
    for rel in all_paths:
        base = read_upstream_blob_raw(repo_dir, pin, rel) if rel in pin_files else None
        theirs = read_upstream_blob_raw(repo_dir, head, rel) if rel in head_files else None
        local_path = skill_dir / rel
        ours = local_path.read_bytes() if rel in ours_files else None
        ops.append(merge_file(rel, base, theirs, ours))
    return ops


def apply_ops(skill_dir: Path, ops: list[FileOp], force_conflicts: bool) -> tuple[int, int]:
    written = 0
    conflicts_kept = 0
    for op in ops:
        target = skill_dir / op.rel
        if op.action in {"add", "modify"} and op.new_bytes is not None:
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(op.new_bytes)
            written += 1
        elif op.action == "delete":
            if target.exists():
                target.unlink()
                written += 1
        elif op.action == "conflict":
            if op.new_bytes is not None and force_conflicts:
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(op.new_bytes)
                conflicts_kept += 1
            # else: leave the file as-is
    return written, conflicts_kept


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--skill-dir", required=True, help="Path to skill-creator/")
    parser.add_argument("--apply", action="store_true", help="Actually write changes")
    parser.add_argument("--force-conflicts", action="store_true",
                        help="In --apply mode, write conflict markers into files instead of skipping")
    args = parser.parse_args()

    skill_dir = Path(args.skill_dir).resolve()
    pin_file = skill_dir / ".upstream-version"
    if not pin_file.exists():
        print(f"ERROR: {pin_file} not found", file=sys.stderr)
        return 1
    pin = pin_file.read_text().strip()

    with tempfile.TemporaryDirectory() as tmp:
        repo_dir = Path(tmp) / "skills"
        print(f"==> Cloning {UPSTREAM_REPO} (full history, no blobs)")
        run(["git", "clone", "--filter=blob:none", "--sparse", UPSTREAM_REPO, str(repo_dir)])
        run(["git", "-C", str(repo_dir), "sparse-checkout", "set", UPSTREAM_SUBPATH])
        head = run(["git", "-C", str(repo_dir), "rev-parse", "HEAD"]).stdout.strip()

        print(f"==> Pin:  {pin}")
        print(f"==> HEAD: {head}")

        if pin == head:
            print("==> Already up to date.")
            return 0

        if not run_ok(["git", "-C", str(repo_dir), "cat-file", "-e", pin]):
            print(f"ERROR: pinned commit {pin} not found in upstream history.", file=sys.stderr)
            return 1

        # List commits that touched the subpath since pin (informational).
        log = run(["git", "-C", str(repo_dir), "log", "--oneline", f"{pin}..{head}", "--", UPSTREAM_SUBPATH]).stdout
        print("==> Upstream commits touching skill-creator/ since pin:")
        if log.strip():
            for line in log.splitlines():
                print(f"     {line}")
        else:
            print("     (none — only metadata changed elsewhere in the repo)")

        ops = plan(skill_dir, repo_dir, pin, head)

        # Print summary by action.
        buckets: dict[str, list[FileOp]] = {}
        for op in ops:
            buckets.setdefault(op.action, []).append(op)
        print("\n==> Merge plan:")
        for action in ("add", "modify", "delete", "conflict", "keep", "unchanged"):
            entries = buckets.get(action, [])
            if not entries:
                continue
            print(f"  [{action}] {len(entries)} file(s)")
            if action != "unchanged":
                for op in entries:
                    print(f"     - {op.rel} — {op.detail}")

        conflicts = buckets.get("conflict", [])
        if not args.apply:
            print("\n==> Dry-run. Re-run with --apply to write changes.")
            if conflicts:
                print(f"==> Note: {len(conflicts)} conflict(s) — review above before applying.")
                print("     With --apply alone, conflicting files are LEFT UNCHANGED.")
                print("     With --apply --force-conflicts, conflict markers are written into the files.")
            return 0

        if conflicts and not args.force_conflicts:
            print(f"\n==> {len(conflicts)} conflict(s) — leaving those files unchanged.")
            print("    Use --force-conflicts to write merge markers, or resolve manually.")

        written, conflicts_kept = apply_ops(skill_dir, ops, args.force_conflicts)
        pin_file.write_text(head + "\n")
        print(f"\n==> Wrote {written} file(s); injected conflict markers into {conflicts_kept} file(s).")
        print(f"==> Updated .upstream-version → {head}")
        if conflicts and not args.force_conflicts:
            return 2
        return 0


if __name__ == "__main__":
    sys.exit(main())
