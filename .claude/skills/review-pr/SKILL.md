---
name: review-pr
description: Review one or more GitHub Pull Requests — detects re-reviews, asks strictness level (Quick/Standard/Thorough/Strict), posts 🔴/🟡/🟢 inline comments per finding, and submits APPROVE or REQUEST_CHANGES via the GitHub API. Accepts multiple PR URLs/numbers for parallel batch review.
metadata:
  category: Delivery
  tags: [github, pull-request, code-review, inline-comments, re-review, batch]
---

# PR Review

Fetches one or more GitHub PRs, checks for prior reviews, determines strictness,
runs deep analysis (in parallel when multiple PRs), posts each finding as a separate
inline comment, then submits a formal review per PR.

**Input**: `/review-pr <PR URL or number> [<PR URL or number> ...]`

Single PR: `/review-pr https://github.com/org/repo/pull/123`
Batch: `/review-pr 123 456` or `/review-pr https://github.com/org/repo/pull/123 https://github.com/org/repo/pull/456`

---

## Model Recommendation

This skill runs deep analysis and reasons about diffs, null semantics, SQL parity,
and test coverage. Haiku is not sufficient for the orchestrator.

**Check the active model** (shown as "You are powered by the model named…"):

- **Haiku**: stop immediately and tell the user:
  > "PR review requires Sonnet or Opus — switch models and re-run."
- **Sonnet or Opus**: proceed. Store as `{ORCHESTRATOR_MODEL}`.

---

## Step 0: Select Analysis Agent Model

**Single PR**: analysis sub-agent inherits `{ORCHESTRATOR_MODEL}`. Skip this step.

**Batch mode only** — ask using AskUserQuestion:

> **"Which model should the {N} parallel analysis agents use?"**
>
> | Model | Speed | Quota | Best for |
> |-------|-------|-------|----------|
> | **Sonnet** *(recommended)* | Balanced | Moderate | Reliable review depth — catches subtle bugs, null-safety issues, logic errors |
> | **Opus** | Slowest | High | Deepest analysis — for security-sensitive or architecturally complex PRs |
> | **Haiku** | Fastest | Low | Not recommended — shallow analysis misses subtle issues in code review |
>
> Sonnet is the default. Haiku saves quota but produces noticeably weaker findings on
> diff analysis. Opus is best when any PR in the batch is security-sensitive or large.

Store as `{AGENT_MODEL}`. Pass `model: "{AGENT_MODEL}"` when spawning each analysis
sub-agent in Step 6.

---

## Step 1: Parse PR References

Split `$ARGUMENTS` on whitespace to get one or more PR references. For each token:

- Full URL `https://github.com/<owner>/<repo>/pull/<n>` → parse directly
- `<n>` only → infer repo from `git remote get-url origin`
- `<n> <owner>/<repo>` → use as given

Store as `{PR_LIST}`: an array of `{OWNER, REPO, PR_NUMBER}` objects.

**Single PR**: set `{BATCH_MODE}=false`, use `{PR_LIST}[0]` as `{OWNER}`, `{REPO}`, `{PR_NUMBER}` throughout — the rest of the flow reads identically.

**Multiple PRs**: set `{BATCH_MODE}=true`. Steps 2–5 run for each PR; Step 4 consolidates into one strictness question; Step 6 runs analysis agents in parallel; Step 7 presents a combined summary before one batch post confirmation.

For every PR in `{PR_LIST}`, store its resolved metadata as `{PR_MAP}[PR_NUMBER]` (owner, repo, title, additions, deletions, changedFiles, headSha, isRereview, lastReview, sensitivity, strictnessMode, findings, etc.) — this map is the per-PR scratchpad used in Steps 2–9.

---

## Step 2: Fetch PR Metadata and Detect Re-review

```bash
gh api user --jq '.login'   # store as {CURRENT_USER} (once, shared across all PRs)
```

**For each PR in `{PR_LIST}`** (run in parallel for batch mode):

```bash
gh pr view {PR_NUMBER} --repo {OWNER}/{REPO} \
  --json title,body,author,state,additions,deletions,changedFiles,\
baseRefName,headRefName,url,mergeable,reviewDecision

gh pr view {PR_NUMBER} --repo {OWNER}/{REPO} \
  --json files --jq '.files[] | "\(.additions)+ \(.deletions)- \(.path)"'

gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER} --jq '.head.sha'
# store per PR as {PR_MAP}[PR_NUMBER].headSha

gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/reviews \
  --jq '[.[] | {id:.id, user:.user.login, state:.state, submitted_at:.submitted_at}]'
```

**Merged / closed PR check** (per PR): read the `state` field from the `gh pr view` output.

