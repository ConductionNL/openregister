#!/usr/bin/env bash
#
# update.sh — sync skill-creator with upstream anthropics/skills via 3-way merge.
#
# Replaces the previous rsync-and-patch approach. Uses git's merge-file to
# combine three trees: the pinned upstream snapshot (.upstream-version), upstream
# HEAD, and your local working copy. Local edits, additions, and deletions are
# preserved automatically — the local-mods.patch file is no longer needed.
#
# Usage:
#   bash update.sh             # dry-run summary (no changes)
#   bash update.sh --apply     # apply the merge
#   bash update.sh --apply --force-conflicts   # write conflict markers and continue
#
set -euo pipefail
SKILL_DIR="$(cd "$(dirname "$0")" && pwd)"
exec python3 "$SKILL_DIR/update_from_upstream.py" --skill-dir "$SKILL_DIR" "$@"
