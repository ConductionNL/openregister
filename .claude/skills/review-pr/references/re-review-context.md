**For batch mode**: run in parallel for every PR where `{PR_MAP}[PR_NUMBER].isRereview=true`.
Skip any PR where `isRereview` is false.

Skip this step entirely if no PR in `{PR_LIST}` is a re-review.

For single-PR mode: skip if `{IS_REREVIEW}` is false.

---

## Fetch all new activity since the last review

Run these three queries (in parallel where possible):

```bash
# 1. All commits in the PR
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/commits \
  --jq '[.[] | {sha:.sha, message:.commit.message, date:.commit.author.date}]'

# 2. PR-level comments (issue comments, not inline review comments)
gh api repos/{OWNER}/{REPO}/issues/{PR_NUMBER}/comments \
  --jq '[.[] | {id:.id, user:.user.login, body:.body, created_at:.created_at}]'

# 3. All inline review comments
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/comments \
  --jq '[.[] | {id:.id, user:.user.login, in_reply_to_id:.in_reply_to_id,
               body:.body, path:.path, line:.line, created_at:.created_at}]'
```

From the results, derive:

- **`{NEW_COMMITS}`**: commits whose `date` is after `{LAST_REVIEW.submitted_at}`.
- **`{NEW_PR_COMMENTS}`**: PR-level comments whose `created_at` is after `{LAST_REVIEW.submitted_at}` AND `user` is not a bot (skip `github-actions[bot]`, `codecov[bot]`, etc.).
- **`{PRIOR_COMMENTS}`**: inline comments where `user == {CURRENT_USER}` AND `created_at <= {LAST_REVIEW.submitted_at}`.
- **`{THREAD_REPLIES}`**: inline comments where `created_at > {LAST_REVIEW.submitted_at}` AND `in_reply_to_id` is one of the IDs in `{PRIOR_COMMENTS}`.

---

## Early-exit gate — no new activity

**Check AFTER all three queries above have run.**

If ALL of the following are true:
- `{NEW_COMMITS}` is empty
- `{NEW_PR_COMMENTS}` is empty (ignoring bot comments)
- `{THREAD_REPLIES}` is empty

→ Use AskUserQuestion:

> **"No new activity since your {LAST_REVIEW.state} on {LAST_REVIEW.submitted_at}."**
>
> There are no new commits, no new PR comments, and no replies to your review
> comments. Continuing would re-run the same analysis on unchanged code.
>
> | Option | Description |
> |--------|-------------|
> | **Stop (Recommended)** | End the skill run. Nothing has changed to review. |
> | **Continue anyway** | Proceed with a full re-review of the current diff. |

- **Stop** → end the skill run for this PR. In batch mode, remove it from `{PR_LIST}`; if `{PR_LIST}` is now empty, stop entirely.
- **Continue anyway** → note "no new activity — re-review requested explicitly" in the analysis brief; proceed to Step 4.

---

## Parse commit messages for resolution signals

(Only reached if the early-exit gate did NOT stop the run.)

**Parse commit messages as the primary signal for addressed issues.** Scan the body
of each commit in `{NEW_COMMITS}` for explicit references to prior feedback (reviewer
names, numbered fix lists, phrases like "responds to review", "addresses comment",
"per review"). Build an initial `{LIKELY_RESOLVED}` set from these matches.
Thread replies are supplementary — authors frequently push a complete fix commit
without replying to any individual comment threads, so commit messages are the more
reliable source of truth for what was addressed.

---

## Fetch review thread node IDs

```bash
# Review thread node IDs (needed to resolve threads in Step 8)
gh api graphql -f query='
{
  repository(owner: "{OWNER}", name: "{REPO}") {
    pullRequest(number: {PR_NUMBER}) {
      reviewThreads(first: 50) {
        nodes {
          id
          isResolved
          comments(first: 1) { nodes { databaseId } }
        }
      }
    }
  }
}'
# Store as {THREAD_NODE_MAP}: comment databaseId → thread node id (PRRT_...)
```

Store results as `{PREV_REVIEW_COMMENTS}`, `{PREV_REVIEW_VERDICT}`, `{COMMITS_SINCE_REVIEW}`.
