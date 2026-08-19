#!/usr/bin/env bash
#
# Count the JOBS a workflow materialised for a given head SHA.
#
# WHY THIS COUNTS JOBS AND NOT CONCLUSIONS
# ----------------------------------------
# When a reusable workflow cannot be resolved, GitHub does not fail the run.
# It produces a run with NO JOBS IN IT, or produces no run at all. Neither
# shape is a red check — there is simply nothing to report — so every caller
# in the fleet silently stops being checked while every dashboard stays clean.
# That is what happened on 2026-08-04: a single `run:` step of ~22 KB made
# .github/workflows/quality.yml unresolvable (#161), the fleet's gates
# evaporated, and it took a revert (#166) and a re-land (#168) to recover.
#
# The outage signature is therefore `jobs == 0`, NOT `conclusion == failure`.
# This script reports the count and says nothing about whether the jobs passed.
#
# Usage:
#   count-workflow-jobs.sh <workflow-file-name> <head-sha> [timeout-seconds]
#
# Prints the job count on stdout. Always exits 0 — the CALLER decides what a
# count means. A script that both measures and judges cannot be positive-
# controlled against a case that is expected to measure zero.

set -euo pipefail

WORKFLOW="${1:?usage: count-workflow-jobs.sh <workflow-file-name> <head-sha> [timeout-seconds]}"
HEAD_SHA="${2:?missing head sha}"
TIMEOUT="${3:-300}"

: "${GH_TOKEN:?GH_TOKEN must be set (needs actions:read)}"
REPO="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY must be set}"

deadline=$(( $(date +%s) + TIMEOUT ))

# Poll rather than read once. The probe and the workflow it measures are
# started by the SAME event, so the run under test may not exist yet at the
# moment this script first looks. Reading once would make a scheduling race
# indistinguishable from the outage, and the race is far more common — which
# is the exact way a probe teaches its readers to ignore it.
while :; do
    runs_json="$(gh api \
        "repos/${REPO}/actions/workflows/${WORKFLOW}/runs?head_sha=${HEAD_SHA}&per_page=100" \
        2>/dev/null || echo '{"workflow_runs":[]}')"

    run_id="$(printf '%s' "${runs_json}" \
        | jq -r '.workflow_runs | sort_by(.created_at) | reverse | .[0].id // empty')"

    if [ -n "${run_id}" ]; then
        # A run exists. Its jobs may still be materialising, so keep polling
        # until at least one appears or the deadline passes: "not yet" and
        # "never" look identical for the first few seconds.
        count="$(gh api --paginate \
            "repos/${REPO}/actions/runs/${run_id}/jobs?per_page=100" \
            --jq '.jobs[].id' 2>/dev/null | wc -l | tr -d ' ')"

        if [ "${count}" -gt 0 ]; then
            echo "${count}"
            exit 0
        fi
    fi

    if [ "$(date +%s)" -ge "${deadline}" ]; then
        # Either no run was ever created for this workflow at this SHA, or one
        # was created and never grew a single job. Both are the outage.
        echo "0"
        exit 0
    fi

    sleep 10
done