- `MERGED` → PR was merged; reviewing it will not block or unblock anything.
- `CLOSED` (not merged) → PR was abandoned.
- `OPEN` → normal; continue.

After fetching metadata, collect every PR whose `state` is not `OPEN` into `{NON_OPEN_PRS}`. If non-empty, ask the user before proceeding: for a single PR confirm "already {state} — review anyway?"; for batch mode show a table and ask "Review all / Skip merged-closed / Select". Remove user-skipped PRs from `{PR_LIST}`; if `{PR_LIST}` is now empty, stop.

**Re-review detection** (per PR): filter reviews by `{CURRENT_USER}`. If any exist, store
the most recent as `{PR_MAP}[PR_NUMBER].lastReview` (id, state, submitted_at).
Set `{PR_MAP}[PR_NUMBER].isRereview=true`.

**CI check status** (per PR): fetch check runs for `{HEAD_SHA}` and required checks for the base branch.

⚠️ `gh pr checks --json` silently returns `[]` in some environments and must NOT be used as the primary source. Always fetch directly from the check-runs API:

```bash
gh api repos/{OWNER}/{REPO}/commits/{HEAD_SHA}/check-runs \
  --jq '[.check_runs[] | {name:.name, state:.status, conclusion:.conclusion}]'
# store as {PR_MAP}[PR_NUMBER].checkRuns

gh api repos/{OWNER}/{REPO}/branches/{BASE_REF}/protection \
  --jq '.required_status_checks.contexts // []' 2>/dev/null || echo '[]'
# store as {PR_MAP}[PR_NUMBER].requiredChecks (empty array if branch protection is not configured)
```

Derive `{PR_MAP}[PR_NUMBER].failingChecks`: entries from `checkRuns` where
`conclusion == "failure"` or `conclusion == "timed_out"` or `state == "failure"`.
Derive `{PR_MAP}[PR_NUMBER].failingRequiredChecks`: subset of `failingChecks` whose
`name` appears in `requiredChecks`. Store both arrays.

---

## Step 2a: PR Quality Pre-check

**For each PR in `{PR_LIST}`** (after Step 2 metadata is available, before strictness):

Check the PR's title and description quality.

**Description check** — flag if ALL of the following are true:
- `body` is empty, `null`, whitespace-only, or fewer than 50 non-whitespace characters
- PR is non-trivial: `changedFiles > 1` OR `additions > 30`

**Title check** — flag if the title is clearly a stub:
- Fewer than 10 characters, OR
- Looks like a raw branch-name slug: all lowercase with only dashes/underscores and no spaces (e.g., `fix-bug`, `update-stuff`, `feature/thing`)

**If the description is missing/stub AND the PR is non-trivial:**

1. Post a top-level comment on the PR:

```bash
gh api repos/{OWNER}/{REPO}/issues/{PR_NUMBER}/comments \
  -X POST \
  -f body="🟡 **Missing PR description**

This PR makes non-trivial changes but has no description. Please add a description covering:
- What changed and why
- Any behavioral differences reviewers should know about
- Links to related issues, specs, or prior art

A reviewer cannot confidently verify intent against the diff without this context."
```

2. Use AskUserQuestion: "PR #{PR_NUMBER} — \"{TITLE}\" has no description. I've posted a comment asking the author to fill it in. Stop here and wait, or continue the review anyway?"
   - **Stop** → remove this PR from `{PR_LIST}`. If `{PR_LIST}` is now empty, end the skill run.
   - **Continue** → note the missing description as a 🟡 Concern in the analysis brief; proceed to Step 3.

**If only the title is a stub** (but description is present): include it as a 🟢 Minor in the analysis brief; do not stop.

**For batch mode**: collect all PRs with missing descriptions into a table and ask once — "N of M PRs have no description. Stop those PRs, continue all, or select per PR?"

---

## Step 3: Fetch Re-review Context (re-review only)

Skip this step unless `{IS_REREVIEW}` is true.
Follow the full fetch protocol in [references/re-review-context.md](references/re-review-context.md). Store results as `{PREV_REVIEW_COMMENTS}`, `{PREV_REVIEW_VERDICT}`, `{COMMITS_SINCE_REVIEW}`.

**This step contains an early-exit gate.** If there are no new commits, no new PR-level comments, and no replies to your prior review comments since `{LAST_REVIEW.submitted_at}`, the protocol uses AskUserQuestion to recommend stopping (with an option to continue anyway). This gate fires BEFORE Step 4 — do not ask for strictness mode until it has been checked.

---

## Step 4: Recommend and Confirm Strictness Mode

