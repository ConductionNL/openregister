---
name: opsx-plan-to-issues
description: Convert an OpenSpec change's tasks.md into a plan.json and create a GitHub Issue for progress tracking
metadata:
  category: Workflow
  tags: [workflow, artifacts, github, experimental]
---

# Plan to GitHub Issues

Convert one or more OpenSpec changes' `tasks.md` into `plan.json` files and create GitHub Issues with task checkboxes for visual progress tracking.

## Instructions

You are converting OpenSpec change task lists into structured JSON and GitHub Issues.

### Step 1: Find and select changes

Scan the current project's `openspec/changes/` directory for active changes (directories containing `tasks.md` that are NOT in `archive/`).

**If no changes found:** inform the user and suggest running `/opsx-ff` or `/opsx-continue` first.

**If exactly one change found:** announce it and proceed. The user can still cancel in the preview step.

**If multiple changes found:** use **AskUserQuestion** with `multiSelect: true` to let the user choose:

> "Which change(s) should I create GitHub issues for?"

- List each change with its name
- Include an **"All changes"** option
- Skip changes that already have a `plan.json` with a `tracking_issue` set (show them as "already has issue #N" in the list but don't make them selectable)

Store the selected changes as `{SELECTED_CHANGES}`.

### Step 2: Identify the GitHub repo and app name

Determine the GitHub repository using this priority order:
1. **project.md table** — Look up the project name in the workspace `project.md`'s Projects table under the "GitHub Repo" column
2. **git remote** — Fall back to `git remote get-url origin` from the project directory
3. **Ask the user** — If neither works, ask which repo to use

Extract the `owner/repo` (e.g., `ConductionNL/nldesign`). This will be stored in plan.json's `repo` field and used by `/opsx-apply` for all GitHub operations.

Determine the **app name** from the project directory name (e.g., `nldesign`, `procest`). This is used as a label on the GitHub issue.

### Step 3: Parse tasks.md into structured JSON

**For each selected change**, read its `tasks.md` and extract each task into this JSON structure:

```json
{
  "change": "<change-name>",
  "project": "<project-directory-name>",
  "repo": "<owner/repo>",
  "created": "<ISO-date>",
  "tracking_issue": null,
  "tasks": [
    {
      "id": 1,
      "title": "<task title from ### Task N: header>",
      "description": "<task description>",
      "status": "pending",
      "spec_ref": "<from spec_ref field in tasks.md>",
      "acceptance_criteria": ["<from acceptance_criteria in tasks.md>"],
      "files_likely_affected": ["<from files field in tasks.md>"]
    }
  ]
}
```

### Step 3.5: Preview and confirm before creating issues

Show the user a preview of **all** issues that will be created:

**If single change:**

```
About to create 1 GitHub issue in <owner/repo>:

📌 [OpenSpec] [<app-name>] <change-name>

Labels: openspec, <app-name>, <spec-1>, <spec-2>, ...

Tasks (as checkboxes in the issue body):
### 1. Section Name
- [ ] 1.1 task title
- [ ] 1.2 task title

### 2. Section Name
- [ ] 2.1 task title
...
```

**If multiple changes:**

```
About to create N GitHub issues in <owner/repo>:

1. 📌 [OpenSpec] [<app-name>] <change-1>
   Labels: openspec, <app-name>, <spec-a>, <spec-b>
   Tasks: X checkboxes

2. 📌 [OpenSpec] [<app-name>] <change-2>
   Labels: openspec, <app-name>, <spec-c>
   Tasks: Y checkboxes

...
```

Use **AskUserQuestion** to ask: "Create these N issue(s) in `<owner/repo>`?"

Options:
- **Yes, create all** — proceed to Step 4
- **Let me adjust first** — end the session so the user can edit `tasks.md` files, then re-run
- **Cancel** — end without creating anything

**Do NOT create any issues until confirmed.**

### Step 3.5: Sync existing tracking issue (if `plan.json` already has `tracking_issue`)

Before creating new issues, check each selected change for an existing `tracking_issue` in its `plan.json`. For changes that already track an issue, **sync** rather than create:

1. **Fetch the live issue body**: `gh issue view <tracking_issue> --json body`
2. **Diff against `tasks.md`**: compare task lines to checkboxes in the live body.
3. **Update only unchecked tasks that are in `tasks.md` but absent from the issue**:
   - For each task in `tasks.md` that is `- [ ]` but the live issue has `- [x]`, leave as-is (the live issue is the source of human progress; do NOT regress checkboxes).
   - For tasks in `tasks.md` not in the issue at all, append them to the `## Tasks` section.
   - Apply edits via `gh issue edit <tracking_issue> --body "<updated body>"` or MCP `update_issue`.

4. **Report sync status** — for each synced change, output:

   ```
   #<num> <change-name>: synced N task(s) (X→checked, Y→unchanged)
   ```

   If no diff, report `synced 0 task(s) (already in sync)`.

5. **Skip Step 4 for synced changes** — they already have a tracking issue and don't need a new one. Continue to Step 4 only for changes WITHOUT `tracking_issue`.

### Step 4: Create the GitHub Issues

**Determine labels** (once per change):
- `openspec` — always present
- `<app-name>` — the project directory name (e.g., `procest`, `nldesign`)
- One label per delta spec — scan `openspec/changes/<change-name>/specs/` for subdirectories; each subdirectory name becomes a label (e.g., if `specs/lead-management/` and `specs/pipeline-views/` exist, add labels `lead-management` and `pipeline-views`)

Ensure all required labels exist (create if missing) — do this **once** before the loop, collecting all unique labels across all changes:
- **MCP (preferred):** GitHub MCP `list_labels` → `{owner, repo}` — check if each label exists; create missing ones with `create_label` → `{owner, repo, name, color}`
- **CLI (fallback):**
  ```bash
  existing_labels=$(gh api repos/<owner>/<repo>/labels --jq '.[].name' 2>/dev/null || echo "")
  echo "$existing_labels" | grep -q "openspec" || gh api repos/<owner>/<repo>/labels --method POST -f name="openspec" -f color="0075ca" >/dev/null
  echo "$existing_labels" | grep -q "<app-name>" || gh api repos/<owner>/<repo>/labels --method POST -f name="<app-name>" -f color="7057ff" >/dev/null
  # For each spec label:
  echo "$existing_labels" | grep -q "<spec-name>" || gh api repos/<owner>/<repo>/labels --method POST -f name="<spec-name>" -f color="d93f0b" >/dev/null
  ```

**Label colors:**
- `openspec`: `0075ca` (blue)
- `<app-name>`: `7057ff` (purple)
- `<spec-name>`: `d93f0b` (red)

**For each selected change, create the issue:**
- The title format is: `[OpenSpec] [<app-name>] <change-name>`
- The body contains a summary from `proposal.md`, followed by a `## Tasks` section with task checkboxes grouped under section headers from `tasks.md`
- Each task checkbox includes the acceptance criteria as a nested checklist below it
- **MCP (preferred):** GitHub MCP `create_issue` → `{owner, repo, title: "[OpenSpec] [<app-name>] <change-name>", body: "<body>", labels: ["openspec", "<app-name>", "<spec-1>", "<spec-2>", ...]}` — returns `{number, html_url}`, capture `number` directly
- **CLI (fallback):** `gh issue create --repo <owner/repo> --title "[OpenSpec] [<app-name>] <change-name>" --body "<body>" --label "openspec,<app-name>,<spec-1>,<spec-2>"` — parse issue number from returned URL

**Issue body structure:**

```markdown
<summary from proposal.md>

## Specs

<list of delta spec names with brief description from each spec>

## Tasks

### 1. Section Name
- [ ] **1.1 Task title**
  - [ ] Acceptance criterion 1
  - [ ] Acceptance criterion 2
- [ ] **1.2 Task title**
  - [ ] Acceptance criterion 1

### 2. Section Name
- [ ] **2.1 Task title**
  - [ ] Acceptance criterion 1
```

### Step 5: Save plan.json

**For each change**, update its `plan.json` with the created issue number (`tracking_issue`) and save at `openspec/changes/<change-name>/plan.json` in the project directory.

### Step 6: Report and ask what's next

Output a summary:

**If single change:**
- Issue URL
- Number of tasks (as checkboxes)
- Labels applied
- Path to plan.json

**If multiple changes:**

```
## Issues Created

| Change | Issue | Tasks | Labels |
|--------|-------|-------|--------|
| <change-1> | #N (<url>) | X tasks | openspec, <app>, <spec-a> |
| <change-2> | #M (<url>) | Y tasks | openspec, <app>, <spec-c> |
```

**If invoked from an orchestrating skill** (the caller will have said something like "Invoked from apply-loop — skip Step 6 AskUserQuestion and return control"): output the summary, then output exactly: `✅ plan-to-issues complete — NEXT STEP: immediately continue to Step 3 of apply-loop without pausing.` Stop immediately. Do **NOT** use AskUserQuestion.

**Otherwise**, use **AskUserQuestion** to ask:

> "Issue(s) created! What would you like to do next?"

Options:
- **Start implementing** (`/opsx-apply`) — begin working through the tasks
- **Review the issue(s) first** — end the session so the user can check the GitHub issues before starting
- **Done for now** — end the session

**Capture Learnings**

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

**Guardrails**
- Do not create issues if `tasks.md` does not exist for a change — skip that change and warn
- If `gh` is not authenticated (`gh auth status` fails), inform the user and stop
- Check if labels exist before creating them (use `gh api repos/<owner>/<repo>/labels` to list them; handles repos with zero labels)
- Do not create duplicate issues — if a change's `plan.json` already has a `tracking_issue`, skip it and warn
- If a change fails during issue creation, report the error and continue with remaining changes
