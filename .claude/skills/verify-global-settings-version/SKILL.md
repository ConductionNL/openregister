---
name: verify-global-settings-version
description: Check whether global-settings/VERSION has been correctly bumped after any changes to files in the global-settings/ directory
---

# Verify Global Settings Version

**Model check — only apply when this skill is run standalone (invoked directly by the user via `/verify-global-settings-version`). Skip this section entirely if this skill was called from within another skill — the calling skill is responsible for model selection.**

- **On Haiku**: proceed normally — this is the right model for this task.
- **On Sonnet**: inform the user and ask using AskUserQuestion:
  > "⚠️ You're on Sonnet. This skill runs git commands to check version file consistency — no reasoning required. Haiku is a better fit and conserves quota for heavier tasks. Switch with `/model haiku`, or proceed with Sonnet."
  Options: **Proceed with Sonnet** / **Switch to Haiku first** (stop here if switching)
- **On Opus**: stop immediately:
  > "You're on Opus. This skill runs git commands to check version file consistency — no reasoning required. Opus is overkill here and will waste quota unnecessarily. Please switch to Haiku (`/model haiku`) and re-run."

---

Checks whether the `global-settings/VERSION` file has been correctly bumped after any changes to files in the `global-settings/` directory. Run this before creating a PR on the `ConductionNL/.github` repo to ensure users will be notified to update.

---

## When to use

- Before running `/create-pr` on the `ConductionNL/.github` repo
- Any time you modify a file in `global-settings/` and want to confirm the version bump is in place
- During code review to verify a PR touching `global-settings/` includes a version bump

---

## Step 1: Locate the repo and determine compare branch

The canonical `global-settings/` directory lives in the [`ConductionNL/.github`](https://github.com/ConductionNL/.github/tree/feature/claude-code-tooling/global-settings) repo:

```bash
REPO_DIR="$(git rev-parse --show-toplevel 2>/dev/null)"
git -C "$REPO_DIR" rev-parse --show-toplevel
```

If the repo cannot be found, stop and tell the user.

**Determine the compare branch (`{COMPARE_BRANCH}`):**

- If this skill was called from `/create-pr` with a `{TARGET_BRANCH}` value → use `origin/{TARGET_BRANCH}` as `{COMPARE_BRANCH}`.
- Otherwise (standalone invocation): read `~/.claude/settings-repo-ref`:
  ```bash
  if [ -f ~/.claude/settings-repo-ref ]; then
    COMPARE_BRANCH="origin/$(cat ~/.claude/settings-repo-ref | tr -d '[:space:]')"
  else
    COMPARE_BRANCH="origin/main"
  fi
  echo "Compare branch: $COMPARE_BRANCH"
  ```

Store the result as `{COMPARE_BRANCH}`. All subsequent steps use this value instead of a hardcoded `origin/main`.

---

## Step 2: Check for changes in `global-settings/`

Compare the current branch (`HEAD`) against `{COMPARE_BRANCH}` to find which files in `global-settings/` have been modified:

```bash
git -C "$REPO_DIR" fetch origin "$(echo '{COMPARE_BRANCH}' | sed 's|^origin/||')" --quiet --depth=1 2>/dev/null
git -C "$REPO_DIR" diff --name-only {COMPARE_BRANCH}...HEAD -- global-settings/
```

Store the result as `{CHANGED_FILES}`.

---

## Step 3: Check if VERSION was bumped

Regardless of whether other files changed, read both versions:

```bash
# Current branch VERSION
current=$(cat "$REPO_DIR/global-settings/VERSION" | tr -d '[:space:]')

# Compare branch VERSION
main=$(git -C "$REPO_DIR" show {COMPARE_BRANCH}:global-settings/VERSION 2>/dev/null | tr -d '[:space:]')

echo "Current branch  : $current"
echo "{COMPARE_BRANCH}: $main"
```

---

## Step 4: Evaluate and report

### Case A — No changes to `global-settings/`

> ✅ No files in `global-settings/` were changed relative to `{COMPARE_BRANCH}`. No version bump needed.

### Case B — Changes found AND `VERSION` was bumped higher

Verify the bump is a valid semver increment (major, minor, or patch):

> ✅ `global-settings/` changes detected and `VERSION` was correctly bumped from `v{main}` → `v{current}`.
>
> Changed files:
> - `{file1}`
> - `{file2}`

### Case C — Changes found but `VERSION` was NOT bumped

> ❌ **VERSION BUMP MISSING**
>
> The following files in `global-settings/` were changed but `VERSION` was not incremented:
> - `{file1}`
> - `{file2}`
>
> Current `VERSION` on this branch: `v{current}` (same as `{COMPARE_BRANCH}`)
>
> **Action required:** Increment `global-settings/VERSION` before creating a PR.
> Suggested next version: `v{suggested}` (patch bump — use minor if behavior changed, major if breaking)
>
> To apply the suggested bump:
> ```bash
> echo "{suggested}" > "$REPO_DIR/global-settings/VERSION"
> ```
> Then commit the change and re-run `/verify-global-settings-version`.

### Case D — `VERSION` was changed but no other files changed

> ⚠️ `VERSION` was bumped from `v{main}` → `v{current}` but no other files in `global-settings/` were changed.
>
> This is unusual — confirm the bump is intentional before creating a PR.

---

## Integration with `/create-pr`

When `/create-pr` is run and the selected repository is `ConductionNL/.github`, this check runs automatically as part of Step 3.4 (before local quality checks). The create-pr skill passes `{TARGET_BRANCH}` so the comparison runs against the PR's actual target branch instead of a hardcoded default. If a missing version bump is detected (Case C), the PR flow is paused and the user is asked to fix it before continuing.

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`) when done.
