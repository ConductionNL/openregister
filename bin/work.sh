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
#                               take the oldest claimable task (by type rank, then created), of [app] first when
#                               it has one (reuses that app's clone and vendor), else of any app; prints the row; 1 = none
#   task_renew <id>             renew the task lease (0 = still yours)
#   task_done <id> <result>     record the result and release the task lease
#   task_lease <id>             print the lease name of a task
#   task_open                   print every open task (claimed or not), oldest first
# Type rank: fix, review, verify/merge, gap, cover. Type `integrate` (openregister main, behind the single
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
	awk -F'\t' -v mode="$mode" -v types=",$types," -v now="$(date -u +%s)" -v leases="$ST/leases" '
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
			r = ($2 == "fix") ? 1 : ($2 == "review") ? 2 : ($2 == "verify" || $2 == "merge") ? 3 : ($2 == "gap") ? 4 : ($2 == "cover") ? 5 : 9
			if (types != ",,") { if (index(types, "," $2 ",") == 0) next }
			else if ($2 == "integrate") next
			if (mode == "claimable" && live($1)) next
			printf "%d\t%s\t%s\n", r, $7, $0
		}' "$ST/$WD" "$ST/$WQ" 2>/dev/null | sort -t$'\t' -k1,1n -k2,2
}

task_open() { _task_rows open "$1" | cut -f3-; }

task_claim() {
	local worker=$1 app=${2:-} types=${3:-} rows prows n pick id row tries=0
	st_sync || st_sync || return 2
	[ -f "$ST/$WQ" ] || return 1
	mkdir -p "$ST/leases"; touch "$ST/$WD"
	# Ends only when nothing is claimable (1), never because of push contention: a lost race just tries again.
	while [ "$tries" -lt 150 ]; do
		tries=$((tries + 1))
		rows=$(_task_rows claimable "$types")
		[ -n "$rows" ] || return 1
		if [ -n "$app" ]; then
			prows=$(printf '%s\n' "$rows" | awk -F'\t' -v a="$app" '$5 == a')
			[ -n "$prows" ] && rows=$prows
		fi
		# Pick among the oldest few at random, so 58 claimers do not all race for the same row.
		n=$(printf '%s\n' "$rows" | wc -l)
		[ "$n" -gt 6 ] && n=6
		pick=$(( (RANDOM % n) + 1 ))
		row=$(printf '%s\n' "$rows" | sed -n "${pick}p" | cut -f3-)
		id=${row%%$'\t'*}
		if ! lease_acquire "$(task_lease "$id")" 120; then
			python3 -c "import random,time;time.sleep(random.uniform(0.2,1.5))"
			st_sync
			continue
		fi
		# Re-check after the lease: another worker may have finished it between our read and our lease.
		st_sync
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

task_done() {
	local id=$1 result rc i
	result=$(printf '%s' "${2:-done}" | tr '\t\n\r' '   ' | cut -c1-400)
	st_append "$WD" "$id"$'\t'"$result"$'\t'"$ST_ME"$'\t'"$(date -u +%FT%TZ)"; rc=$?
	# A push that lost every race leaves the row committed locally: push that commit again, never append twice.
	for i in 1 2 3 4 5 6; do
		[ "$rc" = 2 ] || break
		python3 -c "import random,time;time.sleep(random.uniform(0.5,3.0))"
		st_sync && git -C "$ST" push -q origin "HEAD:refs/heads/$ST_BRANCH" 2>/dev/null && rc=0
	done
	[ "$rc" = 0 ] || return "$rc"
	lease_release "$(task_lease "$id")"
}
