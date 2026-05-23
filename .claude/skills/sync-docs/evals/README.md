# sync-docs evals

How to run the evals and promote the skill to L5 maturity.

## Files

| File | Purpose |
|------|---------|
| `evals.json` | Eval definitions, assertions, trigger tests, grading rubric |
| `grading.json` | Historical runs — one entry per executed eval per date |
| `README.md` | This file |

## Running an eval

Each eval in `evals.json` has a `setup` field describing the repository state required. Hydra evals assume `{APPS_EXTRA}` is a sibling of this workspace and `{GITHUB_REPO}` resolves to `~/.github` (override per `evals.json` setup notes).

1. Create the setup on a scratch branch (or document that state already exists on the current branch).
2. Invoke the skill with the eval's `prompt` (e.g. `/sync-docs openregister`).
3. Let the skill run to completion.
4. Score each item in `assertions[]` as pass/fail/partial.
5. Compute the weighted score per the `grading_rubric` in `evals.json`.
6. Append a run to `grading.json` (see schema below).

## grading.json run schema

```json
{
  "date": "2026-04-23",
  "claude_version": "claude-opus-4-7",
  "eval_id": "app-docs-openregister-feature-drift",
  "score": 0.87,
  "assertions": [
    {"id": "reads openspec/specs/export/spec.md before editing any doc", "result": "pass"},
    {"id": "flags README [Future] marker as stale (spec status=done)", "result": "pass"},
    {"id": "proposes docs/features/export.md referencing the spec via link, not duplicating content", "result": "partial", "note": "Linked once but duplicated the acceptance criteria inline."},
    {"id": "does NOT link to ADRs from user-facing docs", "result": "pass"}
  ],
  "timing_seconds": 112,
  "tokens_in": 21340,
  "tokens_out": 4120,
  "notes": "One partial — tighten the Reference-Don't-Duplicate guidance in Phase 4."
}
```

## Promoting to L5

The auto-detection script (`.claude/skills/update-skill-overview.sh` in the consuming workspace) promotes a skill to L5 when:

- `evals.json` has 3+ evals ✅
- `trigger_tests.should_trigger` ≥ 10 ✅
- `trigger_tests.should_not_trigger` ≥ 10 ✅
- `last_validated` is non-null ❌ (set after first successful run)

After running at least one eval, update `last_validated` in `evals.json` to today's date.

## Baseline

Each eval has a `baseline` field describing what Claude produces *without* the skill loaded. Record baseline runs in `grading.json` with `eval_id` suffixed `-baseline` when measuring improvement lift.

## Improve cycle

After each eval run, identify the lowest-scoring assertion. Either:

1. Strengthen SKILL.md guardrails / steps to address it, re-run the eval, record the new score. That's one improve cycle.
2. Flag the assertion as too strict and update the eval definition.

Log improve cycles by bumping `evals.json:version` (semver) and referencing the commit that made the fix.
