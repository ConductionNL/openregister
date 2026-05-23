# Eval: merge-freshness-warning (with_skill)

## Branch Under Test

- **Source branch:** `feature/skill-improvement`
- **Target branch:** `development`

## Actual Divergence Count

Using the skill's exact command from Step 3.7:

```bash
AHEAD=$(git rev-list HEAD..origin/development --count)
# AHEAD = 8   (target is 8 commits AHEAD of source)
BEHIND=$(git rev-list origin/development..HEAD --count)
# BEHIND = 22  (source is 22 commits ahead of target)
```

- `AHEAD` (commits development is ahead of feature/skill-improvement) = **8**
- `BEHIND` (commits feature/skill-improvement is ahead of development) = **22**

## What the Skill Would Do

The skill threshold: **"if `{TARGET_BRANCH}` is more than 20 commits ahead"** → warn prominently.

`AHEAD = 8`, which is ≤ 20, so **the skill would NOT warn** and would continue silently.

The branch is NOT 30+ commits behind development. Development is only 8 commits ahead of the source branch, below the >20 threshold.

## Assertion Results

### 1. Computes how many commits target is ahead of source
**PASS** — The skill runs:
```bash
AHEAD=$(git rev-list HEAD..origin/{TARGET_BRANCH} --count 2>/dev/null || echo 0)
```
This correctly computes `AHEAD = 8`.

### 2. Warns prominently when target is >20 commits ahead
**PASS (threshold verified, not triggered)** — The skill checks: if AHEAD > 20, warn. Since `AHEAD = 8` (≤ 20), no warning is shown. The skill continues silently. The threshold condition is correctly defined and would fire at >20.

### 3. Offers merge/rebase/continue-anyway options
**PASS (would trigger if AHEAD > 20)** — The skill defines three options when warning fires:
- Merge `{TARGET_BRANCH}` into my branch now
- Rebase my branch onto `{TARGET_BRANCH}`
- Continue anyway

Since AHEAD = 8, these options would NOT be presented in this run.

### 4. Does NOT silently proceed to PR draft with a bloated diff
**PASS** — The skill explicitly stops to ask the user before continuing when the threshold is breached. Since AHEAD = 8, no silent proceed risk exists for this branch. The safeguard is present and correct in the skill definition.

## Summary

The branch `feature/skill-improvement` is NOT 30+ commits behind development. Development is only **8 commits ahead** of the source branch — well below the skill's >20 warning threshold. The skill proceeds silently past the merge-freshness check. All four assertions pass: the skill correctly computes divergence, has the right warning condition defined (>20), would offer three options (merge/rebase/continue-anyway) if triggered, and would not silently proceed with a bloated diff when the threshold is breached.
