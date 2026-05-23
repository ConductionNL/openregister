---
name: create-pr
description: Create a Pull Request from the current branch — runs local checks, picks target branch, and opens the PR on GitHub
---

# Create Pull Request

Guides the developer through creating a Pull Request for a Nextcloud app. Confirms the source branch, recommends a target branch based on the branching strategy, optionally runs local quality checks, then creates the PR via the GitHub REST API.

---

## Model Recommendation

This skill involves parsing CI workflows, detecting branch-protection rules, resolving bootstrap deadlocks, and reasoning about code diffs. Mistakes here have real consequences.

**First, check the active model** from your system context (it appears as "You are powered by the model named…").

- If the active model is **Haiku or any model other than Sonnet or Opus**: stop immediately and tell the user:
  > "This command requires Sonnet or Opus — the CI workflow parsing and branch-protection analysis steps need stronger reasoning than Haiku can reliably provide. Please switch models and re-run."

- If the active model is **Sonnet or Opus**: ask the user using AskUserQuestion:

**"You're on [active-model]. Which model should I use for this PR?"**

| Model | Best for |
|---|---|
| **Sonnet** | Most PRs — handles CI parsing and branch logic well |
| **Opus** | Repos with reusable CI workflows, branch-protection rulesets, or a complex branching strategy — that's where it pays off most |

- **Sonnet**
- **Opus**

If the chosen model differs from the active model, tell the user:
> "You're on [active-model] but chose [chosen-model]. To switch: use `/model [chosen-model]` in the chat input, or open the model picker in the Claude Code UI. Then re-run this command."
Then stop.

---

## Hard Rules

**NEVER modify `.github/workflows/` files.** This skill reads workflow files to understand what CI runs, but must never edit, create, or delete any workflow file or change any job definition — regardless of what checks fail or what the branch protection requires. If a workflow mismatch is detected (e.g. wrong job name for a required status check), report the issue to the user and stop — do not attempt to fix it.

---

## Step 0: Select Repository

Scan the workspace for available git repositories:

```bash
# Scan sibling directories of the current git repo for other repos
WORKSPACE_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)/.."
for dir in "$WORKSPACE_ROOT"/*/; do
  if [ -d "$dir/.git" ] || git -C "$dir" rev-parse --git-dir > /dev/null 2>&1; then
    echo "$dir"
  fi
done

# Also scan common locations that may not be siblings: ~/.github, ~/src, ~/projects
for extra in "$HOME/.github" "$HOME/src" "$HOME/projects"; do
  [ -d "$extra" ] || continue
  for dir in "$extra"/*/; do
    if [ -d "$dir/.git" ] || git -C "$dir" rev-parse --git-dir > /dev/null 2>&1; then
      echo "$dir"
    fi
  done
done
```

Also include any additional working directories listed in your environment context (they appear as `Additional working directories:` in the session header). Deduplicate the combined list before presenting it.

For each found repo, also get its remote URL and current branch:
```bash
git -C {dir} remote get-url origin 2>/dev/null
git -C {dir} branch --show-current 2>/dev/null
```

Ask the user using AskUserQuestion:

**"Which repository do you want to create a PR for?"**

List each repo as: `{app-name}  [{current-branch}]  ({remote-url})`

Store the selected repo's absolute path as `{REPO_ROOT}`.

---

## Step 1: Detect Current Branch & Repo

Run within `{REPO_ROOT}`:
```bash
git -C {REPO_ROOT} branch --show-current
git -C {REPO_ROOT} remote get-url origin
```

Store:
- `{CURRENT_BRANCH}` — active branch name
- `{REMOTE_URL}` — GitHub repo URL

---

## Step 2: Confirm Source Branch

Ask the user using AskUserQuestion:

**"Your current branch is `{CURRENT_BRANCH}`. Is this the correct branch to create a PR from?"**
- **Yes** — proceed with `{CURRENT_BRANCH}`
- **No, let me specify** → ask them for the correct branch name and store it as `{CURRENT_BRANCH}`

---

## Step 3: Recommend Target Branch

Based on `{CURRENT_BRANCH}`, determine the recommended target using the project branching strategy:

