#!/bin/bash
# Shared task queue for cloud workers, on the sig-pipeline/state branch. Source it after bin/state.sh.
#
# queue/work.tsv       (feeder, append-only) id  type  app  base  target  payload  created
# queue/work-done.tsv  (workers, append-only) id  result  holder  finished
#
# A task is OPEN while it has no done row. It is CLAIMED while a live lease task-<id> exists (120 min);
# a worker that dies loses its lease and the task becomes claimable again.
#   worker_slot                 take a free worker-01..58 slot (360 min); prints the slot name; 1 = none free
#   task_claim <worker> [app] [types]
#                               take the oldest claimable task (open-PR work first, then by app order, type rank
#                               and created), of [app] first when
#                               it has one (reuses that app's clone and vendor), else of any app; prints the row; 1 = none
#   task_renew <id>             renew the task lease (0 = still yours)
#   task_done <id> <result>     record the result and release the task lease
#   task_lease <id>             print the lease name of a task
#   task_open                   print every open task (claimed or not), oldest first
# Type rank: fix, review/measure, verify/merge, gap, cover. Type `integrate` (openregister main, behind the single
# `integrator` lease) is reserved: never claimed unless named in [types] (comma list).
WQ=queue/work.tsv
WD=queue/work-done.tsv
WORKER_SLOTS=${WORKER_SLOTS:-58}

task_lease() { printf 'task-%s' "$(printf '%s' "$1" | tr -c 'A-Za-z0-9._-' '_')"; }

worker_slot() {
	local k
	mkdir -p "$ST/leases"
	for k in $(seq -w 1 "$WORKER_SLOTS" | shuf); do
		k=$(printf '%02d' "$((10#$k))")
		lease_acquire "worker-$k" 360 && { echo "worker-$k"; return 0; }
	done
	return 1
}

