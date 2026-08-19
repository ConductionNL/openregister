#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Fail when one job's result can DELETE another job instead of failing it.

WHY
---
`assert-quality-report-gates-every-leg.py` closes one direction: every job that
renders a verdict must be in the Quality Report's `needs:`, or its failure
cannot block a merge (#190/#229). This closes the OTHER direction, which cost us
2026-08-06 (#194): a job can be removed from the run entirely by an upstream
result, and a removed job does not fail — it goes `skipped`, which renders as a
grey tick and is counted by nothing.

Two shapes produce that, and both are checked here.

SHAPE 1 — the implicit `success()` trap.
    A job with `needs:` and either no `if:` at all, or an `if:` containing no
    status-check function, keeps GitHub's default `success()` over its `needs`.
    Any producer that fails or skips then deletes it silently. The fix is to
    include a status function — conventionally `!cancelled()` in this file —
    which suppresses the default and lets the job reach its own verdict.

SHAPE 2 — an explicit result gate.
    `if: … && needs.<producer>.result != 'failure'` is the same deletion,
    written on purpose. This is exactly what #194 is about: four test jobs
    carried `needs.security.result != 'failure'`, and one advisory published
    against a dev-only code formatter turned PHPUnit, Newman and E2E into
    `skipped` across sixteen repositories at once, with no commit and no red.

    The distinction that matters: blocking a MERGE on a producer is correct and
    is done by putting the producer in the Quality Report's `needs:`. DELETING a
    consumer is never the way to express it, because the deletion destroys the
    consumer's evidence at exactly the moment you want it.

An exemption requires an entry in ALLOWLIST with a stated reason, so the list
can only shrink and it says why.

Usage:  assert-no-producer-deletes-a-verdict.py <workflow.yml>
        assert-no-producer-deletes-a-verdict.py --positive-control <workflow.yml>
Exit:   0 no job can be deleted by a producer, 1 at least one can.
"""

from __future__ import annotations

import argparse
import re
import sys

import yaml

# A status-check function suppresses the implicit `success()` over `needs`.
STATUS_FN_RE = re.compile(r"\b(?:success|failure|cancelled|always)\s*\(")

# `needs.foo.result == 'x'` / `needs['foo'].result != 'x'` in a JOB-level `if:`.
# Step-level conditions are fine and are not inspected: a step that does not run
# leaves its job's verdict intact, whereas a job that does not run has none.
RESULT_GATE_RE = re.compile(
    r"needs(?:\.[A-Za-z0-9_-]+|\[['\"][A-Za-z0-9_-]+['\"]\])\.result\s*(?:!=|==)"
)

# job-id -> reason. Empty on purpose.
ALLOWLIST: dict[str, str] = {}


def check(workflow: dict, injected: dict | None = None) -> list[str]:
    jobs = dict(workflow.get("jobs") or {})
    if injected:
        jobs.update(injected)

    problems: list[str] = []
    for job_id, job in jobs.items():
        if not isinstance(job, dict):
            continue
        if job_id in ALLOWLIST:
            continue
        needs = job.get("needs") or []
        if isinstance(needs, str):
            needs = [needs]
        cond = str(job.get("if", ""))

        if needs and not STATUS_FN_RE.search(cond):
            problems.append(
                f"{job_id}: has `needs: {needs}` and no status-check function in "
                f"`if:` — the implicit success() means ANY producer failing or "
                f"skipping DELETES this job instead of failing it. Add "
                f"`!cancelled()` to the condition."
            )

        m = RESULT_GATE_RE.search(cond)
        if m:
            problems.append(
                f"{job_id}: `if:` gates on `{m.group(0)}…` — a producer's result "
                f"SKIPS this job, and a skipped job renders no verdict and no "
                f"red (#194). Block the merge by listing the producer in the "
                f"Quality Report's `needs:` instead."
            )

    return problems


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("workflow")
    ap.add_argument(
        "--positive-control",
        action="store_true",
        help="Inject the exact #194 shape and assert this check detects it. A "
        "check that cannot fail is worth nothing.",
    )
    args = ap.parse_args(argv)

    with open(args.workflow) as fh:
        workflow = yaml.safe_load(fh)

    if args.positive_control:
        injected = {
            "__probe_result_gate": {
                "needs": ["security"],
                "if": "${{ !cancelled() && needs.security.result != 'failure' }}",
            },
            "__probe_implicit_success": {
                "needs": ["security"],
                "if": "${{ inputs.enable-php }}",
            },
        }
        problems = check(workflow, injected)
        found = {p.split(":")[0] for p in problems}
        if {"__probe_result_gate", "__probe_implicit_success"} <= found:
            print(
                "OK — the check fails when it should: both an explicit "
                "`needs.*.result` gate and an implicit success() trap are "
                "detected. Its clean pass is a verdict."
            )
            return 0
        print(f"FAIL — positive control not detected. Found: {sorted(found)}")
        return 1

    problems = check(workflow)
    if problems:
        print("A producer can DELETE a verdict-producing job:\n")
        for p in problems:
            print(f"  - {p}")
        print(
            "\nA deleted job is `skipped`. `skipped` is not `failure`, so no "
            "tally counts it and it renders in the same colour family as a "
            "pass. See #194."
        )
        return 1

    n = sum(
        1
        for j in (workflow.get("jobs") or {}).values()
        if isinstance(j, dict) and j.get("needs")
    )
    print(
        f"OK — {n} job(s) declare `needs:` and none of them can be deleted by a "
        f"producer's result; each reaches its own verdict."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