| Source branch pattern | Recommended target | Allowed targets |
|-----------------------|--------------------|-----------------|
| `feature/*`, `bugfix/*`, or other non-standard branch | `development` | `development` |
| `development` | `beta` | `beta` |
| `beta` | `main` | `main` |
| `hotfix/*` | `main` | `main`, `beta`, `development` |

Fetch available remote branches:
```bash
git -C {REPO_ROOT} fetch --prune
git -C {REPO_ROOT} branch -r | grep -v HEAD | sed 's|origin/||' | sort
```

Ask the user using AskUserQuestion:

**"Which branch should this PR target?"**

List the allowed target branches, marking the recommended one with `(recommended)`. If there is only one valid option, pre-select it and ask for confirmation instead.

Store the answer as `{TARGET_BRANCH}`.

---

## Step 3.2: Validate Target Branch Against Branch-Protection Workflow

Read [references/branch-protection-guide.md](references/branch-protection-guide.md) for the full procedure covering: branch-protection workflow validation (Step 3.2), required-check bootstrap deadlock detection (Step 3.2b), and bootstrap deadlock resolution post-push (Step 3.2c). Apply all three steps now.

---

## Step 3.4: Verify Global Settings Version (ConductionNL/.github only)

**Only run this step if `{REMOTE_URL}` contains `ConductionNL/.github`.**

Invoke the `/verify-global-settings-version` skill now, passing `{TARGET_BRANCH}` as the compare branch, and follow its steps completely.

- If it reports **Case A or B** (no changes, or correctly bumped) → proceed silently to Step 3.5.
- If it reports **Case C** (VERSION BUMP MISSING) → pause the PR flow. Ask using AskUserQuestion:

  **"The global-settings VERSION has not been bumped. How do you want to proceed?"**
  - **Apply a patch bump now** — increment to next patch, show the new value, then continue to Step 3.5
  - **Apply a minor bump now** — increment minor, show new value, then continue to Step 3.5
  - **I'll fix it manually** — stop here

- If it reports **Case D** (VERSION bumped but no file changes) → warn the user but allow the PR to proceed.

---

## Step 3.5: Check for Existing PR

Before running any checks, look up whether a PR already exists for this source → target combination:

```bash
gh pr list --repo "{REMOTE_URL}" --head "{CURRENT_BRANCH}" --base "{TARGET_BRANCH}" --state open --json number,title,url,createdAt,author
```

Also check for a closed/merged PR to give full context:
```bash
gh pr list --repo "{REMOTE_URL}" --head "{CURRENT_BRANCH}" --base "{TARGET_BRANCH}" --state merged --json number,title,url,mergedAt --limit 1
```

**If an open PR already exists:**

Inform the user clearly:

> "An open PR already exists for `{CURRENT_BRANCH}` → `{TARGET_BRANCH}`:
> **#{number}: {title}**
> {url}
> Opened by {author} on {createdAt}"

Then ask using AskUserQuestion:

**"A PR for this branch already exists. What would you like to do?"**
- **View the existing PR** — open the URL and stop here
- **Update the existing PR** (push new commits + update description) — store `{EXISTING_PR_NUMBER}` and proceed to Step 3.7 as normal; in Step 7 use PATCH instead of POST
- **Continue anyway** (create a duplicate — not recommended) — proceed to Step 3.7 as normal

**If a merged PR was found but no open PR:**

Inform the user:

> "Note: A previous PR for `{CURRENT_BRANCH}` → `{TARGET_BRANCH}` was already merged (#{number}: {title}, merged {mergedAt}). You may be re-opening the same work."

Then proceed normally to Step 4.

**If no PR exists:** proceed silently to Step 4.

---

## Step 3.7: Check for Uncommitted or Unpushed Changes

Check the working tree and push status of `{CURRENT_BRANCH}`:

```bash
git -C {REPO_ROOT} status --short
git -C {REPO_ROOT} log origin/{CURRENT_BRANCH}...HEAD --oneline 2>/dev/null || echo "(branch not yet on remote)"
```

**If there are uncommitted changes** (modified, untracked, or staged files):

