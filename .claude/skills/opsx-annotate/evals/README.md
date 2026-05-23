# opsx-annotate evals

How to run the evals and promote this skill to L5 maturity.

## Files

| File | Purpose |
|------|---------|
| `evals.json` | 4 eval scenarios + 12/12 trigger tests + weighted grading rubric |
| `grading.json` | Historical runs — one entry per executed eval per date |
| `README.md` | This file |

## Running an eval

Each eval's `setup` field describes the repo state required. Because this skill creates branches, commits, and opens PRs, run against a scratch app clone — NEVER against production.

1. Check out a scratch clone of the target app (the eval's `setup` describes what state to put it in).
2. Create the setup on a throwaway branch.
3. Invoke the skill with the eval's `prompt` (e.g. `/opsx-annotate procest`).
4. Let the skill run to completion OR until it hits a refusal guardrail (several evals test refusals).
5. Score each item in `assertions[]` as pass/fail/partial.
6. Compute the weighted score per the `grading_rubric` in `evals.json`.
7. Append a run to `grading.json` (schema below).

## grading.json run schema

```json
{
  "date": "2026-04-23",
  "claude_version": "claude-opus-4-7",
  "method": "read-only Explore subagent simulation",
  "eval_id": "happy-path-small-app",
  "score": 0.91,
  "assertions": [
    {"id": "checks coverage-report.json exists AND is < 24h old BEFORE any other action", "result": "pass"},
    {"id": "uses AskUserQuestion before any file edits", "result": "pass"},
    {"id": "archives the ghost change via /opsx-archive at the end", "result": "partial", "note": "Skill calls /opsx-archive but does not explicitly verify the move into changes/archive/."}
  ],
  "timing_seconds": 420,
  "tokens_in": 28100,
  "tokens_out": 7800,
  "notes": "Strongest: prereq chain. Weakest: verification after archive step."
}
```

## Promoting to L5

The auto-detection script (`.claude/skills/update-skill-overview.sh`) promotes to L5 when:

- `evals.json` has 3+ evals ✅ (this skill has 4)
- `trigger_tests.should_trigger` ≥ 10 ✅ (this skill has 12)
- `trigger_tests.should_not_trigger` ≥ 10 ✅ (this skill has 12)
- `last_validated` is non-null ❌ (set after first successful run)

After running at least one eval, update `last_validated` in `evals.json` to today's date and record `baseline_score` as the first-run pass rate.

## Safety note — this skill writes

Unlike a typical skill, `/opsx-annotate` creates branches, edits files, commits, pushes, and opens PRs. Running an eval against a real repo will produce real side-effects. For evaluation, prefer:

- Read-only Explore subagent simulation (agent has no Edit/Write tools — physically cannot modify files).
- Scratch clone of the app in a sandbox.
- Dry-run trace where you read the skill step-by-step against the setup and score from reasoning alone.

## Improve cycle

After each run, identify the lowest-scoring assertion. Either strengthen SKILL.md guardrails/steps to address it and re-run (bump `version` in evals.json), or flag the assertion as too strict and update the eval.
