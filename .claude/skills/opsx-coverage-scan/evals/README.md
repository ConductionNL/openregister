# opsx-coverage-scan evals

How to run the evals and promote this skill to L5 maturity.

## Files

| File | Purpose |
|------|---------|
| `evals.json` | 4 eval scenarios + 12/12 trigger tests + weighted grading rubric |
| `grading.json` | Historical runs — one entry per executed eval per date |
| `README.md` | This file |

## Running an eval

This skill is **read-only** (writes only `openspec/coverage-report.md` + `coverage-report.json`), so running against a real app is much safer than `/opsx-annotate`. Still, reports are generated in the target app's workspace — use a scratch branch if you want to avoid committing them.

1. Check out the target app per the eval's `setup` field.
2. Invoke the skill with the eval's `prompt` (e.g. `/opsx-coverage-scan pipelinq`).
3. Let it run to completion OR until it hits a guardrail (several evals test refusals).
4. Score each `assertions[]` entry as pass/fail/partial.
5. Compute the weighted score per the rubric.
6. Append a run to `grading.json`.

## grading.json run schema

```json
{
  "date": "2026-04-23",
  "claude_version": "claude-opus-4-7",
  "method": "read-only Explore subagent simulation",
  "eval_id": "happy-path-small-app",
  "score": 0.93,
  "assertions": [
    {"id": "reports which branch the app is on (Step 1)", "result": "pass"},
    {"id": "reverse pass splits unimplemented REQs into 3a (git log -S match) vs 3b", "result": "partial", "note": "Step 6 specifies the split but the SKILL.md wording 'don't over-invest' risks under-investigation."}
  ],
  "timing_seconds": 900,
  "tokens_in": 42000,
  "tokens_out": 15200,
  "notes": "Scans are expensive — real runs take 30+ min on medium apps (cf. openregister learnings entry: ~107 min on 703 code units)."
}
```

## Promoting to L5

The auto-detection script promotes to L5 when:

- 3+ evals ✅
- 10+/10+ trigger tests ✅ (12/12 here)
- `last_validated` non-null ❌ (set after first successful run)

After running at least one eval, update `last_validated` in `evals.json` and record `baseline_score`.

## Baseline

Each eval has a `baseline` field describing what Claude produces *without* the skill loaded. Record baseline runs in `grading.json` with `eval_id` suffixed `-baseline` when measuring improvement lift.

## Improve cycle

After each run, identify the lowest-scoring assertion. The coverage-scan `learnings.md` already captures several classifier weaknesses from real runs against procest and openregister — treat these as a prior for which assertions are likely to fail.
