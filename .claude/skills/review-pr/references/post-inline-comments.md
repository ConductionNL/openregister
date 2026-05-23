**For batch mode**: iterate over each selected PR in sequence. Run the full Step 8 + Step 9
loop for PR #1, then PR #2, etc. Do not post to all PRs simultaneously — sequential posting
makes it easy to catch a rejected payload (e.g. 422 on a bad line number) and handle it
before moving on.

**New findings**: post as a single COMMENT review payload (same as before):

```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/reviews \
  --input /tmp/pr-{PR_NUMBER}-review.json \
  --jq '{id: .id, state: .state}'
```

JSON payload format per finding — see [comment-format.md](comment-format.md)
for body format. Use `{STRICTNESS_MODE}` to shape body wording:
- **Quick/Standard**: concise body, one paragraph max
- **Thorough/Strict**: full body with impact + suggested fix

**For pre-discussed findings** (`already_discussed_in` is non-empty): append a
blockquote at the end of the comment body before posting:

```markdown
> ⚠️ This concern was raised in merged PR #{N}: "{matched_text_excerpt}"
```

One line per matched PR if multiple. This surfaces the prior context directly in the
inline comment so the author sees it without needing to look up the history.

**Line number rules:**
- Only comment on lines in the diff (context or added lines)
- Verify: `grep -n "^@@" /tmp/pr-{PR_NUMBER}-diff.txt`
- If a target line is not in the diff, move to nearest hunk context line with a note

**For re-review — reply to resolved threads:**

```bash
gh api repos/{OWNER}/{REPO}/pulls/comments/{PRIOR_COMMENT_ID}/replies \
  -X POST \
  -f body="✅ Resolved in {COMMIT_SHA[:7]}: {one-sentence explanation}"
```

Do this for each comment in `{RESOLVED}`. Do NOT reply to `{STILL_OPEN}` ones.

Verify comments landed and capture html_urls for use in the Step 9 verdict body:
```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/comments \
  --jq '[.[] | {id:.id, path:.path, line:.line, html_url:.html_url, body:.body[:60]}]'
```

Store the `html_url` of each new finding comment as `{COMMENT_URLS}` (keyed by finding title or ID). Pass these into Step 9 to build the linked verdict body.

**For re-review — resolve GitHub threads for addressed comments:**

After posting all replies, mark the corresponding review threads as resolved using
the GraphQL API. Write a Python script to avoid shell-escaping issues with GraphQL
mutation strings (inline `python3 -c` with quoted node IDs breaks reliably):

Write the script at `/tmp/resolve_threads.py` — template at [resolve-threads.py](../scripts/resolve-threads.py) — then run it:

```bash
python3 /tmp/resolve_threads.py
```

Resolve threads for every comment in `{RESOLVED}`. Do **not** resolve `{STILL_OPEN}` threads.
