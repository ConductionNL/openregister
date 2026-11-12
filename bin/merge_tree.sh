#!/bin/bash
# Merge the GREEN PRs of one fleet tree into development, in order, each behind the merge gate.
#   bash bin/merge_tree.sh <app-clone> <app> <n@sha,n@sha,...> [<stacks: a>b>c d>e>] [<lane>]
# Per PR: bin/merge_gate.py (exit 0 OK, 1 RED, 2 UNMEASURED), then one REST merge with the tested sha.
# Every result goes to queue/fleet-merged.tsv: MERGED, FAIL (non-2xx, never retried), RED (gate; also a RED
# row in queue/fleet-verdicts.tsv so a fix task follows) or SKIP (gate unmeasured, or an earlier stack member
# was not merged). Only base development of a fleet app; never main or beta, never openregister.
# Prints "merged=<k> red=<k> fail=<k> skip=<k>".
set -u
clone=$1 app=$2 prs=$3 stacks=${4:-} lane=${5:-${W:-worker}}
BIN=$(cd "$(dirname "$0")" && pwd)
export ST=${ST:-$(dirname "$BIN")}
source "$BIN/state.sh"
[ "$app" = openregister ] && { echo "openregister is never merged here"; exit 2; }
declare -A blocked
m=0 r=0 f=0 s=0
# MERGE_BATCH=1 buffers the state lines and pushes them once per run (fewer racing pushes); every line
# also goes to $MERGE_LOCAL_LOG at once, so a run that dies before the flush can be replayed.
buf_m=() buf_v=()
_line() { printf '%s' "$1" | tr '\t\n' '  ' | cut -c1-300; }
_put() {
	local file=$1; shift
	[ -n "${MERGE_LOCAL_LOG:-}" ] && printf '%s\t%s\n' "$file" "$@" >> "$MERGE_LOCAL_LOG"
	if [ -n "${MERGE_BATCH:-}" ]; then
		[ "$file" = queue/fleet-merged.tsv ] && buf_m+=("$@") || buf_v+=("$@")
	else
		st_append "$file" "$@"
	fi
}
flush() {
	local i
	for i in 1 2 3 4 5; do [ ${#buf_m[@]} = 0 ] && break; st_append queue/fleet-merged.tsv "${buf_m[@]}" && buf_m=(); done
	for i in 1 2 3 4 5; do [ ${#buf_v[@]} = 0 ] && break; st_append queue/fleet-verdicts.tsv "${buf_v[@]}" && buf_v=(); done
}
trap flush EXIT
rec() { _put queue/fleet-merged.tsv "$app"$'\t'"$1"$'\t'"$2"$'\t'"$3"$'\t'"$lane"$'\t'"$(_line "$4")"; }
stack_of() { local g; for g in $stacks; do [[ ">$g>" == *">$1>"* ]] && { echo "$g"; return; }; done; }
for item in ${prs//,/ }; do
	n=${item%@*} sha=${item#*@}
	g=$(stack_of "$n")
	if [ -n "$g" ] && [ -n "${blocked[$g]:-}" ]; then rec "$n" "$sha" SKIP "stack: #${blocked[$g]} was not merged"; s=$((s + 1)); continue; fi
	out=$(python3 "$BIN/merge_gate.py" "$clone" "$n" "$sha" 2>&1); rc=$?
	why=$(printf '%s\n' "$out" | tail -1)
	if [ "$rc" = 0 ]; then
		resp=$(gh api -X PUT "repos/ConductionNL/$app/pulls/$n/merge" -f merge_method=merge -f sha="$sha" 2>&1)
		if [ $? = 0 ]; then rec "$n" "$sha" MERGED "$why"; m=$((m + 1)); continue; fi
		rec "$n" "$sha" FAIL "$resp"; f=$((f + 1))
	elif [ "$rc" = 1 ]; then
		rec "$n" "$sha" RED "$why"
		_put queue/fleet-verdicts.tsv "$app"$'\t'"$n"$'\t'"$sha"$'\t'RED$'\t'"$lane:merge-gate"$'\t'"$why"
		r=$((r + 1))
	else
		rec "$n" "$sha" SKIP "$why"; s=$((s + 1))
	fi
	[ -n "$g" ] && blocked[$g]=$n
done
echo "merged=$m red=$r fail=$f skip=$s"
