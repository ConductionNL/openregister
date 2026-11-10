#!/bin/bash
# Cloud integrator for ConductionNL/openregister: one integration tree of real PR heads on fresh main,
# the full PHPUnit Unit suite in shards plus jest/build when the tree touches the frontend, then one GitHub merge
# per PR into main, only while main and every head are exactly what was tested.
# Port of ~/sig-lanes/shared/closeout/cloud-list.sh + ~/sig-lanes/batch/batch.sh + apply.sh.
#
#   integrate.sh prepare          clones (work dir, state), starts dependency install in the background
#   integrate.sh lease            take the integrator lease (exit 1: someone else holds it, stop)
#   integrate.sh select           classify open cloud/* PRs; writes candidates and the needs-review queue
#   integrate.sh build            integration tree on fresh main (resolves use lines / map entries only)
#   integrate.sh test             starts deps check + 4 shards (+ frontend) in the background, returns at once
#   integrate.sh wait [SECS] [JOB] blocks up to SECS (default 170) for the test run; prints DONE or RUNNING
#   integrate.sh verdict          GREEN / AUTOPARK / RED / NORESULT, recorded in the state branch
#   integrate.sh merge            merges each PR of a GREEN tree; exit 5 when main or a head moved
#   integrate.sh finish           releases the lease
# Exit codes: 0 ok, 1 nothing to do / lease held, 4 no result, 5 rebuild needed, 6 stop and report.
set -o pipefail
R=ConductionNL/openregister
URL=https://github.com/$R
W=${SIG_WORK:-$HOME/sigint}
C=$W/or
export ST=$W/state ST_ME_FILE=$W/.id SIG_ROLE=integrator
# Runs from a COPY of the state branch's bin/ (~/sigint/bin), so a state sync never rewrites a running script.
SELF=$(readlink -f "$0")
BIN=$(dirname "$SELF")
LOGS=$W/logs; RUN=$W/run
CAP=${CAP:-50}
LEASE=integrator
TRAILER='Assisted-by: ClaudeCode:claude-opus-5-5'
# Hold rules: identical to ~/sig-lanes/shared/closeout/cloud-list.sh.
RISK='_rbac|_multitenancy|hasPermission|isAdmin|AuditTrail|auditTrail|getOrganisation|activeOrg|PersonalData|anonymi|Rbac|Grant|Permission|Tenan|Purpose|Retention|Consent|Ownership|LockService|Authoriz|CnDataTable'
RISK_PATH='^\+\+\+ b/lib/(.*/)?(Rbac|Audit|Archival|Retention|Party|Organisation|Tenancy|Purpose|Consent|Share|Lock|Gdpr|Avg)'
FRONT_PATHS=(src css package.json package-lock.json webpack.config.js jest.config.js .babelrc 'babel.config.*' tsconfig.json tests/jest.setup.js tests/__mocks__)
mkdir -p "$W" "$LOGS" "$RUN"

say() { echo "$*"; }
need_state() { [ -d "$ST/.git" ] || { say "no state clone; run prepare"; exit 6; }; source "$BIN/state.sh"; }
lsr() { git -C "$C" ls-remote origin "$1" 2>/dev/null | awk 'NR==1{print $1}'; }

# Background job helper: runs "$@" detached, writes $RUN/<name>.rc when it ends.
# Background job: runs `integrate.sh <entry>` detached, writes $RUN/<name>.rc when it ends.
bg() {
	local name=$1 entry=$2
	rm -f "$RUN/$name.rc"
	nohup setsid bash -c 'bash "$1" "$2" > "$3" 2>&1; echo $? > "$4"' _ "$SELF" "$entry" "$LOGS/$name.log" "$RUN/$name.rc" >/dev/null 2>&1 &
	echo $! > "$RUN/$name.pid"
}

