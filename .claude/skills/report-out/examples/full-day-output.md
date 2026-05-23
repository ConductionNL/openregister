# Full-Day Example Output

What the skill produces end-to-end for a representative day. Real values are replaced with `{placeholders}` to keep the example portable.

## Step 6 overview (chat output)

```
## Daily activity — DD-MM-YYYY

### {repo-a} (feature/some-branch)
**Commits (3):**
- a1b2c3d fix: address PR review comments
- e4f5g6h docs: update file after merge conflict
- i7j8k9l docs: add cross-reference to ADR

**Uncommitted (0 files)**

**Related PRs:**
- #N {title} — open (re-review)

### {repo-b} (main)
**Commits (5):**
- m1n2o3p feat: early-exit gate for retry flow
- q4r5s6t docs: path learnings
- u7v8w9x docs: session learnings
- y0a1b2c feat: mechanical gate for reachability check
- d3e4f5g chore: update CHANGELOG

**Related PRs:**
- #N {title} — merged ✅
- #N {title} — open, NOT BY USER (filtered out of comments)

### {repo-c} (feature/refactor)
**Commits (8):**
- (...)

**Related PRs:**
- #N coverage report — merged ✅
- #N reverse-spec A — open (needs review)
- #N reverse-spec B — open (needs review)
- #N reverse-spec C — open (needs review)
```

## Step 5 — report-out message (Slack draft, generated at end)

```
Report out:
- PR review comments verwerkt; merge conflict opgelost
- {repo-b}: early-exit gate aan retry flow, ADR cross-references in learnings
- {repo-c}: 3 nieuwe specs gedraft + PR's geopend

PR's:
- https://github.com/{org}/{repo-a}/pull/N: fix review comments + merge conflict 🔵
- https://github.com/{org}/{repo-b}/pull/N: early-exit gate ✅
- https://github.com/{org}/{repo-c}/pull/N: coverage report ✅
- https://github.com/{org}/{repo-c}/pull/N: reverse-spec A 🆕🟡
- https://github.com/{org}/{repo-c}/pull/N: reverse-spec B 🆕🟡
- https://github.com/{org}/{repo-c}/pull/N: reverse-spec C 🆕🟡
```

## Step 7 — issue comment (filed under tracking issue for {repo-a} + {repo-b})

```markdown
### 01-05-2026

**{repo-a} - fix: address PR review comments + merge conflict resolved**

- Alle review comments van {reviewer} verwerkt
- Merge conflict in retrofit.md opgelost en behavioral details hersteld

**{repo-b} - docs: review-pr en opsx learnings**

- Early-exit gate toegevoegd aan re-review flow
- opsx-archive path learnings en sessie learnings vastgelegd
- PR #N gemerged ✅
- ADR cross-references toegevoegd aan learnings

Voor meer details zie de PR's;

https://github.com/{org}/{repo-a}/pull/N (open — wacht op re-review)
https://github.com/{org}/{repo-b}/pull/N (gemerged ✅)
```

## Step 7 — issue comment (filed under tracking issue for {repo-c})

```markdown
### 01-05-2026

- PR #N gemerged ✅
- 3 nieuwe Bucket 2a clusters als aparte PR's aangemaakt

**{repo-c} - retrofit: actions, oas-generation en approval-workflow**

- Spec A gedraft (5 REQs) + 9 methods geannoteerd
- Spec B gedraft (2 REQs) + 2 methods geannoteerd
- Spec C gedraft (5 REQs) + 13 methods geannoteerd
- Coverage report bijgewerkt

Voor meer details zie de PR's;

https://github.com/{org}/{repo-c}/pull/N (gemerged ✅)
https://github.com/{org}/{repo-c}/pull/N (open — needs review)
https://github.com/{org}/{repo-c}/pull/N (open — needs review)
https://github.com/{org}/{repo-c}/pull/N (open — needs review)
```

## Notes from this run

- A PR was related to {repo-b} but was created by a team member, not by `{GH_LOGIN}`. The author filter on `git log` correctly excluded it from the commit list, but it surfaced in `gh pr list --state open`. **The skill must distinguish "PRs touched by the user today" from "PRs in repos the user worked in today"** — only the former belong in the comment and the report-out.
- The first version of the comment had duplicate content between top summary bullets and detail sections. The skill removed duplicates per the rule in `templates/issue-comment.md`.
- The 24h-comment check (Step 7a) detected a comment from earlier today and offered Edit/New/Skip — Edit was chosen and the existing comment was PATCHed rather than a new one being POSTed.
