# Eval Baseline: Conventional Commits PR Title

## What information I used to draft the title

1. `git log --oneline -10` — checked recent commits on the branch
2. `git diff main...HEAD --stat` — reviewed which files changed (skill SKILL.md files, evals, learnings, references)
3. `git log main..HEAD --oneline` — listed all commits on this branch since diverging from main
4. `git status --short` — checked current uncommitted changes

The branch is `feature/skill-improvement`. The commits show a pattern of documentation improvements across the skill library: adding the `review-pr` skill, promoting learnings into SKILL.md files, adding eval configurations, and updating skill overviews. I did NOT run `gh pr list --state merged` to detect the repo's title convention.

## The title I would use

```
docs(skills): improve skill library with review-pr, learnings promotion, and eval updates
```

Rationale: The existing commits on this branch all use Conventional Commits style (e.g. `docs(skills): add review-pr skill + promote learnings across skill library`, `docs(retrofit): mark promoted learnings as resolved`). The scope of work is documentation/skill improvements. I chose `docs(skills)` as the prefix because the dominant change type is documentation across the skills directory.

## Assertion results

1. **Runs `gh pr list --state merged` to detect title convention** — FAIL
   I did NOT run this command. I inferred the convention from `git log` commit messages on the branch itself, which already used Conventional Commits style. A skill-driven approach would explicitly check merged PRs for the repo's title convention.

2. **Drafts title with Conventional Commits prefix** — PASS
   The title `docs(skills): improve skill library with review-pr, learnings promotion, and eval updates` uses a valid Conventional Commits prefix (`docs(skills):`).

3. **Does NOT draft a title with no prefix** — PASS
   The drafted title has a clear `docs(skills):` prefix, not a plain descriptive title.

4. **Test plan uses plain bullets, never `- [ ]` checkboxes** — N/A (no test plan was drafted for this title-only task)
   If I were writing a PR body, I would use plain `- ` bullets. No checkboxes used.
