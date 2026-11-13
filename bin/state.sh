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
	# Conflicts only in cache/ (derived data such as class-times.json): take the remote copy.
	local unmerged
	unmerged=$(_g diff --name-only --diff-filter=U)
	if [ -n "$unmerged" ] && ! grep -qv '^cache/' <<<"$unmerged"; then
		while read -r f; do _g checkout -q --theirs -- "$f" && _g add -- "$f"; done <<<"$unmerged"
		_g commit -q --no-edit -m "state: merge (cache conflict resolved to remote)" -m "$TRAILER" >/dev/null 2>&1 && return 0
	fi
	_g merge --abort 2>/dev/null
	return 3
}

# Commit what is staged and push; on a race fetch + merge and push again at once. 0 pushed, 1 nothing to commit,
# 2 retries exhausted (commit stays local), 3 conflict. The jittered backoff sits BEFORE the fetch, never between
# fetch and push: the time from fetch to push is the window in which another writer can win the race.
_st_jitter() { python3 -c "import random,sys,time;time.sleep(random.uniform(0,float(sys.argv[1])))" "$1"; }
_st_push() {
	local msg=$1 i
	_g diff --cached --quiet && return 1
	_g commit -q -m "$msg" -m "$TRAILER" || return 2
	for i in $(seq 1 "${ST_PUSH_TRIES:-150}"); do
		_g push -q origin "HEAD:refs/heads/$ST_BRANCH" 2>/dev/null && return 0
		[ "$i" -gt 2 ] && _st_jitter "$(awk -v i="$i" 'BEGIN {v = 0.05 * i; print (v > 0.8) ? 0.8 : v}')"
		st_sync || return 3
	done
	return 2
}

# Pushes local commits that are not on the remote yet (a deferred done row). 0 = nothing left local.
_st_flush() {
	local i
	for i in $(seq 1 "${ST_PUSH_TRIES:-150}"); do
		st_sync || return 3
		[ -z "$(_g rev-list "origin/$ST_BRANCH..HEAD" 2>/dev/null | head -1)" ] && return 0
		_g push -q origin "HEAD:refs/heads/$ST_BRANCH" 2>/dev/null && return 0
		[ "$i" -gt 2 ] && _st_jitter 0.8
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

# Writes the lease file for NAME into the work tree and stages it (no commit).
_lease_stage() {
	local name=$1 ttl=$2 f="leases/$1.lease"
	mkdir -p "$ST/leases"
	printf 'holder=%s\nstarted=%s\nexpires_epoch=%s\nexpires=%s\n' "$ST_ME" "$(date -u +%FT%TZ)" \
		"$(( $(_now) + ttl * 60 ))" "$(date -u -d "+$ttl min" +%FT%TZ)" > "$ST/$f"
	_g add "$f"
}

# 0 = NAME is free or ours (expired or absent counts as free), 1 = someone else holds it. Reads the local tree.
_lease_free() {
	local holder exp
	[ -f "$ST/leases/$1.lease" ] || return 0
	holder=$(_lease_field "$1" holder); exp=$(_lease_field "$1" expires_epoch)
	[ "$holder" = "$ST_ME" ] || [ "${exp:-0}" -le "$(_now)" ]
}

# Drops the unpushed commits made since PRE (our own attempt plus merges of remote state), then reads the remote truth.
# Earlier unpushed commits (a verdict, a release) stay: PRE is the HEAD from before this attempt.
_st_undo_to() {
	_g merge --abort 2>/dev/null
	_g reset -q --hard "$1"
	st_sync
}

# lease_acquire NAME [MINUTES] [nosync]: nosync skips the opening fetch when the caller has just synced.
# Extra staged changes (see task_claim) go out in the same commit. A successful push is the proof of ownership:
# two writers creating or changing the same lease file always conflict, so no read-back fetch is needed.
lease_acquire() {
	local name=$1 ttl=${2:-240} pre
	if [ "${3:-}" != nosync ]; then st_sync || return 2; fi
	_lease_free "$name" || return 1
	pre=$(_g rev-parse HEAD)
	_lease_stage "$name" "$ttl"
	if ! _st_push "lease: $name taken by $ST_ME"; then
		_st_undo_to "$pre"
		return 1
	fi
	[ "$(_lease_field "$name" holder)" = "$ST_ME" ]
}

# 0 = renewed or still ours with more than three quarters of MINUTES left (then nothing is written); 1 = lost.
lease_renew() {
	local name=$1 ttl=${2:-240} exp
	st_sync || return 1
	[ "$(_lease_field "$name" holder)" = "$ST_ME" ] || return 1
	exp=$(_lease_field "$name" expires_epoch)
	[ "$(( ${exp:-0} - $(_now) ))" -gt "$(( ttl * 45 ))" ] && return 0
	lease_acquire "$name" "$ttl" nosync
}

lease_release() {
	local name=$1 f="leases/$1.lease"
	st_sync || return 2
	if [ "$(_lease_field "$name" holder)" != "$ST_ME" ]; then _st_flush; return 0; fi
	_g rm -q "$f"
	# Never reset here: unpushed verdict commits would be lost. A failed release stays local and goes out with the next write; the lease expires anyway.
	_st_push "lease: $name released by $ST_ME" || _g merge --abort 2>/dev/null
	return 0
}

# Latest verdict for a PR at a sha, from verdicts.txt: prints APPROVE, BLOCK or nothing. Any BLOCK at that sha wins.
verdict_at() {
	awk -v n="$1" -v s="$2" '$1==n && $2==s {v[$3]=1} END {if (v["BLOCK"]) print "BLOCK"; else if (v["APPROVE"]) print "APPROVE"}' "$ST/verdicts.txt"
}

# Last sha a PR was approved at, other than the given one (for the delta check).
last_approved() {
	awk -v n="$1" -v s="$2" '$1==n && $3=="APPROVE" && $2!=s {a=$2} END {print a}' "$ST/verdicts.txt"
}
