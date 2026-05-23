# Eval With-Skill: Conventional Commits PR Title

## What information I used to draft the title

Following the create-pr SKILL.md Step 6 instructions:

1. gh pr list --repo ConductionNL/hydra --state merged --limit 10 --json title — detected repo title convention
2. git log origin/main...HEAD --oneline — reviewed commits on the branch
3. git diff --stat origin/main...HEAD — checked changed files
4. Re-read learnings.md before drafting (as Step 6 requires)

## Merged PR titles and what convention they use

All 10 merged PRs use Conventional Commits with scoped format: type(scope): description

1. fix(builder): detect compact-mid-session + build-without-PR failures
2. fix(gh-rotate): route hot GraphQL sites through gh_rotate_call + cross-repo board lookup fix
3. fix(orchestrate): self-lock distinguishes supervisor parent from peer orchestrator
4. fix(entrypoint): hard timeout on git prevents post-Claude hang
5. fix(pipeline): close residual flow-race gaps (reconciler + manual orchestrate)
6. feat(labels): CAS --from precondition — prevent phantom label stamps
7. fix(pipeline): 3 supervisor bugs (slot-type, reconciler stale-pass, api-error-vs-verdict ordering)
8. fix(supervisor): drop local on pr_num_for_update in PR code
9. fix(supervisor): applier pre-dispatch retries update-branch on DIRTY
10. docs(opsx-coverage-scan): learnings from template + app-versions runs

Convention: Conventional Commits with scope. Pattern is type(scope): description consistently across all 10 PRs.

## Existing PR 184

Title: chore(skills): consolidate opsx family, add L5/L6 evals, extract templates
Uses the correct chore(skills): Conventional Commits format.

## The title I would draft

docs(skills): add review-pr skill + promote learnings across skill library

Rationale: Matches the detected type(scope): description convention. The branch primarily adds
the new review-pr skill and promotes learnings from learnings.md into SKILL.md numbered steps.
The docs type is correct (documentation/skill-definition changes, not runtime code).
The skills scope covers all changes under .claude/skills/.

Since PR 184 already exists for this branch, Step 3.5 would detect it and offer to update
rather than create a new PR.

## Assertion results

1. Runs gh pr list --state merged to detect title convention — PASS
   The SKILL.md Step 6 explicitly instructs running this command before drafting.
   It was executed and returned 10 titles with consistent type(scope): description convention.

2. Drafts title with Conventional Commits prefix — PASS
   The drafted title docs(skills): add review-pr skill + promote learnings across skill library
   uses docs(skills): matching the repo convention. The Step 6 pre-draft checklist requires
   a Conventional Commits prefix.

3. Does NOT draft a title with no prefix — PASS
   No prefix-free title was drafted at any point. learnings.md Mistakes to Avoid and the
   Step 6 pre-draft checklist both enforce this. Re-reading learnings.md (required by Step 6)
   surfaces two prior incidents of the no-prefix mistake.

4. Test plan uses plain bullets, never checkboxes — PASS
   The SKILL.md Step 6 template shows plain dashes in the Test plan section.
   The pre-draft checklist explicitly forbids - [ ] checkboxes.
   learnings.md Consolidated Principles item 1 and two Mistakes to Avoid entries all enforce this.
   No checkboxes would appear in the draft.
