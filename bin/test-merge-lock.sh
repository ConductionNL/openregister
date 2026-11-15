#!/bin/bash
# Concurrency test for the per-app merge lock in bin/work.sh, against a scratch bare repo.
# N claimers take fix and merge tasks of three apps; one extra "runner" takes merge-app0 directly with
# lease_acquire, as the local merge runners do. Passes when no two sessions ever hold an app's merge at once,
# every task is done exactly once and no task or merge lease is left over.
#   [HOLD=seconds] bash bin/test-merge-lock.sh [claimers=8] [tasks=30] [workdir]
set -u
N=${1:-8}; T=${2:-30}
BIN=$(cd "$(dirname "$0")" && pwd)
W=${3:-$(mktemp -d)}
HOLD=${HOLD:-0.5}
mkdir -p "$W" && cd "$W" || exit 2
rm -rf bare seed c* out* log* iv* check runner
git init -q --bare bare
git clone -q bare seed 2>/dev/null
cd seed
git checkout -q -b sig-pipeline/state
mkdir -p bin queue leases && touch leases/.keep
cp "$BIN/state.sh" "$BIN/work.sh" bin/
printf 'queue/work.tsv merge=union\nqueue/work-done.tsv merge=union\n' > .gitattributes
printf '# id\ttype\tapp\tbase\ttarget\tpayload\tcreated\n' > queue/work.tsv
for i in $(seq 1 "$T"); do
	ty=merge; [ $((i % 3)) = 0 ] && ty=fix
	printf '%s:app%d:%03d:x\t%s\tapp%d\tdevelopment\t-\tp\t2026-11-12T00:%02d:00Z\n' "$ty" "$((i % 3))" "$i" "$ty" "$((i % 3))" "$((i % 60))" >> queue/work.tsv
done
printf '# id\tresult\tholder\tfinished\n' > queue/work-done.tsv
git add -A && git -c user.name=t -c user.email=t@t commit -q -m seed && git push -q origin sig-pipeline/state
cd ..
now() { date +%s.%N; }
pids=()
for c in $(seq 1 "$N"); do
	git clone -q --single-branch --branch sig-pipeline/state bare "c$c"
	(
		cd "c$c" && git config user.name t && git config user.email t@t
		export ST=$PWD ST_ME_FILE=$PWD/.id SIG_ROLE=claimer$c
		source bin/state.sh; source bin/work.sh
		wk=$(WORKER_SLOTS=$((N * 2)) worker_slot) || exit
		while :; do
			row=$(task_claim "$wk" "" "fix,merge") || break
			id=${row%%$'\t'*} ty=$(cut -f2 <<<"$row") app=$(cut -f3 <<<"$row")
			t0=$(now)
			# the lock must be ours on the remote right now
			if [ "$ty" = merge ]; then
				git fetch -q origin sig-pipeline/state
				h=$(git show "origin/sig-pipeline/state:leases/merge-$app.lease" 2>/dev/null | sed -n 's/^holder=//p')
				[ "$h" = "$ST_ME" ] || echo "NOTHELD $id $h" >> "../log.c$c"
			fi
			python3 -c "import time;time.sleep($HOLD)"
			t1=$(now)
			[ "$ty" = merge ] && echo "$app $t0 $t1 c$c" >> "../iv.c$c"
			echo "$id" >> "../out.c$c"
			task_done "$id" "ok by c$c" || echo "DONEFAIL $id" >> "../log.c$c"
		done
		lease_release "$wk"
	) &
	pids+=($!)
done
# a local runner: takes the app0 lock directly three times
git clone -q --single-branch --branch sig-pipeline/state bare runner
(
	cd runner && git config user.name t && git config user.email t@t
	export ST=$PWD ST_ME_FILE=$PWD/.id SIG_ROLE=runner
	source bin/state.sh; source bin/work.sh
	k=0
	for i in $(seq 1 200); do
		[ "$k" -ge 3 ] && break
		if lease_acquire merge-app0 240; then
			t0=$(now); python3 -c "import time;time.sleep($HOLD)"; t1=$(now)
			echo "app0 $t0 $t1 runner" >> ../iv.runner; k=$((k + 1))
			lease_release merge-app0
		else
			python3 -c "import time;time.sleep(0.2)"
		fi
	done
) &
pids+=($!)
for p in "${pids[@]}"; do wait "$p"; done
git clone -q --single-branch --branch sig-pipeline/state bare check
expect=$(grep -vc '^#' seed/queue/work.tsv)
claimed=$(cat out.c* 2>/dev/null | wc -l)
dupes=$(cat out.c* | sort | uniq -d | wc -l)
donerows=$(grep -vc '^#' check/queue/work-done.tsv)
leftover=$(ls check/leases | grep -cE '^(task|merge)-')
notheld=$(cat log.c* 2>/dev/null | grep -c NOTHELD)
donefail=$(cat log.c* 2>/dev/null | grep -c DONEFAIL)
overlap=$(cat iv.* | sort -k1,1 -k2,2n | awk '$1 == a && $2 < e {o++} {if ($1 != a || $3 > e) e = $3; a = $1} END {print o + 0}')
runner=$(wc -l < iv.runner 2>/dev/null || echo 0)
echo "claimers=$N tasks=$expect claimed=$claimed duplicates=$dupes done_rows=$donerows leftover_leases=$leftover lock_not_held=$notheld done_failures=$donefail overlapping_merges=$overlap runner_turns=$runner"
[ "$claimed" = "$expect" ] && [ "$dupes" = 0 ] && [ "$donerows" = "$expect" ] && [ "$leftover" = 0 ] && [ "$notheld" = 0 ] \
	&& [ "$donefail" = 0 ] && [ "$overlap" = 0 ] && [ "$runner" = 3 ] && { echo PASS; exit 0; } || { echo FAIL; exit 1; }
