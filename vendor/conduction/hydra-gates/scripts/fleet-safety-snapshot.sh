#!/usr/bin/env bash
# Fleet safety snapshot — protects against orchestrator `git reset --hard` wipes.
#
# Creates a daily tag `safe/yyyy-mm-dd` on each fleet repo's working branch
# (development, falling back to main/master) and pushes it to origin. If an
# orchestration burst later force-pushes over recent commits, the previous
# day's tag is the recovery point.
#
# Intended cron entry (run nightly at 02:30):
#   30 2 * * * /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/.github/scripts/fleet-safety-snapshot.sh >> ~/.fleet-safety-snapshot.log 2>&1
#
# Idempotent: re-running on the same day is a no-op (tag already exists).
# Non-destructive: only creates tags, never modifies refs or local branches.

set -u

ROOT="/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra"
DATE=$(date +%Y-%m-%d)
TAG="safe/${DATE}"

REPOS=(
    procest shillinq pipelinq hydra openregister docudesk decidesk
    openconnector larpingapp softwarecatalog doriath nextcloud-vue
    concurrentie-analyse openbuild opencatalogi nldesign launchpad
    zaakafhandelapp openklant opentalk openzaak valtimo n8n-nextcloud
)

ok=0; skip=0; fail=0
echo "=== fleet-safety-snapshot ${DATE} ==="

for repo in "${REPOS[@]}"; do
    dir="${ROOT}/${repo}"
    [ -e "${dir}/.git" ] || { echo "SKIP ${repo}: not a git repo"; skip=$((skip+1)); continue; }
    git -C "${dir}" rev-parse --is-inside-work-tree >/dev/null 2>&1 || { echo "SKIP ${repo}: git rev-parse failed"; skip=$((skip+1)); continue; }

    # Pick the working branch (development or main as fallback).
    branch=""
    for candidate in development main master; do
        if git -C "${dir}" ls-remote --heads origin "${candidate}" 2>/dev/null | grep -q "${candidate}"; then
            branch="${candidate}"
            break
        fi
    done
    [ -n "${branch}" ] || { echo "FAIL ${repo}: no development/main/master branch on remote"; fail=$((fail+1)); continue; }

    if ! git -C "${dir}" fetch origin "${branch}" --quiet 2>/dev/null; then
        echo "FAIL ${repo}: fetch origin ${branch} failed"
        fail=$((fail+1))
        continue
    fi

    # Already-tagged on remote? No-op.
    if git -C "${dir}" ls-remote --tags origin "refs/tags/${TAG}" 2>/dev/null | grep -q "${TAG}"; then
        echo "SKIP ${repo}: ${TAG} already on remote"
        skip=$((skip+1))
        continue
    fi

    sha=$(git -C "${dir}" rev-parse "origin/${branch}" 2>/dev/null)
    [ -n "${sha}" ] || { echo "FAIL ${repo}: cannot resolve origin/${branch}"; fail=$((fail+1)); continue; }

    if ! git -C "${dir}" tag -a "${TAG}" "${sha}" -m "Safety snapshot ${DATE} of origin/${branch}" 2>/dev/null; then
        # Tag may already exist locally from a prior partial run — just try to push.
        :
    fi

    if git -C "${dir}" push origin "refs/tags/${TAG}" --quiet 2>/dev/null; then
        echo "OK   ${repo}: ${TAG} -> ${sha:0:8}"
        ok=$((ok+1))
    else
        echo "FAIL ${repo}: push tag ${TAG} failed"
        fail=$((fail+1))
    fi
done

echo "=== done: ok=${ok} skip=${skip} fail=${fail} ==="
exit 0
