---
name: report-out
description: Daily end-of-day report — scans local repos and the user's GitHub activity today, suggests committing/pushing uncommitted changes, creating PRs for orphan branches, and lifecycle actions on tracking issues (close, follow-up); ends with a copy-paste Dutch Slack report-out message
metadata:
  category: Workflow
  tags: [daily, git, github, report, dutch, end-of-day]
---

# Report Out — Daily End-of-Day Workflow

Scans the user's local git repositories for today's commits and uncommitted changes, auto-discovers GitHub issues and PRs the user has interacted with, surfaces suggestions for actions that may be needed (new PRs, issue status changes, follow-up issues), then walks the user through optional updates. **Always ends with a single copy-paste-ready Dutch report-out message** referencing the issues/PRs the user has created or merged.

**Input**: invoked as `/report-out`. Optional argument: a date string (`YYYY-MM-DD`) — defaults to today.

---

## Hard Rules

- **NEVER `git push`, `git commit`, `git stash`, or any destructive git action** without explicit user authorization in the current message (per project memory rule on git push). The skill must show what will be staged/committed/pushed in chat **before** running, and execute only after `AskUserQuestion` returns Yes.
- **NEVER post, patch, create, or close** an issue / comment / PR without showing the draft to the user first and getting explicit approval via `AskUserQuestion`. This applies to all suggestions in Steps 7–9.
- **NEVER use `gh pr edit`** — fails with "Projects (classic) is being deprecated". Use `gh api repos/{OWNER}/{REPO}/pulls/{N} -X PATCH` instead.
- **Filter strictly by the detected author** (`git config user.name` + `gh api user --jq .login`) — never assume a hardcoded username.
- **Use `$HOME` for path discovery** — never assume `/home/<name>` paths. Use `git rev-parse`, `gh` CLI lookups, and environment variables only.
- **Before posting any comment, check existing comments on that issue from the last 24 hours.** If the user has a recent comment, suggest editing it instead of adding a new one.
- **PR creation requires `git push` authorization first** — the skill must surface the push requirement up front and let the user decide; never push silently.
- **Uncommitted changes are surfaced for action, not committed by default** — Step 8.4 shows them, asks the user, and only commits/stashes/pushes after explicit Yes. Never auto-commit untracked files; always show the file list first.
- **Never use `git commit --no-verify`** unless the user explicitly authorizes it — it bypasses the project's pre-commit hooks (linting, secret scans).

---

## Step 0: Detect Date and User Identity

Resolve the target date and the user's git/GitHub identity dynamically — never hardcode.

```bash
TARGET_DATE="${ARGUMENTS:-$(date +%Y-%m-%d)}"
TZ_OFFSET=$(date +%z)                            # e.g. +0200 (DST-aware)
SINCE="${TARGET_DATE}T00:00:00${TZ_OFFSET}"
UNTIL="${TARGET_DATE}T23:59:59${TZ_OFFSET}"
DISPLAY_DATE=$(date -d "$TARGET_DATE" +%d-%m-%Y) # Dutch DD-MM-YYYY

GIT_AUTHOR=$(git config --global user.name 2>/dev/null || git config user.name)
GIT_EMAIL=$(git config --global user.email 2>/dev/null || git config user.email)
GH_LOGIN=$(gh api user --jq '.login' 2>/dev/null)
HOME_DIR="${HOME:-$(getent passwd "$(id -un)" | cut -d: -f6)}"
```

Store `{TARGET_DATE}`, `{SINCE}`, `{UNTIL}`, `{DISPLAY_DATE}`, `{GIT_AUTHOR}`, `{GH_LOGIN}`, `{HOME_DIR}`.

If `{GH_LOGIN}` is empty, prompt the user to run `gh auth login` and stop.
If `{GIT_AUTHOR}` is empty, prompt the user to set `git config --global user.name "..."` and stop.

---

## Step 1: Ask for Additional Input (Start)

Before any scanning, ask using AskUserQuestion:

**"Anything specific to take into account for today's report-out? (Context for issue comments, PR descriptions, or things to highlight in the Slack message.)"**
- **Nothing extra — proceed with defaults**
- **Yes — let me add context** → ask: "What should I keep in mind?" — capture as `{USER_CONTEXT}`
- **Skip the issue/PR updates — just the report-out** → set `{REPORT_ONLY}=true`, skip Steps 8–9

Store `{USER_CONTEXT}` (may be empty) and `{REPORT_ONLY}`.

---

## Step 2: Confirm Scan Scope

The Hydra repo (where this skill lives) **MUST always be included** in the scan — it is the user's primary work repo, not infrastructure to ignore.