# Open tasks as "<rank>\t<created>\t<row>", sorted; with $1=claimable also drops tasks under a live lease.
_task_rows() {
	local mode=${1:-open} types=${2:-}
	awk -F'\t' -v mode="$mode" -v types=",$types," -v now="$(date -u +%s)" -v leases="$ST/leases" \
		-v order="$(grep -v '^#' "$ST/queue/app-order.txt" 2>/dev/null | tr '\n' ' ')" '
		BEGIN { n = split(order, o, " "); for (i = 1; i <= n; i++) pos[o[i]] = i }
		function lname(id,   s) { s = id; gsub(/[^A-Za-z0-9._-]/, "_", s); return leases "/task-" s ".lease" }
		function live(id,   f, line, e) {
			f = lname(id); e = 0
			while ((getline line < f) > 0) if (line ~ /^expires_epoch=/) { sub(/^expires_epoch=/, "", line); e = line + 0 }
			close(f); return e > now
		}
		FNR == NR { if ($0 !~ /^#/ && NF) done[$1] = 1; next }
		/^#/ || NF < 7 || ($1 in done) || ($1 in seen) { next }
		{
			seen[$1] = 1
			r = ($2 == "fix") ? 1 : ($2 == "review" || $2 == "measure") ? 2 : ($2 == "verify" || $2 == "merge") ? 3 : ($2 == "gap") ? 4 : ($2 == "cover") ? 5 : 9
			# Finishing open PRs (verify, merge, review) comes first; all other work goes app by app in
			# queue/app-order.txt order, by type rank within an app.
			if ($2 != "verify" && $2 != "merge" && $2 != "review") r += 10 * (($3 in pos) ? pos[$3] : 90)
			if (types != ",,") { if (index(types, "," $2 ",") == 0) next }
			else if ($2 == "integrate") next
			if (mode == "claimable" && live($1)) next
			printf "%d\t%s\t%s\n", r, $7, $0
		}' "$ST/$WD" "$ST/$WQ" 2>/dev/null | sort -t$'\t' -k1,1n -k2,2
}

task_open() { _task_rows open "$1" | cut -f3-; }

# One commit per claim: the task lease, plus a refresh of the worker's own slot lease when that is past a quarter of
# its 360 min (so a claiming worker never needs a separate lease_renew commit for its slot).
task_claim() {
	local worker=$1 app=${2:-} types=${3:-} rows prows n w pick id row tries=0 exp
	st_sync || st_sync || return 2
	[ -f "$ST/$WQ" ] || { _st_flush; return 1; }
	mkdir -p "$ST/leases"; touch "$ST/$WD"
	# Ends only when nothing is claimable (1), never because of push contention: a lost race just tries again.
	while [ "$tries" -lt 150 ]; do
		tries=$((tries + 1))
		rows=$(_task_rows claimable "$types")
		[ -n "$rows" ] || { _st_flush; return 1; }
		# Stay on [app] only while it has a task among the first 64, so workers do not linger on a later app.
		if [ -n "$app" ]; then
			prows=$(printf '%s\n' "$rows" | head -64 | awk -F'\t' -v a="$app" '$5 == a')
			[ -n "$prows" ] && rows=$prows
		fi
		# Pick at random among the first ${CLAIM_WINDOW:-16} in rank order: a window narrower than the number of
		# concurrent claimers makes them collide on the same lease, and a collision costs the whole push wait.
		n=$(printf '%s\n' "$rows" | wc -l)
		w=${CLAIM_WINDOW:-16}
		[ "$n" -gt "$w" ] && n=$w
		pick=$(( (RANDOM % n) + 1 ))
		row=$(printf '%s\n' "$rows" | sed -n "${pick}p" | cut -f3-)
		id=${row%%$'\t'*}
		_lease_free "$(task_lease "$id")" || continue
		if [ -n "$worker" ] && [ "$(_lease_field "$worker" holder)" = "$ST_ME" ]; then
			exp=$(_lease_field "$worker" expires_epoch)
			[ "$(( ${exp:-0} - $(_now) ))" -gt $(( 360 * 45 )) ] || _lease_stage "$worker" 360
		fi
		# lease_acquire syncs on failure, so the next round reads fresh state without another fetch.
		lease_acquire "$(task_lease "$id")" 120 nosync || continue
		# A done row that landed between our read and our lease is in the tree we just pushed.
		if awk -F'\t' -v id="$id" '$1 == id {f = 1} END {exit !f}' "$ST/$WD"; then
			lease_release "$(task_lease "$id")"
			continue
		fi
		printf '%s\n' "$row"
		return 0
	done
	return 2
}

task_renew() { lease_renew "$(task_lease "$1")" 120; }

# One commit: the done row plus the release of the task lease. Under contention it is pushed a few times at most and
# otherwise stays local: the worker's next claim (or its final release) carries it out in the same push, so a busy
# branch sees one write per task instead of two. If the commit conflicts (the lease changed hands), the row goes out
# on its own and the lease is left to its holder.
task_done() {
	local id=$1 result rc i pre lease
	result=$(printf '%s' "${2:-done}" | tr '\t\n\r' '   ' | cut -c1-400)
	lease=$(task_lease "$id")
	if st_sync; then
		pre=$(_g rev-parse HEAD)
		printf '%s\n' "$id"$'\t'"$result"$'\t'"$ST_ME"$'\t'"$(date -u +%FT%TZ)" >> "$ST/$WD"
		_g add "$WD"
		[ "$(_lease_field "$lease" holder)" = "$ST_ME" ] && _g rm -q "leases/$lease.lease"
		ST_PUSH_TRIES=${ST_DONE_TRIES:-3} _st_push "state: task $id done by $ST_ME"; rc=$?
		[ "$rc" = 0 ] || [ "$rc" = 2 ] && return 0
		_st_undo_to "$pre"
	fi
	st_append "$WD" "$id"$'\t'"$result"$'\t'"$ST_ME"$'\t'"$(date -u +%FT%TZ)"; rc=$?
	# A push that lost every race leaves the row committed locally: push that commit again, never append twice.
	for i in 1 2 3 4 5 6; do
		[ "$rc" = 2 ] || break
		_st_jitter 2
		st_sync && git -C "$ST" push -q origin "HEAD:refs/heads/$ST_BRANCH" 2>/dev/null && rc=0
	done
	[ "$rc" = 0 ] || return "$rc"
	lease_release "$lease"
}
