#!/bin/bash
# Exit 0 when every commit from the reviewed sha to the new head is a clean merge of origin/development
# (non-first parent reachable from development, no conflict-resolution edits). Exit 1 otherwise, 2 when it cannot tell.
# Usage: delta.sh <openregister clone> <pr> <reviewed-sha> <new-sha>   (same rule as ~/sig-lanes/shared/closeout/delta.sh)
D=$1; pr=$2; old=$3; new=$4
cd "$D" || exit 2
git fetch -q origin development "pull/$pr/head" || exit 2
git cat-file -e "$old^{commit}" 2>/dev/null || git fetch -q origin "$old" 2>/dev/null || exit 2
git merge-base --is-ancestor "$old" "$new" || exit 1
for c in $(git rev-list "$old..$new"); do
	parents=$(git rev-list --parents -n1 "$c" | wc -w)
	[ "$parents" -eq 3 ] || exit 1
	second=$(git rev-parse "$c^2")
	git merge-base --is-ancestor "$second" origin/development || exit 1
	[ -z "$(git diff-tree --cc --no-commit-id -p "$c")" ] || exit 1
done
exit 0
