# PR Title and Description Update Protocol

When the user has added new commits to an open PR during the day, the title or description may need to be refreshed so reviewers see an accurate summary.

## Critical: never use `gh pr edit`

`gh pr edit` uses GraphQL and fails with:

> ⚠️ Projects (classic) is being deprecated...

Use the GitHub REST API directly:

```bash
gh api repos/{OWNER}/{REPO}/pulls/{N} -X PATCH \
  -f title="..." \
  -f body="$(cat <<'EOF'
...
EOF
)" --jq '.html_url'
```

If you only want to update one of title/body, omit the other field — PATCH only changes what you send.

## Decision flow

1. **Has the title's intent changed?** If the original title still describes the PR accurately, leave it. Only update if new commits introduced a fundamentally different scope.

2. **Are existing description bullets still accurate?** Read the current body; check whether new commits add to existing bullets (extend the language) or warrant a new bullet entirely.

3. **Prefer extending over adding.** The user has explicitly said: "Make sure that the edits you want to do in the body, adding new notes to the list are needed, maybe look for updating existing ones first." Adding a new bullet for what could be a minor extension to an existing one is noise.

4. **Cleanliness over completeness.** "Keep title and description as clean as possible." A 3-bullet description that summarizes 8 commits is better than an 8-bullet description that mirrors them 1:1.

## Anti-patterns to avoid

| Anti-pattern | Why it's wrong | Correction |
|--------------|----------------|------------|
| Vague verbs like "bumped" without context | The user pushed back on "Global-settings scripts bumped" — what was bumped? Why? | Either describe specifically ("global-settings VERSION bumped to 1.6.0 for hook updates") or drop the bullet |
| Adding a bullet for every commit | Description becomes a commit log, not a summary | Group commits by intent, one bullet per intent |
| Updating title to mention every change | Title bloats; loses signal | Keep title focused on the PR's primary purpose |
| Mirroring branch name in title | Title should describe the change, not the branch | Use the branch as a hint, not a copy source |

## Drafting checklist

Before showing a title/body update to the user:

- [ ] Read the current PR title + body (don't assume — fetch them)
- [ ] List the new commits since the last description update
- [ ] For each commit, ask: does this extend an existing bullet, warrant a new one, or change nothing?
- [ ] Default to "extend" — only add new bullets for genuinely new categories of work
- [ ] Show the user a clear before/after diff when proposing the update

## Example: extending vs adding

**Original body (3 bullets):**
```
- Add review-pr early-exit gate for re-reviews
- Update opsx-archive learnings
- Capture session learnings from hydra retrofit
```

**New commits:** 2 commits adding ADR-029 cross-references to existing learnings.

**Wrong approach (adding a 4th bullet):**
```
- Add review-pr early-exit gate for re-reviews
- Update opsx-archive learnings
- Capture session learnings from hydra retrofit
- Add ADR-029 cross-references                       ← new bullet
```

**Right approach (extending bullet 2):**
```
- Add review-pr early-exit gate for re-reviews
- Update opsx-archive learnings, including ADR-029 cross-references   ← extended
- Capture session learnings from hydra retrofit
```

The right approach keeps the description tight and shows reviewers what changed without inflating the bullet count.