Ask using AskUserQuestion:

**"What scope should I scan for `{DISPLAY_DATE}`?"**
- **Default** — exclude `wordpress-docker` if it exists on this machine, plus `claude-code-config` if present. **Hydra itself is always scanned.**
- **Default + extra excludes** — also exclude `nextcloud-docker-dev` (root only, NOT its `apps-extra/*` children), `openconnector`, `planix` for uncommitted scans
- **Custom** — let me specify the full exclude list

Store as `{EXCLUDED_REPOS}`. The exclusion of `wordpress-docker` is conditional — add it to `{EXCLUDED_REPOS}` only when a `wordpress-docker` repo is actually present in the discovery results. The current repo (Hydra) is **never** added to `{EXCLUDED_REPOS}` — unlike the wordpress-docker variant of this skill, the Hydra variant does not self-exclude.

---

## Step 3: Discover Local Git Repositories

Run the discovery routine in [references/git-discovery.md](references/git-discovery.md). Store as `{REPO_LIST}`.

---

## Step 4: Scan Commits and Uncommitted Changes

For each repo in `{REPO_LIST}`:

```bash
git -C "$repo" log --since="{SINCE}" --until="{UNTIL}" \
  --author="{GIT_AUTHOR}" --pretty=format:"%h|%s|%H" 2>/dev/null
git -C "$repo" status --short 2>/dev/null
git -C "$repo" branch --show-current
git -C "$repo" remote get-url origin
```

Build per-repo summary: branch, commits, uncommitted, unpushed. If `--author="{GIT_AUTHOR}"` returns 0 commits in a repo with same-day commits, fall back to `--author="{GIT_EMAIL}"`. Never broaden to "any author".

Store as `{REPOS_WITH_ACTIVITY}` (only repos with commits today or uncommitted changes).

---

## Step 5: Discover GitHub Interactions Today

See [references/interaction-discovery.md](references/interaction-discovery.md). Run:

```bash
gh search prs --author "@me" --updated ">={TARGET_DATE}" --limit 30 \
  --json number,title,state,url,repository,updatedAt,createdAt,headRefName

gh search issues --commenter "@me" --updated ">={TARGET_DATE}" --limit 30 \
  --json number,title,state,url,repository,updatedAt
gh search issues --author "@me" --updated ">={TARGET_DATE}" --limit 30 \
  --json number,title,state,url,repository,updatedAt

gh search prs --author "@me" --merged ">={TARGET_DATE}" --limit 20 \
  --json number,title,url,repository,mergedAt
```

Deduplicate by `(repository.nameWithOwner, number)`. Split into `{CREATED_OR_MERGED_PRS}`, `{COMMENTED_ISSUES}`, `{TOUCHED_PRS}`.

---

## Step 5.5: Detect Branches Without an Open PR

Per the protocol in [references/branch-pr-detection.md](references/branch-pr-detection.md), for each entry in `{REPOS_WITH_ACTIVITY}`:

```bash
BRANCH=$(git -C "$repo" branch --show-current)
OWNER_REPO=$(git -C "$repo" remote get-url origin \
  | sed -E 's|.*github\.com[:/]([^/]+/[^/.]+)(\.git)?$|\1|')

# Skip default/integration branches — they don't need PRs
case "$BRANCH" in main|master|development|beta|staging) continue ;; esac

# Check whether an open PR already exists for this branch
PR_COUNT=$(gh pr list --repo "$OWNER_REPO" --head "$BRANCH" --state open --json number 2>/dev/null | jq length)
[ "$PR_COUNT" -eq 0 ] && echo "$OWNER_REPO|$BRANCH"
```

For each `(owner_repo, branch)` pair with **today's commits AND no open PR**, add to `{BRANCHES_WITHOUT_PR}` with: commit count today, latest commit subject, behind/ahead counts vs the likely target branch.

Skip a flagged branch if all its commits today were already part of a PR that was closed/merged today (avoid suggesting a re-open). Detect this via `gh pr list --head $BRANCH --state all --search "merged:>=$TARGET_DATE"`.

---

## Step 5.6: Detect Issue Lifecycle Hints

Per the protocol in [references/issue-lifecycle.md](references/issue-lifecycle.md), for each open issue in `{COMMENTED_ISSUES}` plus any saved tracking-issue mappings:

```bash
gh api repos/{OWNER}/{REPO}/issues/{N} \
  --jq '{number, title, state, created_at, comments, body, html_url}'

# Last 20 comments — find the most recent comment by the user, scan for closing-trailer
gh api "repos/{OWNER}/{REPO}/issues/{N}/comments?per_page=20&sort=created&direction=desc" \
  --jq '[.[] | select(.user.login == "{GH_LOGIN}") | {created_at, body}]'
```

Apply these heuristics and tag each issue accordingly:

| Tag | Trigger |
|-----|---------|
| **`close-suggested`** | All PRs referenced in the last 7 days of comments are merged AND user posted a comment today |
| **`closing-trailer-detected`** | Most recent user comment within last 24h contains the Dutch closing-trailer pattern (`/Deze issue lijkt inhoudelijk klaar/`) |
| **`stale-and-busy`** | `state == open` AND `(now - created_at).days > 5` AND `comments > 10` AND no closing-trailer in last 7 days |
| **`needs-followup`** | User-supplied during Step 1 context, OR `stale-and-busy` for >14 days |

Store flagged issues as `{ISSUE_SUGGESTIONS}` with their tags. Do NOT take action yet.

---

## Step 6: Show Overview + Ask Direction

Present a markdown overview grouping:

1. Local repos with commits today (per-repo: branch, commits, uncommitted)
2. PRs created or merged by `{GH_LOGIN}` today
3. Issues `{GH_LOGIN}` commented on today
4. **📝 Repos with uncommitted changes** (from Step 4) — flagged with `→ suggest commit/push`
5. **🆕 Branches without an open PR** (from Step 5.5) — flagged with `→ suggest creating PR`
6. **♻️ Issues with lifecycle suggestions** (from Step 5.6) — flagged with their tag

If `{REPORT_ONLY}` is true, skip ahead to Step 10.

Otherwise ask using AskUserQuestion:

**"What would you like to handle?"**
- **Tracking issues + status suggestions** — Step 7
- **PR updates + creation suggestions** — Step 8 → Step 8.4 → Step 8.5
- **All of the above, then finalize** — Step 7 → Step 8 → Step 8.4 → Step 8.5 → Step 9 → Step 10
- **Skip directly to final report-out** — Step 9 → Step 10

---

## Step 7: Update Tracking Issues + Lifecycle Suggestions

For each tracking issue (existing flow + new suggestions):

### 7a. Check existing comments from the last 24 hours

```bash
SINCE_24H=$(date -u -d '24 hours ago' +%Y-%m-%dT%H:%M:%SZ)
gh api repos/{OWNER}/{REPO}/issues/{N}/comments \
  --jq '[.[] | select(.user.login == "{GH_LOGIN}" and .created_at > "'"$SINCE_24H"'") | {id, created_at, body_preview: (.body[0:200])}]'
```

If a recent user comment exists, ask Edit / New / Skip per [references/comment-update-protocol.md](references/comment-update-protocol.md).

### 7b. Draft and post the comment

Use [templates/issue-comment.md](templates/issue-comment.md). Show draft → ask Yes/Edit/Skip.

### 7c. Issue status suggestions (NEW)

If the issue has a `close-suggested` or `closing-trailer-detected` tag from Step 5.6, ask using AskUserQuestion:

**"Issue #{N} `{title}` — {reason}. Close it?"**
- **Yes, close as completed** — `gh api repos/{OWNER}/{REPO}/issues/{N} -X PATCH -f state=closed -f state_reason=completed`
- **Yes, close as not-planned** — `state_reason=not_planned`
- **Add a label instead** → ask which label, then `gh api repos/{OWNER}/{REPO}/issues/{N}/labels -X POST -f labels[]="..."`
- **Skip** — leave open

Show the proposed action in chat before executing.

### 7d. Follow-up issue suggestions (NEW)

If the issue has a `stale-and-busy` or `needs-followup` tag, ask using AskUserQuestion:

**"Issue #{N} is {age} days old with {N_comments} comments. Create a follow-up issue?"**
- **Yes, draft a follow-up** — proceed to draft per [references/issue-lifecycle.md](references/issue-lifecycle.md), show body, ask "Create?", then `gh api repos/{OWNER}/{REPO}/issues -X POST -f title="..." -f body="..."` if approved
- **Skip — keep adding to the original**

The follow-up body must:
- Reference the parent issue (`Volgt op #{N}`)
- Summarize what's been done so far (1–3 bullets)
- List what's still open (the work that motivates the new issue)
- Include any `{USER_CONTEXT}` notes that apply

### 7e. Today's insights → standalone new issue (NEW)

Once per run (not per issue), ask using AskUserQuestion:

**"Did anything come up today that warrants its own new tracking issue (not a comment on an existing one)?"**
- **No, all in existing issues**
- **Yes — let me describe it** → ask for: target repo, working title, 2–4 bullets of context. Draft full issue body (Dutch). Show. Ask "Create?". If yes, `gh api repos/{OWNER}/{REPO}/issues -X POST`.

---

## Step 8: Update PR Titles and Descriptions

For each open PR in `{CREATED_OR_MERGED_PRS}` with new commits today, follow the existing protocol in [references/pr-update-protocol.md](references/pr-update-protocol.md):

```bash
gh api repos/{OWNER}/{REPO}/pulls/{N} --jq '{title, body}'
```

Apply: prefer extending bullets over adding new ones. Show diff. Ask Yes/Edit/Skip. Patch via `gh api ... -X PATCH`. Never use `gh pr edit`.

---

## Step 8.4: Handle Uncommitted Changes (NEW)

Before suggesting any PR creation, deal with uncommitted work the user may have lying around. For each repo in `{REPOS_WITH_ACTIVITY}` with uncommitted changes:

1. Show the user a summary:
   ```
   📝 {repo}: {N} uncommitted files on `{branch}`
     M  src/foo.php       (modified, staged)
      M src/bar.php       (modified, unstaged)
     ?? notes.md          (untracked)
   ```

2. Ask using AskUserQuestion:

   **"Uncommitted changes in `{repo}` on `{branch}`. What would you like to do?"**
   - **Draft a commit message and commit** — propose a Conventional Commits prefix from the file types (`feat:` for new src files, `fix:` for modified, `docs:` for `*.md`, `chore:` for config), draft 1–2 sentence subject from the file list + any `{USER_CONTEXT}`, show, ask "Looks good?", then run the commit
   - **I'll provide the message** — ask user for the full subject (and optional body), then commit
   - **Stash with WIP label** — `git -C "$repo" stash push -m "WIP from /report-out {TARGET_DATE}"` (reversible — user can `git stash pop` later)
   - **Skip — leave as-is** — do not modify

3. **Commit execution** (only after explicit approval in step 2):

   ```bash
   git -C "$repo" add -A
   git -C "$repo" commit -m "$COMMIT_SUBJECT" -m "$OPTIONAL_BODY"
   ```

   Per project memory, `git commit` is treated like `git push`: the skill must show what will be staged and committed in chat **before** running, and execute only after explicit Yes from the user. If the project's pre-commit hooks fail, surface the failure and ask the user how to proceed (fix and retry, skip, or commit with `--no-verify` only if the user explicitly authorizes that).

4. **Push offer** (only after a successful commit):

   Ask using AskUserQuestion:

   **"Push `{branch}` to origin now?"**
   - **Yes — push** — see push protocol below
   - **No, push later manually** — skip

### Push protocol (commit + PR-creation paths share this)

Pushing requires explicit user authorization per the project's git-push memory rule. Surface up front:

> "Pushing `{branch}` requires the project's git-push authorization phrase. Your current message must contain a phrase like 'please git push' (or you've already authorized in this session). If it doesn't, the system hook will block the push."

Attempt the push:

```bash
git -C "$repo" push -u origin "$branch"
```

If the hook blocks, report:

> "Push blocked by the git-push hook. Send your next message including 'please git push' to authorize, then re-run this skill or run `git -C {repo} push -u origin {branch}` manually."

Do NOT use `--no-verify`, `git -c hooks.autohooksenabled=false`, or any other bypass — that defeats the user's safety policy.

### What this step is NOT for

- Not for committing files in repos that had no commits today and no `{USER_CONTEXT}` mentions — those uncommitted changes may be in-progress work the user wants to keep uncommitted. Only act on repos in `{REPOS_WITH_ACTIVITY}`.
- Not for `claude-code-config` or excluded repos — those were filtered out in Step 2.

---

## Step 8.5: Suggest Creating PRs for Orphan Branches (NEW)

For each `(owner_repo, branch)` in `{BRANCHES_WITHOUT_PR}`:

1. Show the user:
   ```
   ⚠️ Branch `{branch}` in `{owner_repo}` has {N} commits today and no open PR.
   Latest commit: {hash} {subject}
   Ahead of {target}: {N_ahead} commits.  Behind: {N_behind}.
   ```

2. Ask using AskUserQuestion:

   **"Create a PR for `{branch}`?"**
   - **Yes — launch `/create-pr` for me** → instruct the user: "Run `/create-pr` next. It handles branch push authorization, target detection, CI checks, and the title/body draft." Stop the suggestion here; do NOT call gh api directly.
   - **Yes — quick PR via API (no checks)** → confirm push authorization first (see push protocol below), draft a minimal title (`{Conventional Commits prefix}: {latest commit subject}`) + body (commit log), show, ask "Looks good?", then push + create
   - **Not yet — keep working on the branch** → skip
   - **Skip — I'll handle it manually** → skip

