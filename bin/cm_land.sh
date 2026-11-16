#!/bin/bash
# cm_land.sh: the review ledger and the landing step of a cloud merge task. Run from the state clone (~/q).
#   bash bin/cm_land.sh review <app> <n> <sha> APPROVE|REQUEST_CHANGES <lane> <summary>
#       record a review (briefs/CLOUD-REVIEW.md) of PR n at head sha in queue/fleet-reviews.tsv; REQUEST_CHANGES
#       also writes a BLOCK verdict at that sha to queue/fleet-verdicts.tsv, so a fix task follows.
#   bash bin/cm_land.sh land <app> <workdir> <lane>
#       land the IN PRs of <workdir>/logs/<app>.tree in tree order through bin/merge_tree.sh: per PR an APPROVE
#       review at that sha, the merge gate, then one REST merge pinned to the sha, while the app's merge lock is
#       still ours. Then a verdict row for every PR that did not get into the tree (CONFLICT, LINT, RED; MOVED as
#       REVERIFY), so the feeder queues fix or verify tasks. Prints one summary line for task_done.
#       Every MERGED/RED/FAIL/SKIP row is pushed as it happens (no batching): a session that dies right after a
#       merge cannot take the ledger row with it.
# Development only; never openregister, never beta or main.
set -u
BIN=$(cd "$(dirname "$0")" && pwd)
export ST=${ST:-$(dirname "$BIN")}
source "$BIN/state.sh"
source "$BIN/work.sh"
op=$1; shift
line() { printf '%s' "$1" | tr '\t\n\r' '   ' | cut -c1-300; }
case $op in
review)
	app=$1 n=$2 sha=$3 v=$4 lane=$5 why=$(line "${6:-}")
	case $v in APPROVE|REQUEST_CHANGES) ;; *) echo "verdict must be APPROVE or REQUEST_CHANGES"; exit 2 ;; esac
	[ "${#sha}" = 40 ] || { echo "full 40-char head sha required"; exit 2; }
	[ "$app" = openregister ] && { echo "openregister is never merged here"; exit 2; }
	st_append queue/fleet-reviews.tsv "$app"$'\t'"$n"$'\t'"$sha"$'\t'"$v"$'\t'"$lane"$'\t'"$why" || exit $?
	if [ "$v" = REQUEST_CHANGES ]; then
		st_append queue/fleet-verdicts.tsv "$app"$'\t'"$n"$'\t'"$sha"$'\t'BLOCK$'\t'"$lane:review"$'\t'"review: $why" || exit $?
	fi
	echo "recorded $v for $app #$n at ${sha:0:10}"
	;;
land)
	app=$1 W=$2 lane=$3
	T=$W/logs/$app.tree
	[ "$app" = openregister ] && { echo "openregister is never merged here"; exit 2; }
	[ -f "$T" ] || { echo "no tree file $T"; exit 2; }
	if [ -s "$W/logs/$app.new.fail" ]; then echo "the tree adds unit failures: not landed"; exit 2; fi
	grep -qE '^(OK|Tests:)' "$W/logs/$app.tree.out" 2>/dev/null && grep -qE '^(OK|Tests:)' "$W/logs/$app.base.out" 2>/dev/null \
		|| { echo "no complete base and tree suite: not landed"; exit 2; }
	[ "$(_lease_field "merge-$app" holder)" = "$ST_ME" ] || { st_sync; [ "$(_lease_field "merge-$app" holder)" = "$ST_ME" ]; } \
		|| { echo "the merge lock of $app is not ours: not landed"; exit 2; }
	rem=$(gh api rate_limit --jq .resources.core.remaining 2>/dev/null || echo 0)
	[ "${rem:-0}" -lt 1500 ] && { echo "REST quota low ($rem): not landed"; exit 3; }
	ins=$(awk -F'\t' '$3 == "IN" {printf "%s%s@%s", s, $1, $2; s = ","}' "$T")
	res=none
	if [ -n "$ins" ]; then
		res=$(REQUIRE_REVIEW=1 MERGE_LOCK=$app MERGE_LOCAL_LOG=$W/merged-local.log \
			bash "$BIN/merge_tree.sh" "$W/$app" "$app" "$ins" "${STACKS:-}" "$lane" 2>&1 | tail -1)
	fi
	vrows=()
	while IFS=$'\t' read -r n sha st why; do
		case $st in
		CONFLICT) # a conflict only with an earlier PR of this tree is not the PR's fault: re-verify it alone
			case $why in
			*"merges clean on development alone"*) vrows+=("$app"$'\t'"$n"$'\t'"$sha"$'\t'REVERIFY$'\t'"$lane:merge"$'\t'"conflicts only with an earlier PR of the merge tree: $(line "$why")") ;;
			*) vrows+=("$app"$'\t'"$n"$'\t'"$sha"$'\t'"$st"$'\t'"$lane:merge"$'\t'"$(line "$why")") ;;
			esac ;;
		LINT|RED) vrows+=("$app"$'\t'"$n"$'\t'"$sha"$'\t'"$st"$'\t'"$lane:merge"$'\t'"$(line "$why")") ;;
		MOVED) vrows+=("$app"$'\t'"$n"$'\t'"$sha"$'\t'REVERIFY$'\t'"$lane:merge"$'\t'"verdict sha is not the head: $(line "$why")") ;;
		esac
	done < <(grep -v $'\tIN\t' "$T")
	[ ${#vrows[@]} -gt 0 ] && st_append queue/fleet-verdicts.tsv "${vrows[@]}"
	echo "cloud tree of $(wc -l < "$T") $app PRs: $res; conflict $(grep -c $'\tCONFLICT\t' "$T"), lint $(grep -c $'\tLINT\t' "$T"), red $(grep -c $'\tRED\t' "$T"), moved $(grep -c $'\tMOVED\t' "$T"), done $(grep -c $'\tDONE\t' "$T")"
	;;
*) echo "unknown op $op"; exit 2 ;;
esac
