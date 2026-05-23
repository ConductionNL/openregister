# Local Checks: CI Detection, Tool Verification, Execution, and Reporting Protocol

This reference covers Steps 4a–4f of the create-pr skill: reading CI workflows, determining the check working directory, categorising steps, running installs, verifying tools, running checks, and deciding whether to proceed.

---

### Step 4a: Read CI Workflows — the Source of Truth

**The workflow files define exactly what to run locally. Do not hardcode assumptions.**

Find all workflow files triggered on `push` or `pull_request`:
```bash
find {REPO_ROOT}/.github/workflows -name "*.yml" -o -name "*.yaml" 2>/dev/null | sort
```

If no `.github/workflows` directory exists:
> "No GitHub Actions workflows found — skipping local checks."
Then proceed to Step 5.

Read each workflow file. For each job in a triggered workflow:

**If the job runs steps directly** — read and record every `run:` step in order.

**If the job delegates to a reusable workflow** (`uses: org/repo/.github/workflows/file.yml@ref`) — fetch and read that workflow too:
```bash
# Parse org, repo, path, ref from the 'uses:' value
# e.g. uses: ConductionNL/.github/.github/workflows/quality.yml@main
gh api "repos/{org}/{repo}/contents/{path}?ref={ref}" --jq '.content' | base64 -d
```
Then read the reusable workflow's jobs and their `run:` steps, also noting any `inputs:` the calling workflow passes (e.g. `enable-eslint: true`) — these control which jobs/steps actually execute.

Build a complete ordered list of every `run:` step the CI executes for this repo's push/PR trigger.

### Step 4b: Determine Check Working Directory

For the Nextcloud workspace, checks run inside the app subdirectory. Detect from the changed files:
```bash
git -C {REPO_ROOT} diff --name-only origin/{TARGET_BRANCH}...HEAD
```

Look for which app directory has the most changed files. If ambiguous, ask:

**"Which app directory should we run checks in?"** — list the changed app directories.

Store as `{CHECK_DIR}`.

### Step 4c: Categorise Steps and Build Execution Plan

Read [ci-step-categorisation-guide.md](ci-step-categorisation-guide.md) for the full categorisation rules (Install / Check / Docker check / Skip), mixed-job handling, "requires Nextcloud" detection signals, Docker check adaptations (PHPUnit, Newman), lock file gate, and execution plan display format. Apply the full procedure now.

### Step 4d: Run Install Steps

Run each install step **exactly as it appears in the CI workflow**, in CI order. If any install step fails, stop immediately and show the full error — do not proceed to checks.

### Step 4d-verify: Verify Check Tools Are Available

Read [check-tool-verification.md](check-tool-verification.md) for the full verification procedure: tool checklist by command pattern, local vs Docker probe commands, missing-tool report format, and the three-option resolution prompt. Apply the full procedure now.

### Step 4e: Run Check Steps

Run each check step **exactly as it appears in the CI workflow**, in CI order. Run them one by one, show output as each completes, and record pass/fail.

For steps flagged as optional (e.g. slow test suites with `phpunit`/`pytest`), ask first:
**"Run `{command}` too? (this may be slow)"**

### Step 4f: Report & Decide

Display a results table with one row per check step:

```
CI check results:
  [{job name}] {command}   ✅ PASS / ❌ FAIL
  [{job name}] {command}   ✅ PASS / ❌ FAIL
  ...
```

- If **all checks pass** → proceed to Step 5 with a success note.
- If **any check fails** → show the full output and ask using AskUserQuestion:

  **"Some checks failed. How do you want to proceed?"**
  - **Fix issues first, then re-run** — stop here; let the user fix and re-invoke the skill
  - **Create PR anyway** — proceed to Step 5 with a warning note in the PR body listing which checks failed