Before listing all uncommitted changes, specifically check for lock files that are untracked or modified:
```bash
git -C {REPO_ROOT} status --short | grep -E "composer\.lock|package-lock\.json"
```

If `composer.lock` or `package-lock.json` appear as untracked (`??`) or modified (`M`), warn prominently:
> "⚠️ `{filename}` is not committed. CI installs dependencies from the lock file — without it, dependency versions may differ between local and CI, causing check failures. This should be committed before creating the PR."

Inform the user of all uncommitted changes, listing the files. Then ask using AskUserQuestion:

**"There are uncommitted changes on `{CURRENT_BRANCH}`. What would you like to do?"**
- **Commit them now** — ask for a commit message, run `git -C {REPO_ROOT} add -A && git -C {REPO_ROOT} commit -m "{message}"`, then continue
- **Stash them** — run `git -C {REPO_ROOT} stash`, continue, and remind user to `git stash pop` afterwards
- **Continue without committing** — proceed (these changes will not be in the PR)

**If the branch has commits not yet pushed to origin:**

Inform the user. Then ask using AskUserQuestion:

**"Branch `{CURRENT_BRANCH}` has unpushed commits. Push them now before continuing?"**
- **Yes, push now** — run `git -C {REPO_ROOT} push -u origin {CURRENT_BRANCH}`, then continue
- **No, push later** — note that unpushed commits won't be in the PR until pushed; continue

**Merge-freshness check:**

```bash
AHEAD=$(git -C {REPO_ROOT} rev-list HEAD..origin/{TARGET_BRANCH} --count 2>/dev/null || echo 0)
BEHIND=$(git -C {REPO_ROOT} rev-list origin/{TARGET_BRANCH}..HEAD --count 2>/dev/null || echo 0)
echo "source is $BEHIND commits ahead of target; target is $AHEAD commits ahead of source"
```

If `{TARGET_BRANCH}` is **more than 20 commits ahead** of `{CURRENT_BRANCH}`, warn prominently:

> "⚠️ `{TARGET_BRANCH}` is `{AHEAD}` commits ahead of your branch. Without a merge/rebase first, the PR diff will include all of those commits as deletions — making the PR look much larger than it is and confusing reviewers. It is strongly recommended to merge or rebase first."

Then ask using AskUserQuestion:

**"`{TARGET_BRANCH}` is significantly ahead of your branch. What would you like to do?"**
- **Merge `{TARGET_BRANCH}` into my branch now** — run `git -C {REPO_ROOT} fetch origin && git -C {REPO_ROOT} merge origin/{TARGET_BRANCH}`, then re-push and continue
- **Rebase my branch onto `{TARGET_BRANCH}`** — run `git -C {REPO_ROOT} fetch origin && git -C {REPO_ROOT} rebase origin/{TARGET_BRANCH}`, then force-push and continue
- **Continue anyway** — proceed without updating (PR diff may look bloated)

If `{TARGET_BRANCH}` is ≤20 commits ahead, continue silently.

---

## Step 4: Run Local Checks (optional)

Ask the user using AskUserQuestion: **"Do you want to run local quality checks before creating the PR? This mirrors exactly what CI will run and ensures the PR checks pass."** (Yes / No, skip checks → proceed to Step 5)

### Step 4a–4f: Detect and run CI checks
Read [references/local-checks.md](references/local-checks.md) for the full CI detection, tool verification, execution, and reporting protocol.

---

## Step 5: Analyse Branch Changes

Collect the full picture of what is on this branch before drafting anything:

```bash
git -C {REPO_ROOT} log origin/{TARGET_BRANCH}...HEAD --oneline
git -C {REPO_ROOT} log origin/{TARGET_BRANCH}...HEAD --format="%H %s%n%b" --no-merges
git -C {REPO_ROOT} diff --stat origin/{TARGET_BRANCH}...HEAD
git -C {REPO_ROOT} diff origin/{TARGET_BRANCH}...HEAD -- "*.php" "*.js" "*.ts" "*.vue" | head -300
```

Read each changed file's diff to understand what actually changed (not just filenames). This is the basis for the title and description — derive them from the actual code changes, not just commit messages.