Read [references/strictness-modes.md](references/strictness-modes.md) for the full
behaviour of each mode.

**Recommend a mode per PR** based on PR metadata (use `{IS_SECURITY_SENSITIVE}` from Step 4a):

| Signal | Recommended mode |
|--------|-----------------|
| `{IS_SECURITY_SENSITIVE}` is true (auth, RBAC, permissions, …) | **Strict** |
| PR size >500 additions OR >15 files changed | **Thorough** |
| Re-review with only small new commits | **Quick** |
| Hotfix, config tweak, or <50 additions | **Quick** |
| Anything else | **Standard** |

Use AskUserQuestion to confirm. For single PR: "How strictly should I review PR #{PR_NUMBER} — {title}? I recommend **{RECOMMENDED_MODE}** based on {brief reason}." For batch: if all recommendations match, ask once; if they differ, show a per-PR table and offer "Use recommended per PR" or a single override mode (Quick / Standard / Thorough / Strict).

**This step is mandatory — never skip it, even for re-reviews or tiny fix commits.** The recommendation changes based on signals; the confirmation question does not get omitted.

Store `{PR_MAP}[PR_NUMBER].strictnessMode` per PR (or the override for all PRs).

---

## Step 4a: Classify PR Sensitivity

Scan the changed file list from Step 2 for security-sensitive signals. Check for any of:

- Auth, OAuth, session, or token handling
- RBAC, permission checks, or access-control logic
- CI/CD workflows, deploy scripts, or Dockerfile changes
- API keys, secrets, service accounts, or machine identities
- SSO/SAML/OIDC integration

Store `{IS_SECURITY_SENSITIVE}=true` if any signal is present, otherwise `false`.
Store `{SECURITY_SIGNALS}` as a short list of the matched signals (e.g. `["RBAC", "permission checks"]`).

This classification is used by both Step 4b (persistence audit offer) and Step 4 when
computing the strictness recommendation — a security-sensitive PR always recommends Strict.

---

## Step 4b: Offer Persistence Audit (security PRs only)

Skip this step unless `{IS_SECURITY_SENSITIVE}` is true.

Read [references/persistence-audit-integration.md](references/persistence-audit-integration.md) for the full persistence audit offer protocol, severity mapping, and posting instructions.

Store the choice as `{PERSISTENCE_AUDIT_ACTION}` (inline | top-level | show-first | skip).

---

## Step 5: Stage the Diff

**For each PR in `{PR_LIST}`** (run in parallel for batch mode):

For a **first review**: save the full PR diff.

```bash
gh pr diff {PR_NUMBER} --repo {OWNER}/{REPO} \
  > /tmp/pr-{PR_NUMBER}-diff.txt 2>/dev/null
```

For a **re-review**: also save the diff of only the new commits. Find the SHA of the
last commit before `{LAST_REVIEW.submitted_at}` from the commit list, then:

```bash
gh api repos/{OWNER}/{REPO}/compare/{LAST_REVIEWED_SHA}...{HEAD_SHA} \
  --jq '.files[] | "=== \(.filename) ===\n\(.patch // "(binary)")"' \
  > /tmp/pr-{PR_NUMBER}-new-diff.txt
```

If a specific file's diff is needed during analysis, extract it:
```bash
sed -n '/^diff --git a\/{PATH}/,/^diff --git/p' /tmp/pr-{PR_NUMBER}-diff.txt | head -200
```

---

## Step 5a: Fetch PR Commit List

Fetch all commits in the PR and store them for use by Step 5b and (for re-reviews)
Step 3. Skip if `{IS_REREVIEW}` is true — Step 3 already fetched the commit list.

```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/commits \
  --jq '[.[] | {sha:.sha, message:.commit.message, date:.commit.author.date}]'
```

Store as `{PR_COMMITS}`.

---

## Step 5b: Fetch Merged PR Context

Read [references/merged-pr-context.md](references/merged-pr-context.md) for the full fetch
protocol, context file format, and truncation rules.

Set `{HAS_MERGED_CONTEXT}=true` if any PRs were fetched, otherwise `false`.

---

## Step 5c: Run Mechanical Gates Against PR Diff

For each PR, run the 13 mechanical hydra-gates against the PR's head SHA, scoped to the
PR's diff. Findings here are **pre-flagged** — they go into the analysis brief as already-confirmed
🔴 blockers, and any failure on the protected list (gates 5, 7, 8, 9, 10, 11, 12, 13) forces
`REQUEST_CHANGES` in Step 9.

**Why pre-flag mechanically?** The agent is good at semantic analysis but inconsistent on
syntactic checks (regex-style scans for forbidden patterns, attribute presence, label props,
file location). The gate script gets this right deterministically — let it.

