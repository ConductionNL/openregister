# GitHub Issue Comment Template (Dutch)

Format established across many sessions. Daily-update reviewers expect this structure.

## Skeleton

```markdown
### {DD-MM-YYYY}

- {High-level summary bullet 1}
- {High-level summary bullet 2}

**{repo} - {commit/work description}**

- {Detail bullet}
- {Detail bullet}

**{another-repo} - {description}**

- {Detail bullet}

Voor meer details zie de PR's;

https://github.com/{owner}/{repo}/pull/{N} ({status})
https://github.com/{owner}/{repo}/pull/{N} ({status})
```

## Status strings (Dutch)

| Status | String |
|--------|--------|
| Open, awaiting first review | `(open — needs review)` |
| Open, comments addressed, awaiting re-review | `(open — wacht op re-review)` |
| Merged | `(gemerged ✅)` |
| Closed without merging | `(gesloten)` |

## Closing-out comment

When a tracking issue's work area is "done", append this trailing block:

```markdown
---

Deze issue lijkt inhoudelijk klaar. Na een korte check/review door {reviewer} kan hij naar done. Verdere of toekomstige werkzaamheden worden bijgehouden in {follow-up-issue-link}
```

`{reviewer}` and `{follow-up-issue-link}` are not hardcoded — supply them via `{USER_CONTEXT}` or by reading the saved tracking-issue mapping (see `references/tracking-issues.md`).

## Filled example

```markdown
### 01-05-2026

- PR #1364 gemerged ✅
- 3 nieuwe Bucket 2a clusters als aparte PR's aangemaakt

**openregister - retrofit: actions, oas-generation en approval-workflow**

- Actions spec gedraft (5 REQs) + 9 methods geannoteerd
- OAS-generation spec gedraft (2 REQs) + 2 methods geannoteerd
- Approval-workflow spec gedraft (5 REQs) + 13 methods geannoteerd
- Coverage report bijgewerkt

Voor meer details zie de PR's;

https://github.com/{org}/openregister/pull/1364 (gemerged ✅)
https://github.com/{org}/openregister/pull/1394 (open — needs review)
https://github.com/{org}/openregister/pull/1397 (open — needs review)
https://github.com/{org}/openregister/pull/1400 (open — needs review)
```

## Critical rules

- **No duplication between top summary bullets and detail-section bullets.** Pick one or the other for each piece of information. The user pushed back explicitly on duplicates.
- **`**bold**` repo headers** with format `**{repo} - {short description}**` — exactly one space-dash-space.
- **One blank line** between heading, summary bullets, each repo section, and the trailing PR list.
- **No emoji in headings.** Status emoji only on PR-link lines (`✅` for merged).
- **PR URLs only** in the trailer — never bare PR numbers, never branch URLs.
- **Only include PRs the GitHub user authored or merged.** PRs by other team members in the same repo do not belong here, even if related.

## Posting via gh CLI

```bash
gh issue comment {N} --repo {OWNER}/{REPO} --body "$(cat <<'EOF'
### {DD-MM-YYYY}

{...comment body...}
EOF
)"
```

The `<<'EOF'` (with quotes) is important — disables shell expansion so backticks, `$`, and `!` in the body are preserved verbatim.

## Editing an existing comment (24h-window flow)

When a recent comment exists (see `references/comment-update-protocol.md`):

```bash
# Look up the comment ID from the user's recent activity
gh api repos/{OWNER}/{REPO}/issues/{N}/comments \
  --jq '[.[] | select(.user.login == "'"$GH_LOGIN"'")] | last | {id, created_at}'

# PATCH it
gh api repos/{OWNER}/{REPO}/issues/comments/{COMMENT_ID} -X PATCH -f body="$(cat <<'EOF'
{...new body...}
EOF
)" --jq '.html_url'
```
