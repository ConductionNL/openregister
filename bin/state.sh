#!/bin/bash
# Shared pipeline state on the sig-pipeline/state branch of ConductionNL/openregister.
# Source it: `source bin/state.sh` from a clone of that branch, or set ST to the clone.
# Writers fetch, change, commit and push. On a rejected push they fetch and MERGE (never rebase, never force-push).
# Append-only files merge with the union driver (.gitattributes); a lease is a plain file, so two sessions
# creating the same lease conflict and the later push loses.
#   st_sync                      fetch and merge the remote state
#   st_append FILE LINE...       append lines, commit, push
#   st_put FILE SRC              replace FILE (owner only: needs-review queue, cache), commit, push
#   lease_acquire NAME MINUTES   0 = this session holds NAME; 1 = someone else does
#   lease_renew NAME MINUTES     0 = renewed; 1 = lost (do not continue the guarded work)
#   lease_release NAME
#   st_log LINE                  append "<utc> <me> LINE" to log.txt
ST=${ST:-$(git rev-parse --show-toplevel 2>/dev/null)}
ST_BRANCH=sig-pipeline/state
TRAILER='Assisted-by: ClaudeCode:claude-opus-5-5'
ST_ME_FILE=${ST_ME_FILE:-$HOME/.sig-pipeline-id}
[ -s "$ST_ME_FILE" ] || echo "${SIG_ROLE:-session}-$(date -u +%Y%m%dT%H%M%S)-$(cut -c1-8 /proc/sys/kernel/random/uuid)" > "$ST_ME_FILE"
ST_ME=$(cat "$ST_ME_FILE")

_g() { git -C "$ST" "$@"; }
_now() { date -u +%s; }

st_sync() {
	_g fetch -q origin "$ST_BRANCH" || return 2
	_g merge -q --no-edit "origin/$ST_BRANCH" >/dev/null 2>&1 && return 0
	_g merge --abort 2>/dev/null
	return 3
}

# Commit what is staged and push; on a race fetch + merge and push again. 0 pushed, 1 nothing to commit, 3 conflict.
_st_push() {
	local msg=$1
	_g diff --cached --quiet && return 1
	_g commit -q -m "$msg" -m "$TRAILER" || return 2
	for _ in 1 2 3 4 5 6; do
		_g push -q origin "HEAD:refs/heads/$ST_BRANCH" 2>/dev/null && return 0
		st_sync || return 3
		python3 -c "import random,time;time.sleep(random.uniform(1,4))"
	done
	return 2
}

st_append() {
	local file=$1; shift
	st_sync || return $?
	mkdir -p "$(dirname "$ST/$file")"
	printf '%s\n' "$@" >> "$ST/$file"
	_g add "$file"
	_st_push "state: append $file"
}

st_put() {
	local file=$1 src=$2
	st_sync || return $?
	mkdir -p "$(dirname "$ST/$file")"
	cp "$src" "$ST/$file"
	_g add "$file"
	_st_push "state: update $file"; local rc=$?
	[ "$rc" = 1 ] && return 0
	return "$rc"
}

st_log() { st_append log.txt "$(date -u +%FT%TZ) $ST_ME $*"; }

_lease_field() { sed -n "s/^$2=//p" "$ST/leases/$1.lease" 2>/dev/null; }

lease_acquire() {
	local name=$1 ttl=${2:-240} f exp holder
	f="leases/$name.lease"
	st_sync || return 2
	if [ -f "$ST/$f" ]; then
		holder=$(_lease_field "$name" holder); exp=$(_lease_field "$name" expires_epoch)
		if [ "$holder" != "$ST_ME" ] && [ "${exp:-0}" -gt "$(_now)" ]; then return 1; fi
	fi
	printf 'holder=%s\nstarted=%s\nexpires_epoch=%s\nexpires=%s\n' "$ST_ME" "$(date -u +%FT%TZ)" \
		"$(( $(_now) + ttl * 60 ))" "$(date -u -d "+$ttl min" +%FT%TZ)" > "$ST/$f"
	_g add "$f"
	if ! _st_push "lease: $name taken by $ST_ME"; then
		# Lost a race or the push failed: drop the local attempt and read the remote truth.
		_g merge --abort 2>/dev/null; _g fetch -q origin "$ST_BRANCH"; _g reset -q --hard "origin/$ST_BRANCH"
	fi
	[ "$(_lease_field "$name" holder)" = "$ST_ME" ] || return 1
	_g fetch -q origin "$ST_BRANCH"
	[ "$(_g show "origin/$ST_BRANCH:$f" 2>/dev/null | sed -n 's/^holder=//p')" = "$ST_ME" ]
}

lease_renew() {
	local name=$1 ttl=${2:-240}
	st_sync || return 1
	[ "$(_lease_field "$name" holder)" = "$ST_ME" ] || return 1
	lease_acquire "$name" "$ttl"
}

lease_release() {
	local name=$1 f="leases/$1.lease"
	st_sync || return 2
	[ "$(_lease_field "$name" holder)" = "$ST_ME" ] || return 0
	_g rm -q "$f"
	_st_push "lease: $name released by $ST_ME" || { _g merge --abort 2>/dev/null; _g fetch -q origin "$ST_BRANCH"; _g reset -q --hard "origin/$ST_BRANCH"; }
}

# Latest verdict for a PR at a sha, from verdicts.txt: prints APPROVE, BLOCK or nothing. Any BLOCK at that sha wins.
verdict_at() {
	awk -v n="$1" -v s="$2" '$1==n && $2==s {v[$3]=1} END {if (v["BLOCK"]) print "BLOCK"; else if (v["APPROVE"]) print "APPROVE"}' "$ST/verdicts.txt"
}

# Last sha a PR was approved at, other than the given one (for the delta check).
last_approved() {
	awk -v n="$1" -v s="$2" '$1==n && $3=="APPROVE" && $2!=s {a=$2} END {print a}' "$ST/verdicts.txt"
}