composer_deps() {
	cd "$C" || return 9
	local want; want=$(sha256sum composer.lock | cut -d' ' -f1)
	[ -f vendor/.sig-lock ] && [ "$(cat vendor/.sig-lock)" = "$want" ] && return 0
	# The sandbox token is rejected by the GitHub API; plain git through the proxy works, and the root-only
	# post-install script fails after vendor is complete, so success is judged by the installed autoloader.
	env -u GH_TOKEN -u GITHUB_TOKEN COMPOSER_ALLOW_SUPERUSER=1 composer config --global use-github-api false
	# Packages locked without a git source (phpstan) only have an api.github.com zipball, which the proxy refuses:
	# build that zip from git and put it in composer's file cache under the key composer derives from the URL.
	python3 - <<'PY'
import hashlib, json, os, re, subprocess, tempfile
lock = json.load(open('composer.lock'))
for p in lock.get('packages', []) + lock.get('packages-dev', []):
    if p.get('source') or not p.get('dist'):
        continue
    m = re.match(r'https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/([0-9a-f]{40})$', p['dist'].get('url', ''))
    if not m:
        continue
    d = os.path.expanduser('~/.cache/composer/files/' + p['name'])
    out = os.path.join(d, hashlib.sha1(p['dist']['url'].encode()).hexdigest() + '.zip')
    if os.path.exists(out):
        continue
    os.makedirs(d, exist_ok=True)
    t = tempfile.mkdtemp()
    subprocess.run(['git', 'init', '-q', t], check=True)
    subprocess.run(['git', '-C', t, 'fetch', '-q', '--depth', '1', 'https://github.com/' + m.group(1), m.group(2)], check=True)
    subprocess.run(['git', '-C', t, 'archive', '--format=zip', '-o', out, m.group(2)], check=True)
PY
	env -u GH_TOKEN -u GITHUB_TOKEN COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=0 \
		composer install --no-interaction --no-progress --prefer-source
	[ -f vendor/autoload.php ] && [ -x vendor/bin/phpunit ] || return 1
	echo "$want" > vendor/.sig-lock
}

fe_deps() {
	cd "$C" || return 9
	local stamp=node_modules/.batch-npm-ci want
	want=$(sha256sum package-lock.json | cut -d' ' -f1)
	[ -f "$stamp" ] && [ "$(cat "$stamp")" = "$want" ] && return 0
	rm -rf node_modules
	npm ci --ignore-scripts --no-audit --no-fund && npm rebuild --no-audit --no-fund && echo "$want" > "$stamp"
}

phase_prepare() {
	[ -d "$ST/.git" ] || git clone -q --single-branch --branch sig-pipeline/state "$URL" "$ST" || { say "PREPARE-FAIL state branch clone"; exit 6; }
	if [ ! -d "$C/.git" ]; then
		git clone -q --filter=blob:none "$URL" "$C" || { say "PREPARE-FAIL openregister clone"; exit 6; }
	fi
	git -C "$C" config merge.conflictStyle diff3
	for g in "$C" "$ST"; do
		git -C "$g" config user.name >/dev/null || git -C "$g" config user.name "Claude"
		git -C "$g" config user.email >/dev/null || git -C "$g" config user.email "noreply@anthropic.com"
	done
	git -C "$C" fetch -q origin main && git -C "$C" checkout -q -f -B int origin/main
	bg deps composer_deps_entry
	say "PREPARED work=$W composer install started in the background (integrate.sh wait deps)"
}

phase_lease() {
	need_state
	if lease_acquire "$LEASE" 240; then say "LEASE held by $ST_ME"; return 0; fi
	say "LEASE-HELD $(cat "$ST/leases/$LEASE.lease" 2>/dev/null | tr '\n' ' ')"; exit 1
}

# 0 = needs review. Same decision as cloud-list.sh needs_review(), read over git; any unreadable input means review.
needs_review() {
	local n=$1 head=$2 title=$3 fork d
	[ -n "$title" ] || return 0
	case "$title" in "[access]"*) return 0;; esac
	fork=$(git -C "$C" merge-base origin/main "$head" 2>/dev/null) || return 0
	d=$(git -C "$C" diff "$fork" "$head" 2>/dev/null) || return 0
	[ -n "$d" ] || return 0
	grep -qE "$RISK_PATH" <<<"$d" && return 0
	grep -E '^[-+]' <<<"$d" | grep -qE "$RISK"
}

