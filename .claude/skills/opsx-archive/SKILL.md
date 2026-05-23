---
name: opsx-archive
description: Archive a completed change in the experimental workflow
metadata:
  category: Workflow
  tags: [workflow, archive, experimental]
---

Archive a completed change in the experimental workflow.

**Input**: Optionally specify a change name after `/opsx-archive` (e.g., `/opsx-archive add-auth`). If omitted, check if it can be inferred from conversation context. If vague or ambiguous you MUST prompt for available changes.

**Steps**

1. **If no change name provided, prompt for selection**

   Run `openspec list --json` to get available changes. Use the **AskUserQuestion tool** to let the user select.

   Show only active changes (not already archived).
   Include the schema used for each change if available.

   **IMPORTANT**: Do NOT guess or auto-select a change. Always let the user choose.

1.1. **If a change name was provided, verify it exists before proceeding**

   Check that `openspec/changes/<name>/` exists on disk. If it does NOT:

   ```bash
   if [ ! -d "openspec/changes/<name>" ]; then
       echo "Change '<name>' not found. Available active changes:"
       openspec list --json | jq -r '.[] | select(.status != "archived") | "  - " + .name'
       # Prompt for re-selection
   fi
   ```

   Then use **AskUserQuestion** to ask:
   > "Change `<name>` doesn't exist. Pick an active one or cancel."

   List the available changes as options + a "Cancel" option. Do NOT proceed past this step until the user selects a real change or cancels.

2. **Check artifact completion status**

   Run `openspec status --change "<name>" --json` to check artifact completion.

   Parse the JSON to understand:
   - `schemaName`: The workflow being used
   - `artifacts`: List of artifacts with their status (`done` or other)

   - Determine which artifacts are **required** vs **optional**: build the required set by walking the dependency graph backwards from `applyRequires` — any artifact transitively needed to satisfy `apply.requires` is required; all others are optional.

   **If any REQUIRED artifacts are not `done`:**
   - Display warning listing the incomplete required artifacts
   - Prompt user for confirmation to continue
   - Proceed if user confirms

   **If only OPTIONAL artifacts are not `done` (or were never created):**
   - Do not warn — optional artifacts that were skipped are expected. Proceed silently.

3. **Check task completion status**

   Run two checks in parallel — local tasks file and GitHub tracking issue.

   **A. Local tasks.md check**: Read the tasks file (typically `tasks.md`) and count tasks marked `- [ ]` (incomplete) vs `- [x]` (complete).

   **B. GitHub issue check** (if plan.json exists): Read plan.json to get `tracking_issue` and `repo`, then:
   - **MCP (preferred):** `get_issue` → `{owner, repo, issue_number: <tracking_issue>}` → scan body for `- [ ]` lines
   - **CLI (fallback):** `gh issue view <tracking_issue> --repo <repo> --json body --jq '.body'` → count `- [ ]` lines

   **Reconcile findings** — two distinct failure modes:

   **Case A — Sync gap**: tasks.md shows all done, but the GitHub tracking issue has unchecked boxes. The work is complete but GitHub is out of date.
   - **BLOCK the archive** and display:
     ```
     ⛔ Cannot archive: GitHub tracking issue #<n> has N unchecked task(s) but tasks.md shows all complete.
     This is a sync gap — the tracking issue needs to be updated.
     ```
   - Use **AskUserQuestion** to ask: "How do you want to proceed?"
     - **Fix the tracking issue checkboxes now** — Fetch the issue body once, then for each task marked `[x]` in tasks.md: find the parent task line by matching its title, change `- [ ]` to `- [x]`; scan every immediately following line — for each line starting with `  - [ ]` (2-space indent), change it to `  - [x]`; stop scanning at any line that is NOT an indented sub-checkbox (blank line, new parent checkbox, section header, etc.). Write the updated body back in a single call. Then continue to archive.
     - **Archive anyway (override)** — Archive without fixing; add a warning note to the archive summary
   - Only proceed if user explicitly chooses one of these options

   **Case B — Genuinely incomplete tasks**: tasks.md has `- [ ]` items (regardless of what GitHub shows). The work is not done.
   - **BLOCK the archive** and display:
     ```
     ⛔ Cannot archive: N task(s) are still incomplete in tasks.md:
     - Task X: <title>
     ```
   - Use **AskUserQuestion** to ask: "How do you want to proceed?"
     - **Go back and complete tasks** — End the session so the user can finish the work
     - **Archive anyway (override)** — Archive despite incomplete tasks; add a warning note to the archive summary
   - Only proceed if user explicitly chooses the override option

   **If no tasks file exists:** Proceed without task-related warning.

