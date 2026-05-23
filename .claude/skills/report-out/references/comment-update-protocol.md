# Comment Update Protocol — 24h Window Check

Before posting a new comment on a tracking issue, **always check whether the user has a recent comment on that same issue**. Two new comments per day on the same issue is noise; updating a single comment is cleaner.

## The 24-hour window

```bash
SINCE_24H=$(date -u -d '24 hours ago' +%Y-%m-%dT%H:%M:%SZ)
GH_LOGIN=$(gh api user --jq '.login')

gh api repos/{OWNER}/{REPO}/issues/{N}/comments \
  --jq '[.[] | select(.user.login == "'"$GH_LOGIN"'" and .created_at > "'"$SINCE_24H"'")
       | {id, created_at, updated_at, body}]'
```

If the resulting array is **non-empty**, the user has a recent comment on this issue.

## Decision flow

```
Recent comment exists?
├── No  → draft a new comment, show, ask, post
└── Yes → ask the user whether to:
          ├── Edit the existing comment (recommended) → fetch full body, draft updated
          │   version that integrates today's new work, show diff, ask, PATCH
          ├── Add a new comment anyway → draft new, show, ask, POST
          └── Skip this issue entirely
```

## Editing an existing comment

```bash
# Fetch current body
CURRENT_BODY=$(gh api repos/{OWNER}/{REPO}/issues/comments/{COMMENT_ID} --jq '.body')

# After drafting the updated body, PATCH it
gh api repos/{OWNER}/{REPO}/issues/comments/{COMMENT_ID} -X PATCH \
  -f body="$(cat <<'EOF'
{updated body}
EOF
)" --jq '.html_url'
```

## Drafting an updated body (vs replacing it)

When editing, **integrate** today's new work into the existing comment rather than discarding it:

1. Read the existing body — note the date heading and section structure
2. If the date heading still matches today (`### DD-MM-YYYY`), extend the existing sections:
   - Add new bullets under matching `**repo - description**` headers
   - Or add a new `**repo - description**` block if today's work touches a new repo
   - Add new PR links to the trailing PR list
3. If the existing comment is from yesterday/earlier and was meant to be a one-day post, do NOT mutate it — drafting a new comment is correct in that case
4. Show a clear before/after diff to the user

## What "recent comment" means in edge cases

| Situation | Recent comment? |
|-----------|----------------|
| Comment posted 23 hours ago, today's work is a continuation | Yes — offer to edit |
| Comment posted 2 hours ago at 14:00, now 16:00 with new work | Yes — offer to edit |
| Comment posted yesterday at 20:00 (28 hours ago), now 00:30 next day | No — out of window, draft new |
| Comment posted today at 09:00 with `### {yesterday}` heading | Yes — but draft new (heading mismatch overrides window check) |

When the date heading on the existing comment doesn't match the current `{DISPLAY_DATE}`, prefer a new comment regardless of the 24h window.

## Anti-patterns to avoid

| Anti-pattern | Why wrong | Correct approach |
|--------------|-----------|------------------|
| Always posting a new comment "to be safe" | Issue threads bloat with near-duplicate daily posts | Always check 24h window first |
| Editing yesterday's comment to add today's work | Loses temporal signal — reviewers can't tell what was done when | New comment when the date heading would change |
| Replacing the existing body entirely with today's content | Loses earlier work the user reported in the same window | Integrate by appending sections / extending bullets |
| Editing without showing the diff | User can't catch unintended deletions | Always show before/after before PATCH |

## Cross-reference

For the comment body format itself (heading, sections, status strings), see [../templates/issue-comment.md](../templates/issue-comment.md). For the broader tracking-issue mapping logic, see [tracking-issues.md](tracking-issues.md).