phase_select() {
	need_state; st_sync
	local prs refs q n head ref title v prev
	# Pages by number: the sandbox proxy refuses the repositories/{id} URLs that --paginate follows.
	local page=1 chunk
	prs=""
	while :; do
		chunk=$(gh api "repos/$R/pulls?state=open&base=main&per_page=100&page=$page" \
			-q '.[]|select(.head.ref|startswith("cloud/"))|select(.head.repo.full_name=="'"$R"'")|select(.draft|not)|[.number,.head.sha,.head.ref,.title]|@tsv' 2>&1) \
			|| { say "SELECT-APIERR ${chunk:0:120}"; exit 6; }
		[ "$(gh api "repos/$R/pulls?state=open&base=main&per_page=100&page=$page" -q length 2>/dev/null)" = 0 ] && break
		[ -n "$chunk" ] && prs+="$chunk"$'\n'
		page=$((page + 1)); [ "$page" -gt 10 ] && break
	done
	prs=$(sed '/^$/d' <<<"$prs")
	grep -qE $'^[0-9]+\t[0-9a-f]{40}\t' <<<"$prs" || { [ -z "$prs" ] && { say "SELECT-NONE no open cloud PRs"; : > "$W/cand.tsv"; exit 1; }; say "SELECT-APIERR ${prs:0:120}"; exit 6; }
	refs=$(awk -F'\t' '{printf "pull/%s/head:refs/remotes/pr/%s ", $1, $1}' <<<"$prs")
	git -C "$C" fetch -q origin main $refs || { say "SELECT-FETCHERR"; exit 6; }
	: > "$W/cand.tsv"; q=$W/needs-review.tsv; printf '# pr\thead\tlast_approved\treason\n' > "$q"
	while IFS=$'\t' read -r -u 3 n head ref title; do
		grep -qE "^openregister#$n $head( |$)" "$ST/parked.txt" 2>/dev/null && continue
		v=$(verdict_at "$n" "$head")
		if [ "$v" = BLOCK ]; then say "HOLD-BLOCKED #$n ${head:0:8}"; continue; fi
		if [ "$v" = APPROVE ]; then printf '%s\t%s\t%s\t1\n' "$n" "$head" "$ref" >> "$W/cand.tsv"; continue; fi
		if needs_review "$n" "$head" "$title"; then
			prev=$(last_approved "$n" "$head")
			if [ -n "$prev" ] && bash "$BIN/delta.sh" "$C" "$n" "$prev" "$head" >/dev/null 2>&1; then
				st_append verdicts.txt "$n $head APPROVE clean main merge on top of $prev"
				say "REAPPROVED #$n ${head:0:8} (dev merge only on top of ${prev:0:8})"
				printf '%s\t%s\t%s\t1\n' "$n" "$head" "$ref" >> "$W/cand.tsv"
			else
				printf '%s\t%s\t%s\taccess title, path or words\n' "$n" "$head" "${prev:--}" >> "$q"; say "HOLD #$n ${head:0:8} review"
			fi
		elif ! python3 "$BIN/strictcheck.py" "$C" "$head" >/dev/null 2>&1; then
			printf '%s\t%s\t%s\tcode moved into a strict_types file\n' "$n" "$head" "$(last_approved "$n" "$head")" >> "$q"; say "HOLD #$n ${head:0:8} strict-move"
		else
			printf '%s\t%s\t%s\t0\n' "$n" "$head" "$ref" >> "$W/cand.tsv"
		fi
	done 3< <(sort -n <<<"$prs")
	# PRs that passed a pre-check at their current head go first, so a tree is mostly pre-tested.
	if [ -s "$ST/queue/prechecked.txt" ] && [ -s "$W/cand.tsv" ]; then
		awk 'NR==FNR { if ($3 == "GREEN") ok[$1 " " $2] = 1; next } { print (($1 " " $2) in ok ? 0 : 1) "\t" $0 }' \
			"$ST/queue/prechecked.txt" FS='\t' "$W/cand.tsv" | sort -s -t $'\t' -k1,1n | cut -f2- > "$W/cand.sorted" && mv "$W/cand.sorted" "$W/cand.tsv"
	fi
	if ! diff -q <(grep -v '^#' "$q") <(grep -v '^#' "$ST/queue/needs-review.tsv" 2>/dev/null) >/dev/null; then
		st_put queue/needs-review.tsv "$q" >/dev/null
	fi
	say "SELECTED $(wc -l < "$W/cand.tsv") candidates, $(grep -vc '^#' "$q") need review (queue/needs-review.tsv)"
	[ -s "$W/cand.tsv" ] || exit 1
}