**For each PR in `{PR_LIST}`** (run sequentially, not parallel — git worktrees use the same
clone):

```bash
bash scripts/run-gates-on-pr.sh \
    {OWNER} {REPO} {PR_NUMBER} {BASE_REF} \
    > /tmp/pr-{PR_NUMBER}-gates.log 2>&1
GATES_EXIT=$?
```

**Outcomes:**

| Exit | Meaning | Action |
|------|---------|--------|
| `0` | All 13 gates passed | Set `{PR_MAP}[PR_NUMBER].gateFindings = []` and `gateStatus = "passed"` |
| `1`–`13` | That many gates failed | Parse `[gate-N] <name>: FAIL — <reason>` lines from the log; per-gate detail is in `/tmp/hydra-gate-<name>.log`. Store as `gateFindings: [{n, name, reason, file_lines, fix_skill}]`, set `gateStatus = "failed"` |
| `97` | Worktree creation failed | `gateStatus = "skipped"`, note in brief; do NOT block |
| `98` | Could not fetch PR head | `gateStatus = "skipped"`, note in brief |
| `99` | CWD is not a clone of `{OWNER}/{REPO}` | `gateStatus = "skipped"`, note in brief; suggest user run review-pr from inside a clone |

**Protected gate list (forces `REQUEST_CHANGES`):**
- Gate 5 — route-auth (NC middleware reachability)
- Gate 7 — no-admin-IDOR (OWASP A01)
- Gate 8 — unsafe-auth-resolver (CWE-863 fail-open)
- Gate 9 — semantic-auth (annotation/body mismatch)
- Gate 10 — initial-state (CSP-hardened breakage + ADR-004)
- Gate 11 — admin-router (security regression)
- Gate 12 — nc-input-labels (WCAG 1.3.1 / 4.1.2)
- Gate 13 — modal-isolation (ADR-004)

Failures on the unprotected list (1, 2, 3, 4, 6) still surface as 🔴 blockers in the analysis
brief, but Step 9's strictness rules apply normally.

---

## Step 6: Deep Analysis

**For batch mode**: spawn one analysis subagent **per PR in parallel** (all in a single
Agent tool call), each with `model: "{AGENT_MODEL}"` (from Step 0). Each subagent is
independent and receives only its own PR's data. Wait for all subagents to complete before
moving to Step 7. Store each subagent's findings in `{PR_MAP}[PR_NUMBER].findings`.

**For single PR**: spawn one analysis subagent with `model: "{ORCHESTRATOR_MODEL}"` (inherits
the orchestrator model — no separate choice).

Run the analysis in an isolated context (a fresh general-purpose delegate).
Follow the full briefing and instruction template in [references/analysis-brief.md](references/analysis-brief.md).

---

## Step 7: Present Findings in Chat

**For single PR** — first review:
```
### Analysis: #{PR_NUMBER} — {title}  [Mode: {STRICTNESS_MODE}]

⚠️ CI failing (N checks): [check-name], [check-name]   ← omit banner if all checks pass
🔴 Blockers (N): [file:line] Title
🟡 Concerns (N): ...
🟢 Minor (N):    ...
```

Show the CI banner only when `{failingChecks}` is non-empty. If `{failingRequiredChecks}`
is non-empty, mark the banner **Required checks failing** in bold. If only non-required
checks fail, use plain **CI failing**.

**For single PR** — re-review:
```
### Re-review: #{PR_NUMBER} — {title}  [{N} new commits since {LAST_REVIEW.submitted_at}]

✅ Addressed since last review (N): [comment summary]
⚠️  Still open (N): [comment summary]
🆕 New findings (N): [file:line] Title
```

**For batch mode**: show a consolidated table (PR | Title | Mode | CI | 🔴 | 🟡 | 🟢 | Verdict) first,
where CI is ✅ (all pass), ⚠️ (non-required failures), or 🚫 (required failures),
then per-PR finding details in the same format as single-PR.

**Annotate pre-discussed findings** (`already_discussed_in` non-empty): append
`_(discussed in merged PR #N)_` after the title. These are still included — prior discussion
does not resolve them; the annotation informs the reviewer the author was likely aware.

Use AskUserQuestion to confirm before posting. For single PR: "Post these findings and submit a formal review?" (Yes / Skip some / Edit first). For batch: "Post findings for all {N} PRs?" (Yes / Select PRs / Edit first / Cancel).

---

## Step 8: Post Inline Comments

Skip if `{INLINE_COMMENT_COUNT}` is 0.
Follow the full posting protocol in [references/post-inline-comments.md](references/post-inline-comments.md).