4. **Assess delta spec sync state**

   Check for delta specs at `openspec/changes/<name>/specs/`. If none exist, proceed without sync prompt.

   **If delta specs exist:**
   - Compare each delta spec with its corresponding main spec at `openspec/specs/<capability>/spec.md`
   - Determine what changes would be applied (adds, modifications, removals, renames)
   - Show a combined summary before prompting

   **Prompt options:**
   - If changes needed: "Sync now (recommended)", "Archive without syncing"
   - If already synced: "Archive now", "Sync anyway", "Cancel"

   If user chooses sync, execute `/opsx-sync` logic. Proceed to archive regardless of choice.

4.5. **Convert test-plan test cases to reusable test scenarios**

   Check if `openspec/changes/<name>/test-plan.md` exists. If it does not exist, skip this step silently.

   **If test-plan.md exists:**

   a. Parse all test cases by scanning for `### TC-N:` headers. For each, extract: title (rest of the header line), type, persona, preconditions, steps, expected result, spec_ref, and test command fields.

   b. Determine which app this change belongs to (from the change's `.openspec.yaml` or the project field in plan.json). The test scenarios directory is `<APP>/test-scenarios/` relative to the workspace root (e.g., `openregister/test-scenarios/`, `opencatalogi/test-scenarios/`).

   c. Use **AskUserQuestion** to ask:

   > "This change has N test cases in test-plan.md. Convert them to reusable test scenarios?
   >
   > Test scenarios live in `<APP>/test-scenarios/` and are automatically picked up by `/test-persona-*`, `/test-app`, and `/test-counsel` commands after this change is archived."

   Options:
   - **All N test cases** — convert every TC to a TS file
   - **Let me choose** — select which ones to convert
   - **Skip** — don't create any test scenarios

   **If "Let me choose":** use a second **AskUserQuestion** with `multiSelect: true`, listing each TC as `TC-N: <title>`. User picks which to convert.

   d. For each selected TC, create a test scenario file:

   - **Determine next ID**: scan `<APP>/test-scenarios/` for files matching `TS-NNN-*.md`, find the highest N, increment by 1. Start at `TS-001` if none exist. Create the directory if it doesn't exist.
   - **Map TC fields to TS format:**
     - TC `type` → TS `category` (`persona` type → `functional`; all others map directly)
     - TC `persona` → TS `personas` list (map display name to persona slug: Henk → `henk-bakker`, Fatima → `fatima-el-amrani`, Sem → `sem-de-jong`, Noor → `noor-yilmaz`, Annemarie → `annemarie-de-vries`, Mark → `mark-visser`, Priya → `priya-ganpat`, Jan-Willem → `janwillem-van-der-berg`; omit if `—`)
     - TC `preconditions` → TS Preconditions section + GIVEN line
     - TC `steps` → WHEN line(s) in Scenario
     - TC `expected result` → THEN line(s) in Scenario
     - TC `test command` → TS `test-commands` list
     - TC `spec_ref` → TS `spec-refs` list
     - Priority: default `medium`; use `high` if the TC is compliance-critical (AVG/GDPR, authorisation, security) or if the spec_ref points to a High-severity requirement
     - Goal: derive from TC title (e.g., "TC-4: Admin can view audit log" → "Verify admin can access and view the audit log")
   - **Generate slug** from title: lowercase, hyphens, no special chars
   - **Write** `<APP>/test-scenarios/TS-NNN-<slug>.md` using the standard scenario file format:

   Use the format defined in [templates/test-scenario-template.md](templates/test-scenario-template.md).

   e. Report: "Created N test scenario file(s): TS-NNN, TS-NNN+1, …"

5. **Perform the archive**

   Create the archive directory if it doesn't exist:
   ```bash
   mkdir -p openspec/changes/archive
   ```

   Generate target name using current date: `YYYY-MM-DD-<change-name>`

   **Check if target already exists:**
   - If yes: Fail with error, suggest renaming existing archive or using different date
   - If no: Move the change directory to archive

   ```bash
   mv openspec/changes/<name> openspec/changes/archive/YYYY-MM-DD-<name>
   ```

5.5. **Update spec links in main specs**

   After moving the change to archive, update any spec that references this change in its `**OpenSpec changes**` list:

   a. Search all spec files for links pointing to `../../changes/<name>/` (or `changes/<name>/`):
      ```bash
      grep -rl "changes/<name>/" openspec/specs/
      ```

   b. For each matching spec file:
      - Replace the active link `[<name>](../../changes/<name>/)` with the archived link `[<name>](../../changes/archive/YYYY-MM-DD-<name>/) _(archived YYYY-MM-DD)_`
      - If this was the **last active change** for that spec (all other entries in `**OpenSpec changes**` are also archived), update the spec's `**Status**` field to `done`
      - If the list now exceeds 15 entries, apply the grouping rule from [writing-specs.md](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-specs.md) (group by timeframe, oldest first, never remove entries)

   c. If a spec exists for a capability defined in this change's `## Capabilities` section but the spec does NOT yet have this change in its `**OpenSpec changes**` list — add it as an archived entry now.

   d. Also check the shared specs at `.claude/openspec/specs/` for any cross-app capabilities this change touched.

6. **Update feature documentation**

   If a `docs/features/README.md` exists in the project root, update the corresponding feature doc:

   a. Read the **Spec-to-Feature Mapping** section from `docs/features/README.md` to find which feature doc corresponds to the change name (or the delta spec names).

   b. Identify the matching feature doc file. A change may map to a feature doc in two ways:
      - The change name directly matches a spec name in the mapping (e.g., change `lead-management` → `lead-management.md`)
      - The change's delta specs match spec names in the mapping

   c. If a matching feature doc is found:
      - Read the current feature doc
      - Read the main spec(s) that were synced (from `openspec/specs/<spec-name>/spec.md`)
      - Update the feature doc to reflect any new, changed, or removed features from the spec
      - Preserve the document structure (heading hierarchy, Specs section, Features section, Planned sections)
      - Move features from "Planned" sections to implemented sections if the spec now marks them as done
      - Add new features that appear in the updated spec
      - Keep the writing style consistent: short descriptions, bullet points for sub-features

   d. If no matching feature doc is found, **create one** at `docs/features/<change-name>.md` with:
      - Feature title and one-line summary
      - Standards references (GEMMA referentiecomponent URL, TEC RFP section, Forum Standaardisatie standards if applicable)
      - Overview section describing the feature
      - Key capabilities as bullet points (from the spec requirements)

   e. **Update the feature overview table** in `docs/features/README.md`:
      - Add a row for the new/updated feature in the Features table
      - Each row must have: Feature name, short summary (max 1 line), Standards column (GEMMA/TEC/ZGW references), link to feature doc
      - If `docs/features/README.md` doesn't exist, create it with:
        - App name and description
        - Standards Compliance table (GEMMA components, TEC sections, Forum Standaardisatie standards)
        - Features table with all implemented features

   **Standards references to include where applicable:**
   - GEMMA referentiecomponent URL (from `gemmaonline.nl`)
   - TEC RFP template section numbers
   - ZGW API standard references
   - Forum Standaardisatie 'Pas toe of leg uit' standards
   - ISO/NEN standards (27001, 15489, etc.)

6.5. **Update CHANGELOG.md**

   Create or update `CHANGELOG.md` in the project root.

   a. **Determine the version**:
      - Read `openspec/app-config.json` → use `version` field
      - Fallback: read `appinfo/info.xml` → use `<version>` element
      - If neither exists, use `Unreleased`

   b. **Gather entry content**:
      - Read the completed tasks from `tasks.md` — items marked `- [x]` become bullet points (strip the `[x]` prefix, keep the description)
      - Read the change title/summary from `.openspec.yaml` or `proposal.md` if available (use as a comment or section intro)
      - If no tasks file exists, use the change name as a single entry line

   c. **Categorize tasks** using [Keep a Changelog](https://keepachangelog.com/) categories:
      - **Added** — new features or capabilities
      - **Changed** — changes to existing functionality
      - **Fixed** — bug fixes
      - **Removed** — removed features
      - **Security** — security fixes

      If a task description clearly fits a category, group it there. If uncertain, place it under **Added**.

   d. **Format the new entry**:
      ```markdown
      ## [<version>] - YYYY-MM-DD

      ### Added
      - <task description>
      - <task description>

      ### Fixed
      - <task description>
      ```

   e. **If CHANGELOG.md already exists**:
      - Read the current content
      - Check if a `## [<version>]` entry already exists (exact version match)
      - **If yes**: append the new items into the existing entry's sections (merge, no duplicates)
      - **If no**: insert the new entry at the top — immediately after the `# Changelog` header line (or at line 1 if no header)

   f. **If CHANGELOG.md does not exist**: create it:
      ```markdown
      # Changelog

      All notable changes to this project will be documented in this file.

      The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
      and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

      ## [<version>] - YYYY-MM-DD

      ### Added
      - <item>
      ```

7. **Close GitHub issue** (if plan.json exists)

   Read `openspec/changes/<name>/plan.json` (from its new archive location).

   If `tracking_issue` is set, use **AskUserQuestion** to ask:

   > "Close GitHub issue #<tracking_issue>?"
   >
   > ℹ️ **Future feature:** In a planned update, issues will auto-close when the associated PR is merged, managed via a GitHub Project board (to do → refinement → ready for development → in progress → code quality check → code security check → review → done). For now, you can close it manually here.

   Options:
   - **Yes, close the issue** — close with archive comment
   - **No, leave it open** — skip closing (e.g., if a PR will handle it)

   **If user chooses to close:**
   - **MCP (preferred):** GitHub MCP `update_issue` → `{owner, repo, issue_number: <tracking_issue>, state: "closed"}`, then `add_issue_comment` → `{owner, repo, issue_number: <tracking_issue>, body: "✓ Change archived: openspec/changes/archive/YYYY-MM-DD-<name>/"}`
   - **CLI (fallback):** `gh issue close <tracking_issue> --repo <repo> --comment "✓ Change archived: openspec/changes/archive/YYYY-MM-DD-<name>/"`

8. **Display summary and ask what's next**

   Show archive completion summary including:
   - Change name
   - Schema that was used
   - Archive location
   - Spec sync status (synced / sync skipped / no delta specs)
   - GitHub issue closed or left open (if plan.json existed)
   - Note about any warnings (incomplete artifacts/tasks)

   Then use **AskUserQuestion** to ask:

   > "Change archived! What would you like to do next?"

   Options:
   - **Sync dev docs** (`/sync-docs dev`) — update `.claude/docs/` to reflect current commands and conventions (recommended after any workflow change)
   - **Start a new change** (`/opsx-new`) — begin working on the next feature or fix
   - **Explore ideas first** (`/opsx-explore`) — think through what to build next before committing
   - **Run feature counsel** (`/feature-counsel`) — get multi-persona analysis of what to build next
   - **Done for now** — end the session

For all output formats, see [examples/output-templates.md](examples/output-templates.md).

**What's Next**

The change is archived. Start your next change with:
- `/opsx-new` — start a new change
- `/opsx-explore` — explore ideas before starting

**Capture Learnings**

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

**Guardrails**
- Always prompt for change selection if not provided
- Use artifact graph (openspec status --json) for completion checking
- Don't block archive on warnings - just inform and confirm
- Preserve .openspec.yaml when moving to archive (it moves with the directory)
- Show clear summary of what happened
- If sync is requested, use /opsx-sync approach (agent-driven)
- If delta specs exist, always run the sync assessment and show the combined summary before prompting
