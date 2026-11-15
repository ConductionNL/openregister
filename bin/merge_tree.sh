#!/bin/bash
# Merge the GREEN PRs of one fleet tree into development, in order, each behind the merge gate.
#   bash bin/merge_tree.sh <app-clone> <app> <n@sha,n@sha,...> [<stacks: a>b>c d>e>] [<lane>]
# Per PR: bin/merge_gate.py (exit 0 OK, 1 RED, 2 UNMEASURED), then one REST merge with the tested sha.
# Every result goes to queue/fleet-merged.tsv: MERGED, FAIL (non-2xx, never retried), RED (gate; also a RED
# row in queue/fleet-verdicts.tsv so a fix task follows) or SKIP (gate unmeasured, or an earlier stack member
# was not merged). Only base development of a fleet app; never main or beta, never openregister.
# REQUIRE_REVIEW=1 (cloud workers): a PR merges only with an APPROVE review at its head sha as the latest
# review row of that sha in queue/fleet-reviews.tsv; else SKIP. MERGE_LOCK=<app>: renew the app's merge lock
# before every merge and stop (SKIP the rest) when it is lost.
# Prints "merged=<k> red=<k> fail=<k> skip=<k>".
set -u
clone=$1 app=$2 prs=$3 stacks=${4:-} lane=${5:-${W:-worker}}
BIN=${MT_BIN:-$(cd "$(dirname "$0")" && pwd)}
export ST=${ST:-$(dirname "$BIN")}
source "$BIN/state.sh"
source "$BIN/work.sh"
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
# latest review verdict of app/pr at sha in queue/fleet-reviews.tsv (APPROVE, REQUEST_CHANGES or empty)
review_at() {
	awk -F'\t' -v a="$app" -v n="$1" -v s="$2" '$1 == a && $2 == n && $3 == s {v = $4} END {print v}' "$ST/queue/fleet-reviews.tsv" 2>/dev/null
}
[ -n "${REQUIRE_REVIEW:-}" ] && { st_sync || st_sync; }
lost=""
rec() { _put queue/fleet-merged.tsv "$app"$'\t'"$1"$'\t'"$2"$'\t'"$3"$'\t'"$lane"$'\t'"$(_line "$4")"; }
stack_of() { local g; for g in $stacks; do [[ ">$g>" == *">$1>"* ]] && { echo "$g"; return; }; done; }
# Every merge but the last item of the list carries [skip ci]: one development CI run per batch.
last_item=${prs##*,}
for item in ${prs//,/ }; do
	n=${item%@*} sha=${item#*@}
	skipci=(-f "commit_message=Merged in a batch of $app PRs [skip ci]"); [ "$item" = "$last_item" ] && skipci=()
	g=$(stack_of "$n")
	if [ -n "$g" ] && [ -n "${blocked[$g]:-}" ]; then rec "$n" "$sha" SKIP "stack: #${blocked[$g]} was not merged"; s=$((s + 1)); continue; fi
	if [ -n "$lost" ]; then rec "$n" "$sha" SKIP "merge lock lost"; s=$((s + 1)); continue; fi
	if [ -n "${REQUIRE_REVIEW:-}" ] && [ "$(review_at "$n" "$sha")" != APPROVE ]; then
		rec "$n" "$sha" SKIP "no APPROVE review at this head"; s=$((s + 1))
		[ -n "$g" ] && blocked[$g]=$n
		continue
	fi
	out=$(python3 "$BIN/merge_gate.py" "$clone" "$n" "$sha" 2>&1); rc=$?
	why=$(printf '%s\n' "$out" | tail -1)
	if [ "$rc" = 0 ] && [ -n "${MERGE_LOCK:-}" ] && ! merge_lock_renew "$MERGE_LOCK"; then
		lost=1; rec "$n" "$sha" SKIP "merge lock lost"; s=$((s + 1)); continue
	fi
	if [ "$rc" = 0 ]; then
		resp=$(gh api -X PUT "repos/ConductionNL/$app/pulls/$n/merge" -f merge_method=merge -f sha="$sha" "${skipci[@]}" 2>&1)
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