# Merge main into a PR head whose conflicts are only use lines / map entries, and push it (fast-forward).
resolve_on_head() {
	local n=$1 head=$2 ref=$3 review=$4 wt=$W/res files new
	git -C "$C" worktree remove -f "$wt" >/dev/null 2>&1; rm -rf "$wt"
	git -C "$C" worktree add -q -f --detach "$wt" "$head" || return 1
	(
		cd "$wt" || exit 1
		git -c merge.conflictStyle=diff3 merge -q --no-ff --no-commit origin/main >/dev/null 2>&1 && { git merge --abort; exit 1; }
		files=$(git diff --name-only --diff-filter=U)
		[ -n "$files" ] || { git merge --abort; exit 1; }
		# shellcheck disable=SC2086
		python3 "$BIN/resolve_uses.py" $files || { git merge --abort; exit 1; }
		for f in $files; do case "$f" in *.php) php -l "$f" >/dev/null || { git merge --abort; exit 1; };; esac; done
		git add -- $files
		[ -z "$(git diff --name-only --diff-filter=U)" ] || { git merge --abort; exit 1; }
		git commit -q -m "Merge branch 'main' into $ref" -m "Conflicts resolved in use lines and map entries only: $(echo $files | tr ' ' ',')" -m "$TRAILER" || exit 1
		git push -q origin "HEAD:refs/heads/$ref" || exit 1
		echo "$files" > "$RUN/resolved-$n.txt"
	) >&2 || { git -C "$C" worktree remove -f "$wt" >/dev/null 2>&1; return 1; }
	new=$(git -C "$wt" rev-parse HEAD)
	git -C "$C" worktree remove -f "$wt" >/dev/null 2>&1
	if [ "$review" = 1 ]; then
		st_append verdicts.txt "$n $new APPROVE integrator: main merge on top of $head, conflicts only in use lines or map entries of $(tr '\n' ',' < "$RUN/resolved-$n.txt")"
	fi
	st_log "RESOLVED #$n ${head:0:8} -> ${new:0:8} use/map lines only"
	echo "$new"
}

phase_build() {
	need_state
	[ -s "$W/cand.tsv" ] || { say "BUILD-NONE run select first"; exit 1; }
	cd "$C" || exit 6
	rm -f "$RUN/verdict" "$W/tree.env"
	git merge --abort 2>/dev/null; git fetch -q origin main
	git checkout -q -f -B int origin/main && git clean -fdq
	local d0 n head ref review now new t bad f inc=0
	d0=$(git rev-parse HEAD)
	: > "$W/tree.tsv"; : > "$W/included.txt"
	while IFS=$'\t' read -r -u 3 n head ref review; do
		[ "$inc" -ge "$CAP" ] && break
		now=$(lsr "refs/pull/$n/head")
		[ "$now" = "$head" ] || { say "BUILD-MOVED #$n listed=${head:0:8} head=${now:0:8}"; continue; }
		if ! git merge-tree --write-tree "$d0" "$head" >/dev/null 2>&1; then
			if new=$(resolve_on_head "$n" "$head" "$ref" "$review"); then
				say "BUILD-RESOLVED #$n ${head:0:8} -> ${new:0:8}"; head=$new
				git fetch -q origin "pull/$n/head" 2>/dev/null
			else
				say "BUILD-CONFLICT-DEV #$n (conflict with main outside use lines / map entries)"; st_log "CONFLICT-DEV #$n ${head:0:8}"; continue
			fi
		fi
		if git merge -q --no-ff --no-edit -m "int: merge #$n" "$head" >/dev/null 2>&1; then
			bad=""
			while IFS= read -r f; do
				php -l "$f" >/dev/null 2>&1 </dev/null || bad="$bad $f"
			done < <(git diff --name-only --diff-filter=AM HEAD^1 HEAD -- '*.php')
			if [ -n "$bad" ]; then
				git reset -q --hard HEAD^1
				say "BUILD-LINT #$n ${head:0:8} does not compile on this tree:$bad"; st_log "BUILD-LINT #$n ${head:0:8} does not compile on this tree:$bad"
				continue
			fi
			t=$(git rev-parse HEAD)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$n" "$head" "$ref" "$review" "$t" "$(git rev-parse "$t^{tree}")" >> "$W/tree.tsv"
			echo "openregister#$n $head" >> "$W/included.txt"; inc=$((inc + 1))
		else
			git merge --abort; say "BUILD-CONFLICT-TREE #$n (conflicts with another PR in this tree; retried next tree)"
		fi
	done 3< "$W/cand.tsv"
	[ -s "$W/tree.tsv" ] || { say "BUILD-EMPTY"; exit 1; }
	local sig
	sig="dev=${d0:0:10} prs=$(cut -f1 "$W/tree.tsv" | paste -sd,) "
	if grep -qF "$sig" "$ST/queue/red.txt" 2>/dev/null; then
		say "RED-UNCHANGED this exact tree ($sig) was already red without a single culprit; stop for the coordinator"; exit 6
	fi
	printf 'D0=%s\nT=%s\nN=%s\n' "$d0" "$(git rev-parse HEAD)" "$inc" > "$W/tree.env"
	[ "${NO_LEASE:-0}" = 1 ] || lease_renew "$LEASE" 240 || { say "LEASE-LOST"; exit 6; }
	say "BUILT tree $(git rev-parse --short HEAD) on main ${d0:0:10}: $inc PRs"
}

