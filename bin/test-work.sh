#!/bin/bash
# Concurrency test for bin/work.sh: N parallel claimers against a scratch bare repo.
# Every task must be claimed exactly once, and every claimed task must end with exactly one done row.
#   [HOLD=seconds] bash bin/test-work.sh [claimers=10] [tasks=40] [workdir]   (HOLD simulates work per task)
set -u
N=${1:-10}; T=${2:-40}
BIN=$(cd "$(dirname "$0")" && pwd)
W=${3:-$(mktemp -d)}
mkdir -p "$W" && cd "$W" || exit 2
rm -rf bare seed c* out* log* check
git init -q --bare bare
git clone -q bare seed 2>/dev/null
cd seed
git checkout -q -b sig-pipeline/state
mkdir -p bin queue leases && touch leases/.keep
cp "$BIN/state.sh" "$BIN/work.sh" bin/
printf 'queue/work.tsv merge=union\nqueue/work-done.tsv merge=union\n' > .gitattributes
printf '# id\ttype\tapp\tbase\ttarget\tpayload\tcreated\n' > queue/work.tsv
for i in $(seq 1 "$T"); do
	ty=cover; [ $((i % 4)) = 0 ] && ty=fix; [ $((i % 7)) = 0 ] && ty=integrate
	printf 't:%03d\t%s\tapp\tdevelopment\t-\tp\t2026-11-12T00:%02d:00Z\n' "$i" "$ty" "$((i % 60))" >> queue/work.tsv
done
printf '# id\tresult\tholder\tfinished\n' > queue/work-done.tsv
git add -A && git -c user.name=t -c user.email=t@t commit -q -m seed && git push -q origin sig-pipeline/state
cd ..
expect=$(grep -vc -e '^#' -e $'\tintegrate\t' seed/queue/work.tsv)
pids=()
for c in $(seq 1 "$N"); do
	git clone -q --single-branch --branch sig-pipeline/state bare "c$c"
	(
		cd "c$c" && git config user.name t && git config user.email t@t
		export ST=$PWD ST_ME_FILE=$PWD/.id SIG_ROLE=claimer$c
		source bin/state.sh; source bin/work.sh
		while :; do
			row=$(task_claim "w$c"); rc=$?
			[ "$rc" = 0 ] || { echo "claim rc=$rc" >> "../log.c$c"; break; }
			id=${row%%$'\t'*}
			echo "$id" >> "../out.c$c"; [ "${HOLD:-0}" = 0 ] || python3 -c "import time;time.sleep(${HOLD:-0})"
			task_done "$id" "ok by c$c"; rc=$?
			[ "$rc" = 0 ] || { echo "DONEFAIL $id" >> "../out.c$c"; echo "done $id rc=$rc" >> "../log.c$c"; }
		done
	) &
	pids+=($!)
done
for p in "${pids[@]}"; do wait "$p"; done
git clone -q --single-branch --branch sig-pipeline/state bare check
claimed=$(cat out.c* 2>/dev/null | grep -vc DONEFAIL)
dupes=$(cat out.c* | grep -v DONEFAIL | sort | uniq -d | wc -l)
donefail=$(cat out.c* | grep -c DONEFAIL)
donerows=$(grep -vc '^#' check/queue/work-done.tsv)
doneuniq=$(grep -v '^#' check/queue/work-done.tsv | cut -f1 | sort -u | wc -l)
integ=$(grep -v '^#' check/queue/work-done.tsv | cut -f1 | while read -r id; do grep -q "^$id"$'\tintegrate' check/queue/work.tsv && echo x; done | wc -l)
leftleases=$(ls check/leases 2>/dev/null | grep -c '^task-')
per=$(for f in out.c*; do printf '%s=%s ' "${f#out.}" "$(grep -vc DONEFAIL "$f")"; done)
echo "claimers=$N tasks_claimable=$expect claimed=$claimed duplicate_claims=$dupes done_rows=$donerows unique_done=$doneuniq done_failures=$donefail integrate_claimed=$integ leftover_task_leases=$leftleases"
echo "per claimer: $per"
[ "$claimed" = "$expect" ] && [ "$dupes" = 0 ] && [ "$donerows" = "$expect" ] && [ "$doneuniq" = "$expect" ] && [ "$integ" = 0 ] && [ "$leftleases" = 0 ] \
	&& { echo PASS; exit 0; } || { echo FAIL; exit 1; }