### Push authorization

Creating a PR via this skill requires pushing the branch first. Before any push, ask:

**"To create the PR I need to push `{branch}` to origin. Confirm?"**
- **Yes — push and create** → check if the user's most recent message contains a push-authorization phrase (per the project's git-push memory rule). If not, surface: "Per the git push policy, please type 'please git push' (or similar authorization phrase) in your next message to allow the push." Stop here.
- **No — leave it unpushed** → skip

Direct push command (only after authorization):
```bash
git -C "$repo" push -u origin "$branch"
gh api "repos/$OWNER_REPO/pulls" -X POST \
  -f title="$PR_TITLE" -f head="$branch" -f base="$TARGET_BASE" -f body="$PR_BODY" \
  --jq '.html_url'
```

The recommended path is to delegate to `/create-pr` — only use the direct API path when the user explicitly chose "quick PR".

---

## Step 9: Ask for Additional Input (End)

Before producing the final report-out, ask using AskUserQuestion:

**"Anything else to mention in the final Slack report-out message? (Blockers, plans for tomorrow, things to flag for the team.)"**
- **Nothing — generate the message as-is**
- **Yes — let me add a note** → capture as `{FINAL_NOTE}`

---

## Step 10: Produce Final Copy-Paste Dutch Report-Out

Generate a Dutch message using [templates/report-out-message.md](templates/report-out-message.md) with:

- A plain-text heading exactly `Report out:` (no emoji, no Slack bold markers `*...*`, no Markdown — Slack is not Markdown). No day name, no date — Slack adds the timestamp. No newline before the first bullet.
- 2–4 high-level summary bullets (one per logical work area)
- A "PR's:" section listing PRs `{GH_LOGIN}` created or merged today, with status emoji (include any new PRs created in Step 8.5 as `🆕`)
- An "Issues:" section listing tracking issues touched today (include any newly-created issues from Step 7d/7e marked `🆕`, and any closed ones marked `✅ gesloten`)
- The optional `{FINAL_NOTE}` appended last

Present in a single fenced markdown block. The output is the user's deliverable.

---

## Capture Learnings

After execution, append dated observations to [learnings.md](learnings.md):

- **Patterns That Work** — drafting approaches that landed first try
- **Mistakes to Avoid** — wrong identity detection, wrong heuristic thresholds, missed branches, follow-up issues that should not have been suggested
- **Domain Knowledge** — issue conventions, repo defaults, observed lifecycle patterns
- **Open Questions** — heuristic tuning, edge cases

Each entry: `- YYYY-MM-DD — {one atomic insight}`. Skip if nothing new was learned.

---

## Guardrails

- **No `git push` / `git commit`** without explicit user authorization in the current message.
- **PR creation, issue creation, issue closing — all require `AskUserQuestion` confirmation** before invoking the API. The skill must show the proposed action and the API call before executing.
- **The 5-day / 10-comment thresholds for follow-up suggestions are heuristics**, not rules. If the user dismisses a suggestion, do not surface it again in the same run.
- **Always show drafts in chat before posting/patching/creating** — every comment, every PR, every issue.
- **Always check 24h comment history** before posting a new comment.
- **Dutch language only** for issue comments, PR descriptions (when patching), and the final report-out.
- **Identity is dynamic** — `git config user.name`, `gh api user`, `$HOME`. No hardcoded paths or names.
- **Author filter scoping** — fall back to `--author="{GIT_EMAIL}"`, never to "any author".
- **Exclude-list scoping** — exclude only the ROOT of `nextcloud-docker-dev`, never its `apps-extra/*` children.
- **Default branches never get PR suggestions** — `main`, `master`, `development`, `beta`, `staging` are skipped in Step 5.5.
- **Uncommitted-change handling is opt-in per repo** — Step 8.4 asks for each repo separately. Stash is the safe default for in-progress work the user isn't ready to commit.
- **Never bypass pre-commit hooks** — no `--no-verify` unless explicitly authorized by the user in the current message.
- **No self-exclusion (Hydra variant)** — this skill always scans the Hydra repo. Only `wordpress-docker` (if present) and `claude-code-config` (if present) are excluded by default. The user can extend or override the exclude list.
- **Memory check** — save feedback as a memory entry when the user corrects or confirms a non-obvious choice.
