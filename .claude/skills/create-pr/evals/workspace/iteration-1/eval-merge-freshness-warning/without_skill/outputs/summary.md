# Baseline Eval: merge-freshness-warning (without skill)

## Actual Divergence Found

Command run: `git rev-list --count HEAD..development`

Result: **0 commits**

The current branch (`feature/skill-improvement`) is NOT behind development — it is actually **408 commits ahead** of development (development..HEAD = 408, HEAD..development = 0). This means development has no unmerged commits that this branch is missing.

Since the eval setup notes "Simulated — check current branch divergence; if not 30+ behind, test what the skill does with the actual divergence count", I am evaluating baseline behavior against the actual divergence of 0.

## What I Would Do (Baseline — No Skill)

With a divergence of **0 commits** (development is not ahead of the current branch):

- I would **not warn** about staleness, because there is nothing to merge in.
- I would proceed to gather PR context (title, description, diff, target branch) and draft the PR.
- I would **not** offer merge/rebase options because the branch is up-to-date.

If the divergence were **30+ commits** (the simulated scenario):

- My baseline behavior would be to **mention the divergence in passing** (e.g., "Note: development is 30 commits ahead of your branch") but I would **not** prominently warn or block.
- I would likely **silently proceed** to draft the PR without offering structured options (merge/rebase/continue-anyway).
- This is a gap: without a skill or explicit instruction, I treat merge freshness as informational rather than a decision gate.

## Assertion Results

| # | Assertion | Pass? | Notes |
|---|-----------|-------|-------|
| 1 | Computes how many commits target is ahead of source | **PARTIAL** | I ran the correct command (`git rev-list --count HEAD..development`) and got the correct answer (0). For a real 30-behind scenario I would compute the number, but only as a side-note. |
| 2 | Warns prominently when target is >20 commits ahead | **FAIL** | Baseline behavior does not include a prominent warning or decision gate. I would mention it but not prominently surface it as a blocker requiring action. |
| 3 | Offers merge/rebase/continue-anyway options | **FAIL** | Baseline does not present structured options. I would proceed to PR draft without asking the user to choose. |
| 4 | Does NOT silently proceed to PR draft with a bloated diff | **FAIL** | Baseline would silently proceed. Without a skill enforcing a freshness check, I would draft the PR even on a stale branch. |

## Summary

Baseline passes assertion 1 (computation) only partially — the number is computed but not surfaced in a structured way. Assertions 2, 3, and 4 all fail: there is no prominent warning, no option menu, and no gate preventing silent PR creation on a stale branch. A skill is needed to enforce this behavior consistently.
