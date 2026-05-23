# Analysis Subagent Brief

Use this when spawning the analysis subagent in Step 6.

## What to include in the brief

- PR title, body, changed file list, additions/deletions
- `{STRICTNESS_MODE}` and its rules (from [strictness-modes.md](strictness-modes.md))
- Path to the staged diff: `/tmp/pr-{PR_NUMBER}-diff.txt`
- For re-review: path to new-commits diff `/tmp/pr-{PR_NUMBER}-new-diff.txt`,
  list of `{PRIOR_COMMENTS}` (file, line, body), list of `{NEW_COMMITS}` messages,
  list of `{THREAD_REPLIES}`
- The review checklist from [review-checklist.md](review-checklist.md)
- The comment format rules from [comment-format.md](comment-format.md)
- **Pre-flagged mechanical-gate findings** (from Step 5c): if `{PR_MAP}[PR_NUMBER].gateFindings`
  is non-empty, list each finding with its gate number, name, reason, and file:line. Each gate
  failure has a corresponding per-gate skill (`hydra-gate-<name>`) documenting the fix; the
  agent must include every gate finding as a 🔴 blocker in its findings list, with the comment
  body pointing the author at the per-gate skill. **Do not re-derive these mechanically** —
  the gate script is authoritative. The agent's job is to confirm the line/range is in the
  diff and write a useful inline comment; no re-scanning needed.
- If `{PR_MAP}[PR_NUMBER].gateStatus == "skipped"`, note that mechanical gates could not run
  for this PR (e.g. the user wasn't inside a clone of the repo) and the agent should perform
  the gate-equivalent checks from the review-checklist by hand.
- **If `{HAS_MERGED_CONTEXT}` is true**: path to `{MERGED_CONTEXT_FILE}` and the
  cross-reference instructions from [merged-pr-context.md](merged-pr-context.md)

## What to instruct it to do

1. Read the diff file(s) and any referenced source files needed for context
2. Verify each concern — check actual code, do not summarize
3. Apply `{STRICTNESS_MODE}` rules:
   - Which severity levels to include
   - How to handle uncertain findings (escalate or downgrade per mode)
4. For each finding, identify the exact `file:line` in the **new file** using hunk
   headers (`@@ -old,len +new,len @@`): context lines at hunk start are new-file
   lines `new`…`new+(context-1)`; added lines follow
5. **For re-review**: additionally assess each prior comment:
   - **Addressed**: new commit clearly fixes the issue → mark for reply
   - **Partially addressed**: issue reduced but not fully fixed → keep as open with note
   - **Unresolved**: no relevant new commit → keep open
   - **New issue in new commits**: include as new finding
6. Report:
   - For re-review: `{RESOLVED}` list, `{STILL_OPEN}` list, `{NEW_FINDINGS}` list
   - For first review: `{FINDINGS}` list
   - Each entry: severity, title, file, line, body text, `already_discussed_in` list
