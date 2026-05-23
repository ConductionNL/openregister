# Tracking-Issue Discovery and Mapping

The user maintains daily progress comments on a small set of GitHub issues — typically in an org-level `.github` repository. **Do not hardcode issue numbers** — discover them.

## Discovery: which issues are "tracking" issues?

A tracking issue, in the user's workflow, is one that has these properties:

- The issue is open (or recently closed)
- The user has posted at least one structured progress comment of the form `### DD-MM-YYYY` followed by `**repo - description**` blocks
- The issue's title or body references a coherent stream of work (a repo, a feature area, or a workflow)

Discover them dynamically:

```bash
GH_LOGIN=$(gh api user --jq '.login')

# Issues the user has commented on in the last ~30 days, in any repo
gh search issues --commenter "@me" --state open --updated ">=$(date -d '30 days ago' +%Y-%m-%d)" \
  --limit 50 --json number,title,url,repository,updatedAt \
  --jq 'sort_by(.updatedAt) | reverse'

# For each candidate, check whether the user's most recent comment matches the
# tracking-comment shape (### DD-MM-YYYY heading)
gh api repos/{OWNER}/{REPO}/issues/{N}/comments \
  --jq '[.[] | select(.user.login == "'"$GH_LOGIN"'")] | last | .body' \
  | grep -qE '^### [0-9]{2}-[0-9]{2}-[0-9]{4}'
```

Present the discovered candidates to the user via AskUserQuestion:

**"I found these issues you've been posting daily updates on. Which apply to today's work?"**
- list each issue as `#{N} {title} — last update {updatedAt}`
- include "None of these — let me specify" as an option
- include "Skip tracking-issue updates" as an option

## Saved mapping (optional, per-user)

Users with stable tracking-issue setups may want to short-circuit discovery. The skill checks for a per-user mapping file at:

```
$HOME/.claude/report-out/tracking-issues.json
```

Schema:

```json
{
  "mappings": [
    {"work_area": "skill library", "repo_globs": [".github", "{repo-b}"], "issue": "{org}/.github#{N}"},
    {"work_area": "{work-area-name}", "repo_globs": ["{repo-c}"], "issue": "{org}/.github#{N}"},
    {"work_area": "follow-up", "repo_globs": ["*"], "issue": "{org}/.github#{N}", "is_followup": true}
  ],
  "last_updated": "YYYY-MM-DD"
}
```

When this file exists, present the saved mapping as the default in Step 7. If the user wants to update it, ask whether to write back the changes (with confirmation).

If the file does NOT exist, fall back entirely to dynamic discovery via `gh search`.

## Lifecycle

Tracking issues evolve. Observed pattern:

1. Open issue → daily comments accumulate
2. When the work area is "done", the user posts a closing comment with the trailer:
   _"Deze issue lijkt inhoudelijk klaar. Na een korte check/review door {reviewer} kan hij naar done. Verdere of toekomstige werkzaamheden worden bijgehouden in #{follow-up}"_
3. A follow-up issue is created
4. The mapping (file or in-memory) updates

When the skill detects a closing-comment trailer in a tracked issue's recent comments, it should ask the user whether the follow-up issue is now active, and offer to update the saved mapping.

## Reviewer convention

The reviewer named in the closing comment is workflow-specific. Do not hardcode any reviewer username; let the user provide it as part of `{USER_CONTEXT}` when relevant.

## Verifying issues are still open

Before drafting comments, sanity-check the candidate issues:

```bash
gh issue view "$ISSUE_REF" --json state,title --jq '{state, title}'
```

If state is `CLOSED`, ask the user whether a successor issue exists and update the mapping accordingly.
