# Learning Candidates — opsx-coverage-scan

Unverified observations awaiting promotion to [learnings.md](learnings.md).

## Promotion flow

```
Capture Learnings step -> learning-candidates.md -> (promotion criteria met?) -> learnings.md -> SKILL.md rules
                                                    ↓ no
                                                 discard after 30 days
```

## Promotion criteria (any one is sufficient)

1. **Confirmation** — the observation has been independently confirmed in 3+ executions of the skill.
2. **Eval resolution** — the observation explains a measured failure in an eval (see `evals/grading.json`) AND the fix has been verified.
3. **User endorsement** — the user has explicitly asked the skill to always apply the observation.

Promotion = move the entry from this file into the corresponding section of `learnings.md` and append `(promoted YYYY-MM-DD)` to the line.

## Discard rule

Entries older than 30 days without promotion should be removed during consolidation. If an observation keeps recurring without meeting promotion criteria, that itself is a signal — re-open it as an Open Question in `learnings.md` instead.

## Candidates

Format: `- YYYY-MM-DD — observation (section: patterns|mistakes|domain|open-question) [seen: N executions]`

<!-- Append candidates below. Keep the block in reverse chronological order. -->

- 2026-04-30 — NEEDS-REVIEW on immutable-resource specs: when a capability's spec says "MUST NOT be deletable" (audit-trail-immutable) and the scanner finds a `destroy` or `destroyMultiple` method in the owning controller, NEEDS-REVIEW is the right call. Key nuance confirmed 2026-04-30: the NEEDS-REVIEW was technically a false positive on `destroy()` itself (it correctly returns 405), but it triggered whole-controller review which found the REAL violation in sibling method `destroyMultiple()` (which actually deleted). The value of NEEDS-REVIEW here is that it prompts review of the entire controller, not just the flagged method. Pattern: if capability spec contains "MUST NOT" + matching action verb (delete, modify, update), force NEEDS-REVIEW on the whole controller's mutating methods. (section: patterns) [seen: 1 execution]
