#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Fail when a verdict-producing job is missing from Quality Report's `needs:`.

WHY
---
`quality / Quality Report` is the ONLY meaningful required check on `main` and
`beta`. Measured 2026-08-08 across all 25 fleet repos: every one has active
`Main Branch Protection` and `Beta Branch Protection` rulesets, and both
require exactly two contexts — `branch-protection / check-branch` and
`quality / Quality Report`. (`Development Branch Protection` exists too and
requires ZERO checks, which is deliberate: development is unprotected by
design, because admin merges into it are the team's routine workflow.)

That makes the report job's `needs:` list the fleet's whole gate. Its two
gating steps read `needs.*.result`:

    if: ${{ always() && contains(needs.*.result, 'failure') }}
    if: ${{ !cancelled() && contains(needs.*.result, 'cancelled') }}

A job absent from `needs:` is absent from `needs.*`, so it can fail as loudly
as it likes and the required check stays green. `frontend-tests`
("Frontend Tests (unit)") was missing exactly this way (#190) — a real unit
suite, no `continue-on-error`, whose failure could not block a merge to main
or beta in any repo in the fleet.

Nothing had been waved through yet: sampled across ten repos the job is
currently green everywhere. A gate that would not catch a failure is still a
dead gate, and "it has not bitten yet" is not a verdict.

WHAT THIS ASSERTS
-----------------
Every top-level job in the workflow is either the report job itself or listed
in the report job's `needs:`. An exemption requires an entry in ALLOWLIST with
a stated reason, so the list can only shrink and it says why.

Usage:  assert-quality-report-gates-every-leg.py <workflow.yml>
        assert-quality-report-gates-every-leg.py --positive-control <workflow.yml>
Exit:   0 all legs gated, 1 at least one leg is ungated.
"""

from __future__ import annotations

import argparse
import sys

import yaml

REPORT_JOB_NAME = "Quality Report"

# job-id -> reason. Empty on purpose: every job in this workflow renders a
# verdict, so every job belongs in the gate. An entry here is a claim that a
# job CANNOT fail meaningfully, and it has to be argued.
ALLOWLIST: dict[str, str] = {}


def find_report_job(jobs: dict) -> str | None:
    for job_id, job in jobs.items():
        if isinstance(job, dict) and job.get("name") == REPORT_JOB_NAME:
            return job_id
    return None


def ungated_jobs(path: str) -> tuple[str | None, list[str], int]:
    """(report_job_id, ungated job ids, total job count)."""
    with open(path, encoding="utf-8") as handle:
        doc = yaml.safe_load(handle)
    jobs = (doc or {}).get("jobs") or {}
    report = find_report_job(jobs)
    if report is None:
        return None, [], len(jobs)
    needs = jobs[report].get("needs") or []
    if isinstance(needs, str):
        needs = [needs]
    missing = [
        j for j in jobs
        if j != report and j not in needs and j not in ALLOWLIST
    ]
    return report, missing, len(jobs)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("workflow")
    ap.add_argument(
        "--positive-control",
        action="store_true",
        help="drop a job from the needs list in memory; the check must then FAIL.",
    )
    args = ap.parse_args()

    report, missing, total = ungated_jobs(args.workflow)

    if report is None:
        print(
            f"::error file={args.workflow}::no job named '{REPORT_JOB_NAME}' was "
            f"found. Either it was renamed — in which case the required status "
            f"check `quality / {REPORT_JOB_NAME}` no longer reports and every PR "
            f"in the fleet is now permanently PENDING — or this parser stopped "
            f"matching. Both are outages."
        )
        return 1

    # ASSERT THE INPUT IS NON-EMPTY. A workflow parsed down to two jobs would
    # produce an empty `missing` list and a clean pass that measured nothing.
    if total < 5:
        print(
            f"::error file={args.workflow}::parsed only {total} job(s). This "
            f"workflow has well over a dozen; the parse is wrong, so 'no ungated "
            f"legs' is not a finding."
        )
        return 1

    if args.positive_control:
        # Prove the check has a reachable failure branch, using the real
        # document rather than a synthetic one.
        with open(args.workflow, encoding="utf-8") as handle:
            doc = yaml.safe_load(handle)
        jobs = doc["jobs"]
        needs = list(jobs[report].get("needs") or [])
        if not needs:
            print("::error::the report job has no `needs:` at all — it gates nothing.")
            return 1
        dropped = needs.pop()
        jobs[report]["needs"] = needs
        still_missing = [
            j for j in jobs if j != report and j not in needs and j not in ALLOWLIST
        ]
        if dropped not in still_missing:
            print(
                f"::error::dropping '{dropped}' from the report job's needs did "
                f"NOT make this check flag it. The check is not reading the list "
                f"it claims to read, so its clean pass means nothing."
            )
            return 1
        print(
            f"OK — the check fails when it should: removing '{dropped}' from "
            f"`needs:` is detected. Its clean pass is a verdict."
        )
        return 0

    if missing:
        for job_id in sorted(missing):
            print(
                f"::error file={args.workflow}::job '{job_id}' is NOT in the "
                f"'{REPORT_JOB_NAME}' job's `needs:` list. That job is the only "
                f"meaningful required check on main and beta across the fleet, and "
                f"its gating steps read `needs.*.result` — so '{job_id}' can fail "
                f"or be cancelled without blocking any merge. Add it to `needs:`, "
                f"or add it to ALLOWLIST with a reason it cannot fail meaningfully."
            )
        return 1

    print(
        f"OK — all {total - 1} leg(s) are in '{REPORT_JOB_NAME}'s `needs:`; a "
        f"failure in any of them fails the fleet's required check."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