suite() {
	cd "$C" || exit 9
	source "$W/tree.env"
	local shards i pids=() front=skip
	composer_deps > "$LOGS/deps.log" 2>&1 || { echo "deps failed" > "$RUN/suite.err"; return 1; }
	mkdir -p .phpunit.cache; cp "$ST/cache/class-times.json" .phpunit.cache/class-times.json 2>/dev/null
	shards=$(nproc); [ "$shards" -gt 4 ] && shards=4; [ "$shards" -lt 1 ] && shards=1
	echo "$shards" > "$RUN/shards"
	python3 "$BIN/batch_tools.py" shards "$C" "$shards" > "$LOGS/shards.txt" || { echo "shards not written" > "$RUN/suite.err"; return 1; }
	if ! git diff --quiet "$D0" HEAD -- "${FRONT_PATHS[@]}"; then
		front=red
		( fe_deps > "$LOGS/front.log" 2>&1 || exit 1
		  if ! npx jest --silent --testTimeout=30000 --maxWorkers=2 >> "$LOGS/front.log" 2>&1; then
			sed -E 's/\x1b\[[0-9;]*m//g' "$LOGS/front.log" | grep -q 'Test suite failed to run' || exit 1
			sed -E 's/\x1b\[[0-9;]*m//g' "$LOGS/front.log" | grep -qE '^Tests: .*[0-9]+ failed' && exit 1
			# Only suites that failed to load (the intermittent "Unexpected token 'export'" parse error
			# on vue-material-design-icons under parallel workers): rerun just those files in band.
			mv "$LOGS/front.log" "$LOGS/front-try1.log"
			mapfile -t failed < <(sed -E 's/\x1b\[[0-9;]*m//g' "$LOGS/front-try1.log" | sed -nE 's/^FAIL +([^ ]+).*/\1/p' | sort -u)
			[ ${#failed[@]} -gt 0 ] || exit 1
			npx jest --silent --testTimeout=30000 --runInBand "${failed[@]}" > "$LOGS/front.log" 2>&1 || exit 1
		  fi
		  npm run build >> "$LOGS/front.log" 2>&1 ) &
		local fpid=$!
	fi
	for i in $(seq 1 "$shards"); do
		timeout 3000 php -d pcov.enabled=0 -d memory_limit=3G vendor/bin/phpunit -c ".batch-shard$i.xml" --no-coverage > "$LOGS/php-$i.log" 2>&1 &
		pids+=($!)
	done
	wait "${pids[@]}"
	if [ "$front" = red ]; then wait "$fpid" && front=ok; fi
	echo "$front" > "$RUN/front"
}

phase_test() {
	need_state
	[ -s "$W/tree.env" ] || { say "TEST-NOTREE"; exit 6; }
	rm -f "$RUN"/suite.* "$RUN/front" "$RUN/verdict" "$LOGS"/php-*.log "$LOGS"/front*.log
	bg suite suite_entry
	say "TEST-STARTED tree $(sed -n 's/^T=//p' "$W/tree.env" | cut -c1-10) (integrate.sh wait)"
}

phase_wait() {
	local job=${2:-suite} secs=${1:-170} t0
	t0=$(date +%s)
	while [ ! -f "$RUN/$job.rc" ]; do
		[ $(( $(date +%s) - t0 )) -ge "$secs" ] && { say "RUNNING $job $(ls "$LOGS"/php-*.log 2>/dev/null | wc -l) shard logs, $(grep -l '^Tests: \|^OK (' "$LOGS"/php-*.log 2>/dev/null | wc -l) finished"; exit 3; }
		python3 -c "import time;time.sleep(15)"
	done
	say "DONE $job rc=$(cat "$RUN/$job.rc")"
}

phase_verdict() {
	need_state
	source "$W/tree.env"
	local T10=${T:0:10} line f e front logs=() hit pr sha test risky
	[ -f "$RUN/suite.rc" ] || { say "VERDICT-NOTDONE"; exit 6; }
	[ -f "$RUN/suite.err" ] && { say "NORESULT tree $T10: $(cat "$RUN/suite.err")"; st_log "NORESULT tree $T10: $(cat "$RUN/suite.err")"; echo NORESULT > "$RUN/verdict"; exit 4; }
	for i in $(seq 1 "$(cat "$RUN/shards")"); do logs+=("$LOGS/php-$i.log"); done
	line=$(python3 "$BIN/batch_tools.py" aggregate "${logs[@]}") || { say "NORESULT tree $T10 prs $N: $line"; st_log "NORESULT tree $T10 prs $N: $line"; echo NORESULT > "$RUN/verdict"; exit 4; }
	f=$(grep -oE 'Failures: [0-9]+' <<<"$line" | grep -oE '[0-9]+'); e=$(grep -oE 'Errors: [0-9]+' <<<"$line" | grep -oE '[0-9]+')
	front=$(cat "$RUN/front" 2>/dev/null || echo red)
	# Failures that match config/inherited.re also fail on plain main in this sandbox (no DNS, missing extensions),
	# so they say nothing about the tree: when they are the only PHP failures, the PHP side counts as green.
	if [ "${f:-0}" != 0 ] || [ "${e:-0}" != 0 ]; then
		[ -z "$(python3 "$BIN/batch_tools.py" failures "${logs[@]}" | grep -vE "$(cat "$ST/config/inherited.re")" | sort -u)" ] && { f=0; e=0; }
	fi
	risky=$(cat "${logs[@]}" | awk '/risky test/{r=1} /^(There w|FAILURES|ERRORS|Tests:)/{if(!/risky/)r=0} r&&/^[0-9]+\) /' | grep -vE "$(cat "$ST/config/inherited.re")" | sed -E 's/^[0-9]+\) //' | sort -u)
	if [ -n "$risky" ]; then
		comm -13 <(sort -u "$ST/risky-debt.txt" 2>/dev/null) <(echo "$risky") > "$RUN/risky.new"
		[ -s "$RUN/risky.new" ] && mapfile -t rl < "$RUN/risky.new" && st_append risky-debt.txt "${rl[@]}"
	fi
	if [ "${f:-0}" = 0 ] && [ "${e:-0}" = 0 ] && [ "$front" != red ]; then
		echo GREEN > "$RUN/verdict"
		python3 "$BIN/batch_tools.py" times "$C" "$RUN/class-times.json" >/dev/null && [ -s "$RUN/class-times.json" ] && st_put cache/class-times.json "$RUN/class-times.json"
		st_log "GREEN tree $T10 on dev ${D0:0:10} prs $N front=$front: $line"
		say "GREEN tree $T10 prs $N front=$front: $line"; return 0
	fi
	local fargs=(); [ "$front" = red ] && fargs=(--front-log "$LOGS/front.log")
	[ "$front" = red ] && [ ! -f "$LOGS/front.log" ] && fargs=()
	hit=$(python3 "$BIN/batch_tools.py" implicate "${fargs[@]}" "$C" "$W/included.txt" "${logs[@]}")
	if [ -n "$hit" ]; then
		read -r pr sha test <<<"$hit"
		st_append parked.txt "$(echo "$pr $sha auto-park: $test" | cut -c1-300)"
		st_log "AUTOPARK $pr ${sha:0:8} $test (tree $T10)"
		echo AUTOPARK > "$RUN/verdict"; say "AUTOPARK $pr $test"; exit 5
	fi
	echo RED > "$RUN/verdict"
	python3 "$BIN/batch_tools.py" failures "${logs[@]}" | grep -vE "$(cat "$ST/config/inherited.re")" | sort -u | head -12 | cut -c1-110 > "$RUN/failures.txt"
	[ "$front" = red ] && sed -E 's/\x1b\[[0-9;]*m//g' "$LOGS/front.log" 2>/dev/null | grep -E '^\s*FAIL ' | sort -u | head -12 | cut -c1-110 >> "$RUN/failures.txt"
	st_append queue/red.txt "$(date -u +%FT%TZ) dev=${D0:0:10} prs=$(cut -f1 "$W/tree.tsv" | paste -sd,) $line front=$front"
	st_log "RED tree $T10 prs $N front=$front: ${line:0:90}; first failures: $(head -3 "$RUN/failures.txt" | paste -sd';')"
	say "RED tree $T10 prs $N front=$front: $line"; sed 's/^/FAIL /' "$RUN/failures.txt"; exit 6
}

merged_now() { gh api "repos/$R/pulls/$1" -q '.merged' 2>/dev/null; }

# Lands the whole tested tree with one fast-forward push of T onto main. T is the chain of
# "int: merge #n" commits that was tested, so main becomes exactly the tested tree and GitHub
# marks every PR whose head is now reachable as merged. Returns 1 (caller falls back to
# per-PR merges) when the push is refused; exits 5 when anything moved since the build.
push_merge() {
	local n head ref review t tree out pending=() lines=() i
	[ "$(lsr refs/heads/main)" = "$D0" ] || { say "DEV-MOVED before push; rebuild"; st_log "DEV-MOVED before push; rebuild"; exit 5; }
	while IFS=$'\t' read -r n head ref review t tree; do
		[ "$(lsr "refs/pull/$n/head")" = "$head" ] || { say "HEAD-MOVED #$n; rebuild"; st_log "HEAD-MOVED #$n before push; rebuild"; exit 5; }
		grep -qE "^openregister#$n $head( |$)" "$ST/parked.txt" && { say "PARKED-MEANWHILE #$n; rebuild"; exit 5; }
		if [ "$review" = 1 ] && [ "$(verdict_at "$n" "$head")" != APPROVE ]; then say "NO-APPROVAL #$n at ${head:0:8}; stop"; st_log "STOP #$n lost its approval at ${head:0:8}"; exit 6; fi
	done < "$W/tree.tsv"
	[ "$(git -C "$C" rev-parse "$T^{tree}")" = "$(tail -1 "$W/tree.tsv" | cut -f6)" ] || { say "ALERT-PRECHECK T is not the last tested tree; stop"; exit 6; }
	if ! out=$(timeout 120 git -C "$C" push origin "$T:refs/heads/main" 2>&1); then
		say "PUSH-REFUSED ${out:0:200}; falling back to per-PR merges"; st_log "PUSH-REFUSED ${out:0:160}; per-PR merges"; return 1
	fi
	git -C "$C" fetch -q origin main
	[ "$(git -C "$C" rev-parse origin/main)" = "$T" ] || { say "ALERT-POSTCHECK main is not T after push; stop"; st_log "ALERT-POSTCHECK main != T after push"; exit 6; }
	mapfile -t pending < <(cut -f1 "$W/tree.tsv")
	for i in 1 2 3 4 5 6; do
		local left=()
		for n in "${pending[@]}"; do [ "$(merged_now "$n")" = true ] || left+=("$n"); done
		pending=("${left[@]}"); [ ${#pending[@]} -eq 0 ] && break
		python3 -c "import time;time.sleep(10)"
	done
	while IFS=$'\t' read -r n head ref review t tree; do lines+=("$n $head $t $tree $(date -u +%FT%TZ) push"); done < "$W/tree.tsv"
	st_append merged.txt "${lines[@]}"
	[ ${#pending[@]} -eq 0 ] || st_log "PUSH-UNMARKED main has the commits but GitHub has not marked merged: ${pending[*]}"
	st_log "MERGED-TREE ${T:0:10}: ${#lines[@]} PRs in one push, main now ${T:0:10} = tested tree"
	rm -f "$RUN/verdict"
	say "MERGED-TREE ${#lines[@]} PRs by push; main ${T:0:10} equals the tested tree"
}

phase_merge() {
	need_state
	[ "$(cat "$RUN/verdict" 2>/dev/null)" = GREEN ] || { say "MERGE-REFUSED tree is not GREEN"; exit 6; }
	source "$W/tree.env"
	lease_renew "$LEASE" 120 || { say "LEASE-LOST no merge"; exit 6; }
	st_sync
	[ "${PUSH_MERGE:-1}" = 1 ] && push_merge && return 0
	local expected=$D0 n head ref review t tree dev now got m mc p1 p2 msg count=0
	while IFS=$'\t' read -r -u 3 n head ref review t tree; do
		dev=$(lsr refs/heads/main)
		[ "$dev" = "$expected" ] || { say "DEV-MOVED main=${dev:0:10} expected=${expected:0:10}; rebuild"; st_log "DEV-MOVED before #$n after $count merges; rebuild"; exit 5; }
		now=$(lsr "refs/pull/$n/head")
		[ "$now" = "$head" ] || { say "HEAD-MOVED #$n; rebuild"; st_log "HEAD-MOVED #$n before merge, after $count merges; rebuild"; exit 5; }
		grep -qE "^openregister#$n $head( |$)" "$ST/parked.txt" && { say "PARKED-MEANWHILE #$n; rebuild"; exit 5; }
		if [ "$review" = 1 ] && [ "$(verdict_at "$n" "$head")" != APPROVE ]; then say "NO-APPROVAL #$n at ${head:0:8}; stop"; st_log "STOP #$n lost its approval at ${head:0:8}"; exit 6; fi
		got=$(git -C "$C" merge-tree --write-tree "$expected" "$head" 2>/dev/null | head -1)
		[ "$got" = "$tree" ] || { say "ALERT-PRECHECK #$n merge would give tree ${got:0:10}, tested ${tree:0:10}; stop"; st_log "ALERT-PRECHECK #$n tree mismatch, nothing merged for it"; exit 6; }
		m=$(merged_now "$n")
		for _ in 1 2 3; do
			[ "$m" = true ] && break
			msg=$(timeout 120 gh pr merge "$n" -R "$R" --admin --merge --match-head-commit "$head" 2>&1)
			m=$(merged_now "$n"); [ "$m" = true ] && break
			msg=$(gh api -X PUT "repos/$R/pulls/$n/merge" -f merge_method=merge -f sha="$head" 2>&1)
			m=$(merged_now "$n"); [ "$m" = true ] && break
			python3 -c "import time;time.sleep(20)"
		done
		[ "$m" = true ] || { say "MERGE-FAILED #$n: ${msg:0:160}"; st_log "MERGE-FAILED #$n: ${msg:0:160}"; exit 6; }
		mc=$(gh api "repos/$R/pulls/$n" -q .merge_commit_sha)
		git -C "$C" fetch -q origin main
		p1=$(git -C "$C" rev-parse "$mc^1" 2>/dev/null); p2=$(git -C "$C" rev-parse "$mc^2" 2>/dev/null)
		if [ "$p1" != "$expected" ] || [ "$p2" != "$head" ] || [ "$(git -C "$C" rev-parse "$mc^{tree}")" != "$tree" ]; then
			say "ALERT-POSTCHECK #$n merge commit ${mc:0:10} is not the tested tree; stop"; st_log "ALERT-POSTCHECK #$n merged as ${mc:0:10}, parents or tree differ from the tested tree; stopped"
			st_append merged.txt "$n $head $mc UNVERIFIED $(date -u +%FT%TZ)"; exit 6
		fi
		st_append merged.txt "$n $head $mc $tree $(date -u +%FT%TZ)"
		expected=$mc; count=$((count + 1)); say "MERGED #$n ${head:0:8} as ${mc:0:10}"
	done 3< "$W/tree.tsv"
	st_log "MERGED-TREE ${T:0:10}: $count PRs, main now ${expected:0:10} = tested tree"
	rm -f "$RUN/verdict"
	say "MERGED-TREE $count PRs; main ${expected:0:10} equals the tested tree"
}

phase_finish() { need_state; lease_release "$LEASE"; say "LEASE released"; }

case "${1:-}" in
	prepare) phase_prepare ;;
	lease) phase_lease ;;
	select) phase_select ;;
	build) phase_build ;;
	test) phase_test ;;
	wait) shift; phase_wait "${1:-170}" "${2:-suite}" ;;
	verdict) phase_verdict ;;
	merge) phase_merge ;;
	finish) phase_finish ;;
	composer_deps_entry) composer_deps ;;
	suite_entry) need_state; suite ;;
	*) sed -n '2,20p' "$0"; exit 2 ;;
esac
