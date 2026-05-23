# Merged PR Context — Fetch Protocol and Analysis Instructions

This file contains the full protocol for Step 5b of the `review-pr` skill — fetching
discussion context from merged PRs and cross-referencing findings against it.

---

## Step 5b: Fetch Merged PR Context

Scan `{PR_COMMITS}` for "Merge pull request" entries and collect discussion context
from those PRs so the analysis agent can identify pre-discussed issues.

**Extract merged PR numbers**: scan every commit message in `{PR_COMMITS}` for the pattern
`Merge pull request #(\d+)` (case-insensitive). Collect all unique PR numbers as
`{MERGED_PR_NUMBERS}`.

If `{MERGED_PR_NUMBERS}` is empty, skip the rest of this step and set
`{MERGED_PR_CONTEXT}` to an empty list.

For each number in `{MERGED_PR_NUMBERS}`, fetch:

```bash
# PR metadata + body
gh api repos/{OWNER}/{REPO}/pulls/{MERGED_PR_NUMBER} \
  --jq '{number:.number, title:.title, body:.body, state:.state}'

# PR-level (issue) comments
gh api repos/{OWNER}/{REPO}/issues/{MERGED_PR_NUMBER}/comments \
  --jq '[.[] | {user:.user.login, body:.body, created_at:.created_at}]'

# Inline review comments
gh api repos/{OWNER}/{REPO}/pulls/{MERGED_PR_NUMBER}/comments \
  --jq '[.[] | {user:.user.login, body:.body, path:.path, line:.line, created_at:.created_at}]'
```

**Build context file**: write all fetched data to `/tmp/pr-{PR_NUMBER}-merged-context.md`
in this format (one section per merged PR):

```markdown
## Merged PR #{MERGED_PR_NUMBER} — {title}

### Description
{body or "(no description)"}

### Discussion ({N} comments)
- @{user} ({created_at}): {body}
- ...

### Review Comments ({N} inline comments)
- @{user} on {path}:{line}: {body}
- ...
```

Truncate individual comment bodies to 500 characters to keep the file manageable.
Store the path as `{MERGED_CONTEXT_FILE}`. If the file would exceed 50KB (many large
merged PRs), truncate the oldest comments first and note the truncation.

Set `{HAS_MERGED_CONTEXT}=true` if any PRs were fetched, otherwise `false`.

---

## Cross-reference instructions for Step 6 analysis agent

When `{HAS_MERGED_CONTEXT}` is true, include these instructions in the analysis agent brief:

> After identifying all findings, cross-reference each finding against the merged PR
> context. For each finding, check whether the same issue (same file/area, same root
> cause) was raised in a merged PR's description or comments. A match requires:
> - Same general concern (not just same file) — e.g. "null check missing on find()"
>   matches a comment saying "find() can return null here"
> - Approximate location overlap is sufficient; exact line match is not required
>
> For each matched finding, annotate it with:
> `already_discussed_in: [{pr_number, matched_text_excerpt (≤80 chars)}]`
>
> For unmatched findings, set `already_discussed_in: []`.