Also check if a `plan.json` exists under `{REPO_ROOT}/openspec/changes` (excluding `archive/`) and if found, extract `tracking_issue` — store as `{TRACKING_ISSUE}` (or `null`). This is used in the PR description to auto-close the linked issue on merge.

---

## Step 6: Draft PR Title & Description

**Before drafting anything**, re-read [learnings.md](learnings.md) — specifically "Mistakes to Avoid" — so known constraints are applied on the first draft, not after a correction.

**Pre-draft checklist (verify both before writing a single word):**
- [ ] Title will have a Conventional Commits prefix (`feat:`, `fix:`, `docs:`, etc.)
- [ ] Test plan will use plain bullets (`-`), never `- [ ]` checkboxes

**Detect repo title convention** before writing the title:
```bash
gh pr list --repo "{REMOTE_URL}" --state merged --limit 5 --json title --jq '.[].title'
```

If the merged PRs consistently use a Conventional Commits prefix (`feat:`, `fix:`, `docs:`, `chore:`, etc.), apply the same format. If the repo has no consistent convention, default to a `feat:`/`fix:`/`docs:` prefix anyway — it is forward-compatible and costs nothing.

Using the commit log, diff stat, and file-level diffs from Step 5, draft:

**Title**: Conventional Commits prefix + concise, action-verb phrase describing the main purpose (e.g. `feat: Add full-text search to registers`). Do not use the branch name verbatim. Do not include app names or ticket numbers unless the commits reference them.

**Description**:

```markdown
## Summary

{3–6 bullet points derived from the actual commits and diffs — what changed and why}

## Checks

{one of:}
- ✅ All local checks passed (`composer check:strict`)
- ⚠️ Some checks failed — see CI for details
- ⏭️ Checks skipped

## Test plan

- CI passes
- Tested locally
- Reviewed for regressions

{if TRACKING_ISSUE is set:}
Closes #{TRACKING_ISSUE}
```

Present the draft to the user **in the chat** — show both the title and the full description as they would appear on GitHub.

Then ask using AskUserQuestion:

**"Does this PR title and description look good?"**
- **Yes, proceed** — proceed to Step 7 (will create or update depending on whether `{EXISTING_PR_NUMBER}` is set)
- **Change something** → ask: "What would you like to change or improve?" — apply the feedback, show the updated draft, and ask again
- **Let me write my own title** → ask for a new title, update the draft, show it, and ask again

Repeat the review loop until the user approves.

Store the approved title as `{PR_TITLE}` and description as `{PR_BODY}`.

---

## Step 7: Create or Update the PR

Push the branch to origin if not already pushed:
```bash
git -C {REPO_ROOT} push -u origin {CURRENT_BRANCH}
```

Parse `{OWNER}` and `{REPO}` from `{REMOTE_URL}` (e.g. `https://github.com/ConductionNL/myapp.git` → owner=`ConductionNL`, repo=`myapp`).

**Always use the GitHub REST API directly — never use `gh pr create` or `gh pr edit` (they use GraphQL and may trigger deprecation errors).**

### If updating an existing PR (`{EXISTING_PR_NUMBER}` is set):
```bash
gh api repos/{OWNER}/{REPO}/pulls/{EXISTING_PR_NUMBER} \
  --method PATCH \
  -f title="{PR_TITLE}" \
  -f body="{PR_BODY}" \
  --jq '{number: .number, title: .title, url: .html_url}'
```

### If creating a new PR:
```bash
gh api repos/{OWNER}/{REPO}/pulls \
  --method POST \
  -f title="{PR_TITLE}" \
  -f head="{CURRENT_BRANCH}" \
  -f base="{TARGET_BRANCH}" \
  -f body="{PR_BODY}" \
  --jq '{number: .number, title: .title, url: .html_url}'
```

Store the returned PR number as `{PR_NUMBER}` and URL as `{PR_URL}`.

---

## Step 8: Confirm & Report

After the PR is created, display:
- The PR URL
- Source → target branch
- Check status summary
- Next steps (e.g., "Request a review", "Watch CI status")

---

## Capture Learnings

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.
> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`).
