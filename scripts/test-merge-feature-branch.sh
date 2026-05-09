#!/usr/bin/env bash
# test-merge-feature-branch.sh
#
# Pull a remote feature branch (typically a PR branch) into the currently
# checked-out branch via `git merge`, then restart Nextcloud and run the
# notify_push self-test so the new code is live in the local Docker
# stack. Lets developers preview a PR locally on top of their own active
# branch ahead of upstream merging to development — the merged code is
# heading there anyway, so this is a "test it now, see it land later"
# workflow rather than a sandbox.
#
# Usage:
#   scripts/test-merge-feature-branch.sh <branch-or-pr-number>
#
# Examples:
#   scripts/test-merge-feature-branch.sh feature/add-live-updates
#   scripts/test-merge-feature-branch.sh 1453            # PR number form
#
# Behaviour:
#   1. Fetch the remote branch (or pull/<N>/head for PR numbers).
#   2. git merge into the current branch.
#   3. docker compose restart nextcloud (picks up new code from the
#      bind-mounted submodule path).
#   4. docker exec ... php occ upgrade (catches schema migrations).
#   5. docker exec ... php occ notify_push:self-test (verifies the chain
#      is healthy if notify_push is installed).
set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 <branch-or-pr-number>" >&2
    exit 1
fi

REF="$1"
REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

CURRENT_BRANCH="$(git branch --show-current || true)"
if [[ -z "$CURRENT_BRANCH" ]]; then
    echo "Not on a branch (detached HEAD). Aborting." >&2
    exit 1
fi

echo "==> Fetching '$REF'"
if [[ "$REF" =~ ^[0-9]+$ ]]; then
    # PR number form
    REMOTE_REF="pull/$REF/head"
    LOCAL_TEMP="test-pr-$REF"
    git fetch origin "$REMOTE_REF:$LOCAL_TEMP"
    MERGE_TARGET="$LOCAL_TEMP"
else
    git fetch origin "$REF"
    MERGE_TARGET="origin/$REF"
fi

echo "==> Merging '$MERGE_TARGET' into '$CURRENT_BRANCH'"
git merge "$MERGE_TARGET" --no-edit

echo "==> Restarting nextcloud container"
docker compose restart nextcloud >/dev/null 2>&1 || docker-compose restart nextcloud

echo "==> Waiting for container to settle"
for i in {1..15}; do
    if docker exec nextcloud php -r 'echo "ok";' 2>/dev/null | grep -q ok; then
        break
    fi
    sleep 1
done

echo "==> Running occ upgrade"
docker exec -u www-data nextcloud php occ upgrade --no-interaction || true

echo "==> Running notify_push self-test (best-effort; skipped if not installed)"
docker exec -u www-data nextcloud php occ notify_push:self-test 2>&1 \
    | grep -E '✓|🗴|×|^Error|^Tests' || echo "(self-test command unavailable or returned no recognisable output)"

cat <<EOF

Done. '$REF' is now merged into '$CURRENT_BRANCH' and live in the
running Nextcloud container. Verify in a browser at http://localhost:8080.
EOF