---

## Step 9: Submit Formal Review

Determine event type based on `{STRICTNESS_MODE}`:

Read [references/strictness-modes.md](references/strictness-modes.md) for the verdict
rules per mode. In summary:

| Mode | Verdict |
|------|---------|
| Quick | APPROVE unless definite 🔴 blockers |
| Standard | REQUEST_CHANGES if 🔴; else APPROVE |
| Thorough | REQUEST_CHANGES if 🔴; else APPROVE |
| Strict | REQUEST_CHANGES if any 🔴 OR 🟡 |

**CI override**: if `{failingRequiredChecks}` is non-empty, always emit `REQUEST_CHANGES`
regardless of mode and code findings. Note this in the review body:
> "Required CI checks are failing: [check-name], … — please fix before merging."

If only non-required checks are failing, do not override the verdict, but note them in
the review body so the author is aware.

**Mechanical-gate override**: if `{PR_MAP}[PR_NUMBER].gateFindings` (from Step 5c) contains
any failure on the protected list (gates 5, 7, 8, 9, 10, 11, 12, 13), always emit
`REQUEST_CHANGES` regardless of mode. These are security and ADR-004 hard-rule violations —
non-negotiable in any strictness mode. Note this in the review body:
> "Mechanical gates failed: gate-{n} {name} — {reason}. See `hydra-gate-{name}` skill for the
> fix protocol."

Failures on the unprotected list (1, 2, 3, 4, 6) follow normal mode rules — they are 🔴
findings that count toward the strictness verdict but don't independently override it.

For re-review: also consider `{STILL_OPEN}` prior comments when determining verdict.
If all prior blockers are resolved and no new ones exist, lean toward APPROVE (per mode).

**Before submitting**: fetch the `html_url` for every inline comment posted in Step 8:
```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/comments \
  --jq '[.[] | select(.pull_request_review_id == {COMMENT_REVIEW_ID}) | {id, html_url, body: .body[:60]}]'
```
Use these URLs as markdown links in the verdict body so the author can click straight to each finding.

Submit:
```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/reviews \
  -X POST \
  -f commit_id="{HEAD_SHA}" \
  -f event="{APPROVE|REQUEST_CHANGES}" \
  -f body="{verdict body — see format below}"
```

**Verdict body format**: one or two sentences max. Reference each finding with a markdown link:
- REQUEST_CHANGES: `"N blocker(s) require fixes — [Title](html_url)[, …]. [What checks out]."` 
- APPROVE: `"No blockers. [Any notable observations with links to concern-level comments if present]."` 

For re-review, also note: how many old issues were resolved, how many remain open (with links), and how many new issues were found (with links).

---

## Capture Learnings

After completion, append new observations to [learnings.md](learnings.md):

- **Patterns That Work** — approaches that caught real issues or resolved threads cleanly
- **Mistakes to Avoid** — errors in analysis, comment placement, or verdict
- **Domain Knowledge** — facts about the codebase or patterns discovered
- **Open Questions** — unresolved items for future sessions

Format: `- YYYY-MM-DD: <insight>`. One insight per bullet. Skip if nothing new.

---

## Guardrails

- **Never post comments on lines outside the diff.** Verify every line number against
  hunk ranges before posting.
- **Never submit both COMMENT and REQUEST_CHANGES/APPROVE in a single API call.**
  Inline comments go in one review (COMMENT event); verdict is a separate call.
- **Never skip the isolated analysis step for PRs with more than 5 changed files.**
- **Never fabricate findings.** If nothing is wrong, APPROVE with an empty comment list.
- **Do not repost prior comments** — check `{PRIOR_COMMENTS}` before posting. If a
  finding duplicates a prior unresolved comment, reply to the existing thread instead
  of opening a new one.
- **Do not post to PRs on repos outside the user's control** without explicit confirmation.
- **Quick mode**: when uncertain whether an issue is blocking, post as 🟡 Concern, not
  🔴 Blocker. In doubt, approve.
- **Strict mode**: when uncertain whether an issue is blocking, post as 🔴 Blocker.
  Request changes for any 🟡 Concern regardless of whether it is strictly blocking.
- **Always verify "pre-existing / out of scope" deferrals.** When an author claims a
  concern targets pre-existing code, grep the diff to confirm the change site is not in
  the PR. Authors with broad context merges frequently misattribute new changes to them.
- **Check group completeness across the full diff.** When a fix is verified at one site
  (e.g. `executeQuery()` → `executeStatement()` in one file), grep the rest of the diff
  for the same pattern. A PR can correctly fix one instance while reintroducing the same
  bug elsewhere.
