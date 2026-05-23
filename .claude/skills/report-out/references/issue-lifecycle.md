# Issue Lifecycle Suggestions

Heuristics for surfacing GitHub issue actions: status changes (close, label) and follow-up issue creation. Used in Steps 5.6, 7c, 7d, 7e.

## Tags applied to flagged issues

A flagged issue may carry one or more tags:

| Tag | Meaning | Trigger condition |
|-----|---------|-------------------|
| `close-suggested` | Work appears done | All PRs referenced in the last 7 days of comments are merged AND user posted a comment today |
| `closing-trailer-detected` | User explicitly indicated the issue is done | Most recent user comment within last 24h contains `/Deze issue lijkt inhoudelijk klaar/` |
| `stale-and-busy` | Long-running, comment-heavy, still open | `state == open` AND `(now - created_at).days > 5` AND `comments > 10` AND no closing-trailer in last 7 days |
| `needs-followup` | Stronger version | `stale-and-busy` for `>14` days, OR explicitly flagged in `{USER_CONTEXT}` |

Multiple tags can apply to the same issue — surface the most actionable suggestion first (close > follow-up > status hint).

## Detecting "all linked PRs merged"

Scan the last 7 days of comments for PR URLs and check their state:

```bash
gh api "repos/$OWNER/$REPO/issues/$N/comments?per_page=100" \
  --jq '[.[] | select(.created_at > "'"$(date -u -d '7 days ago' +%Y-%m-%dT%H:%M:%SZ)"'") | .body]' \
  | grep -oE 'https://github\.com/[^/]+/[^/]+/pull/[0-9]+' \
  | sort -u
```

For each unique PR URL, check its state:

```bash
gh pr view "$PR_URL" --json state,merged --jq '{state, merged}'
```

If every linked PR has `state == MERGED` (or `state == CLOSED && merged == true`), the issue is a `close-suggested` candidate.

## Detecting the closing-trailer

The user posts this Dutch trailing block when an issue is "done pending review":

```
---

Deze issue lijkt inhoudelijk klaar. Na een korte check/review door {reviewer} kan hij naar done. Verdere of toekomstige werkzaamheden worden bijgehouden in #{follow-up}
```

Detect with: `grep -qE '^Deze issue lijkt inhoudelijk klaar'`. If found in a comment created within the last 24h, tag `closing-trailer-detected`.

## Stale-and-busy threshold

```python
import datetime
created = datetime.datetime.fromisoformat(issue["created_at"].replace("Z", "+00:00"))
age_days = (datetime.datetime.now(datetime.timezone.utc) - created).days

is_stale_and_busy = (
    issue["state"] == "open"
    and age_days > 5
    and issue["comments"] > 10
    and not closing_trailer_in_last_7_days
)
```

Why these specific thresholds:

- **5 days**: an issue younger than 5 days is still in its "active discussion" phase. Splitting too early creates fragmentation.
- **10 comments**: below this, the issue is still scannable — readers can absorb the full thread. Above it, context starts to scatter.

The thresholds are tunable — log dismissals to `learnings.md` and adjust if the user consistently rejects suggestions at the boundary.

## Status-change action: closing an issue

```bash
# Close as completed (work done successfully)
gh api repos/$OWNER/$REPO/issues/$N -X PATCH \
  -f state=closed -f state_reason=completed \
  --jq '.html_url'

# Close as not-planned (work won't be done — abandoned, deferred, won't fix)
gh api repos/$OWNER/$REPO/issues/$N -X PATCH \
  -f state=closed -f state_reason=not_planned \
  --jq '.html_url'
```

Always show the proposed action in chat before executing. Surface the difference between `completed` and `not_planned` clearly:

> "Close as **completed** (the work in this issue is done) or **not-planned** (we're not going to do this — moved scope, deprioritized)?"

## Status-change action: adding a label

When neither close-state fits but the issue should change state in some way (e.g. `needs-review`, `blocked`, `done-pending-review`):

```bash
gh api repos/$OWNER/$REPO/issues/$N/labels -X POST \
  -f labels[]="$LABEL" \
  --jq '.[].name'
```

To remove a label:

```bash
gh api "repos/$OWNER/$REPO/issues/$N/labels/$LABEL" -X DELETE
```

Existing repo labels can be enumerated with:

```bash
gh api repos/$OWNER/$REPO/labels --jq '[.[] | {name, description}]'
```

## Follow-up issue creation

When the user approves a follow-up suggestion, draft the new issue body:

```markdown
## Volgt op #{PARENT_N}: {parent title}

### Wat is er tot nu toe gedaan
{1–3 bullets summarizing visible progress on the parent issue}

### Wat staat er nog open
{the work that motivates this follow-up — bullets}

### Context
{any relevant USER_CONTEXT notes, plus a one-line "ouder dan {age} dagen, {N_comments} comments" rationale}

---

Aanleiding: deze issue is gemaakt omdat #{PARENT_N} ouder dan {threshold} dagen is met {N_comments} reacties. Om te voorkomen dat we te veel context op één issue stapelen, gaat het vervolgwerk hier verder.
```

Title: by default `Vervolg op #{PARENT_N}: {short topic}` — let the user override.

After creation, **also offer to post a closing-trailer comment on the parent** that points to the new follow-up:

```markdown
---

Deze issue gaat verder in #{NEW_N}. Verdere werkzaamheden worden daar bijgehouden.
```

## Standalone new-issue creation (Step 7e)

When the user has a fresh insight that doesn't belong on any existing tracking issue, draft a standalone issue:

1. Ask for: target repo, working title, 2–4 bullets of context.
2. Draft body in Dutch using a minimal template:

   ```markdown
   ## Achtergrond
   {1–2 sentences explaining why this came up — link to the conversation, PR, or work that surfaced it}

   ## Wat moet er gebeuren
   {1–4 bullets describing the actual work}

   ## Acceptatiecriteria
   {optional — bullets describing "done"}
   ```

3. Show in chat. Ask "Create?". If yes:

   ```bash
   gh api repos/$OWNER/$REPO/issues -X POST \
     -f title="$TITLE" -f body="$BODY" \
     --jq '{number, html_url}'
   ```

## Anti-patterns

| Anti-pattern | Why wrong |
|--------------|-----------|
| Auto-closing an issue without confirmation | Even when the heuristic is confident, the user may have reasons not to close |
| Creating a follow-up issue without a parent reference | Loses traceability — readers can't find the context |
| Treating the 5-day threshold as a hard rule | It's a heuristic; respect when the user dismisses |
| Suggesting follow-ups for issues with `closing-trailer-detected` | The user has already signaled intent — don't double-suggest |
| Creating a new issue when the work fits an existing tracking issue | Always check if the new insight could be a comment on an existing issue first |
| Re-suggesting a dismissed action in the same run | If the user said "skip", don't ask again later in the run |
