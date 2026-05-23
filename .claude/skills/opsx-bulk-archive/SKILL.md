---
name: opsx-bulk-archive
description: Archive multiple completed changes at once
metadata:
  category: Workflow
  tags: [workflow, archive, experimental, bulk]
---

Archive multiple completed changes in a single operation.

This skill allows you to batch-archive changes, handling spec conflicts intelligently by checking the codebase to determine what's actually implemented.

**Input**: None required (prompts for selection)

**Steps**

1. **Get active changes**

   Run `openspec list --json` to get all active changes.

   If no active changes exist, inform user and stop.

2. **Prompt for change selection**

   Use **AskUserQuestion tool** with multi-select to let user choose changes:
   - Show each change with its schema
   - Include an option for "All changes"
   - Allow any number of selections (1+ works, 2+ is the typical use case)

   **IMPORTANT**: Do NOT auto-select. Always let the user choose.

3. **Batch validation - gather status for all selected changes**

   For each selected change, collect:

   a. **Artifact status** - Run `openspec status --change "<name>" --json`
      - Parse `schemaName` and `artifacts` list
      - Note which artifacts are `done` vs other states

   b. **Task completion** - Two checks in parallel:
      - Read `openspec/changes/<name>/tasks.md` — count `- [ ]` (incomplete) vs `- [x]` (complete)
      - If plan.json exists and has a `tracking_issue`, fetch the GitHub tracking issue body and count its `- [ ]` lines
      - If tasks.md says all done but the GitHub tracking issue has unchecked boxes, flag as a sync gap (treat as incomplete)
      - If no tasks file exists, note as "No tasks"

   c. **Delta specs** - Check `openspec/changes/<name>/specs/` directory
      - List which capability specs exist
      - For each, extract requirement names (lines matching `### Requirement: <name>`)

4. **Detect spec conflicts**

   Build a map of `capability -> [changes that touch it]`:

   ```
   auth -> [change-a, change-b]  <- CONFLICT (2+ changes)
   api  -> [change-c]            <- OK (only 1 change)
   ```

   A conflict exists when 2+ selected changes have delta specs for the same capability.

5. **Resolve conflicts agentically**

   **For each conflict**, investigate the codebase:

   a. **Read the delta specs** from each conflicting change to understand what each claims to add/modify

   b. **Search the codebase** for implementation evidence:
      - Look for code implementing requirements from each delta spec
      - Check for related files, functions, or tests

   c. **Determine resolution**:
      - If only one change is actually implemented -> sync that one's specs
      - If both implemented -> apply in chronological order (older first, newer overwrites)
      - If neither implemented -> skip spec sync, warn user

   d. **Record resolution** for each conflict:
      - Which change's specs to apply
      - In what order (if both)
      - Rationale (what was found in codebase)

6. **Show consolidated status table**

   Display a table summarizing all changes:

   ```
   | Change               | Artifacts | Tasks | Specs   | Conflicts | Status |
   |---------------------|-----------|-------|---------|-----------|--------|
   | schema-management   | Done      | 5/5   | 2 delta | None      | Ready  |
   | project-config      | Done      | 3/3   | 1 delta | None      | Ready  |
   | add-oauth           | Done      | 4/4   | 1 delta | auth (!)  | Ready* |
   | add-verify-skill    | 1 left    | 2/5   | None    | None      | Warn   |
   ```

   For conflicts, show the resolution:
   ```
   * Conflict resolution:
     - auth spec: Will apply add-oauth then add-jwt (both implemented, chronological order)
   ```

   For incomplete changes, show warnings:
   ```
   Warnings:
   - add-verify-skill: 1 incomplete artifact, 3 incomplete tasks
   ```

7. **Confirm batch operation**

   Use **AskUserQuestion tool** with a single confirmation.

   **If ALL selected changes are complete** (all tasks `[x]`, all artifacts done):
   - "Archive N changes?" with options:
     - "Archive all N changes (Recommended)"
     - "Cancel"

   **If SOME selected changes have incomplete tasks or artifacts:**
   - Show clearly which changes are blocked:
     ```
     ⛔ N change(s) have incomplete tasks and cannot be archived:
     - <change-name>: X task(s) still open
     ```
   - Options:
     - "Archive only M ready changes, skip incomplete (Recommended)"
     - "Archive all N changes including incomplete (override)"
     - "Cancel"
   - Default/recommended is always to skip incomplete — never archive incomplete changes without explicit user override.

8. **Execute archive for each confirmed change**

   Process changes in the determined order (respecting conflict resolution):

   a. **Sync specs** if delta specs exist:
      - Invoke `/opsx-sync` for an agent-driven intelligent merge
      - For conflicts, apply in resolved order
      - Track if sync was done

   b. **Perform the archive**:
      ```bash
      mkdir -p openspec/changes/archive
      mv openspec/changes/<name> openspec/changes/archive/YYYY-MM-DD-<name>
      ```

   b2. **Close GitHub issue** (if plan.json exists in the change):
      - Read `openspec/changes/archive/YYYY-MM-DD-<name>/plan.json`
      - Collect all `tracking_issue` numbers across the batch for the user prompt in step 8.5

   c. **Update feature documentation** (if `docs/features/README.md` exists):
      - Read the Spec-to-Feature Mapping from `docs/features/README.md`
      - Find the feature doc matching the change name or its delta spec names
      - If found, read the current feature doc and the synced main spec(s), then update the feature doc to reflect any new/changed/removed features
      - Preserve document structure (heading hierarchy, Specs section, Features section, Planned sections)
      - Skip silently if no matching feature doc found

   c2. **Update CHANGELOG.md**:
      - **Determine the version**: read `openspec/app-config.json` → `version` field; fallback to `appinfo/info.xml` → `<version>`; fallback to `Unreleased`
      - **Gather content**: completed tasks (`- [x]` items) from the change's `tasks.md`; change title/summary from `.openspec.yaml` or `proposal.md` if available
      - **Categorize** using [Keep a Changelog](https://keepachangelog.com/) categories: Added / Changed / Fixed / Removed / Security
      - **If CHANGELOG.md exists**: check for an existing `## [<version>]` entry — if found, merge new items into it; if not found, insert the new entry at the top (after `# Changelog` header)
      - **If CHANGELOG.md does not exist**: create it with standard Keep a Changelog header and the new entry
      - Across a bulk archive of N changes, all entries use the same version (resolved once before the loop). If multiple changes contribute to the same version entry, merge their items together under the appropriate categories.

   d. **Track outcome** for each change:
      - Success: archived successfully
      - Failed: error during archive (record error)
      - Skipped: user chose not to archive (if applicable)

8.5. **Ask about closing GitHub issues**

   If any archived changes had a `tracking_issue` in their plan.json, ask the user about closing them in a single prompt:

   Use **AskUserQuestion** to ask:

   > "Close GitHub issues for archived changes?"
   >
   > Issues: #<issue-1> (<change-1>), #<issue-2> (<change-2>), ...
   >
   > ℹ️ **Future feature:** In a planned update, issues will auto-close when the associated PR is merged, managed via a GitHub Project board (to do → refinement → ready for development → in progress → code quality check → code security check → review → done). For now, you can close them manually here.

   Options:
   - **Yes, close all issues** — close all with archive comments
   - **No, leave them open** — skip closing (e.g., if PRs will handle it)

   **If user chooses to close:**
   For each tracking issue:
   - **MCP (preferred):** GitHub MCP `update_issue` → `{owner, repo, issue_number: <tracking_issue>, state: "closed"}`, then `add_issue_comment` → `{..., body: "✓ Change archived"}`
   - **CLI (fallback):** `gh issue close <tracking_issue> --repo <repo> --comment "✓ Change archived"`

9. **Display summary**

   Show final results:

   ```
   ## Bulk Archive Complete

   Archived 3 changes:
   - schema-management-cli -> archive/2026-01-19-schema-management-cli/
   - project-config -> archive/2026-01-19-project-config/
   - add-oauth -> archive/2026-01-19-add-oauth/

   Skipped 1 change:
   - add-verify-skill (user chose not to archive incomplete)

   Spec sync summary:
   - 4 delta specs synced to main specs
   - 1 conflict resolved (auth: applied both in chronological order)
   ```

   If any failures:
   ```
   Failed 1 change:
   - some-change: Archive directory already exists
   ```

**Conflict Resolution Examples**

Example 1: Only one implemented
```
Conflict: specs/auth/spec.md touched by [add-oauth, add-jwt]

Checking add-oauth:
- Delta adds "OAuth Provider Integration" requirement
- Searching codebase... found src/auth/oauth.ts implementing OAuth flow

Checking add-jwt:
- Delta adds "JWT Token Handling" requirement
- Searching codebase... no JWT implementation found

Resolution: Only add-oauth is implemented. Will sync add-oauth specs only.
```

Example 2: Both implemented
```
Conflict: specs/api/spec.md touched by [add-rest-api, add-graphql]

Checking add-rest-api (created 2026-01-10):
- Delta adds "REST Endpoints" requirement
- Searching codebase... found src/api/rest.ts

Checking add-graphql (created 2026-01-15):
- Delta adds "GraphQL Schema" requirement
- Searching codebase... found src/api/graphql.ts

Resolution: Both implemented. Will apply add-rest-api specs first,
then add-graphql specs (chronological order, newer takes precedence).
```

**Output On Success**

```
## Bulk Archive Complete

Archived N changes:
- <change-1> -> archive/YYYY-MM-DD-<change-1>/
- <change-2> -> archive/YYYY-MM-DD-<change-2>/

Spec sync summary:
- N delta specs synced to main specs
- No conflicts (or: M conflicts resolved)

Feature docs updated:
- docs/features/<feature-file-1>.md
- docs/features/<feature-file-2>.md

Changelog: ✓ CHANGELOG.md updated (v<version>, N entries added)
```

**Output On Partial Success**

```
## Bulk Archive Complete (partial)

Archived N changes:
- <change-1> -> archive/YYYY-MM-DD-<change-1>/

Skipped M changes:
- <change-2> (user chose not to archive incomplete)

Failed K changes:
- <change-3>: Archive directory already exists
```

**Output When No Changes**

```
## No Changes to Archive

No active changes found. Use `/opsx-new` to create a new change.
```

**Guardrails**
- Allow any number of changes (1+ is fine, 2+ is the typical use case)
- Always prompt for selection, never auto-select
- Detect spec conflicts early and resolve by checking codebase
- When both changes are implemented, apply specs in chronological order
- Skip spec sync only when implementation is missing (warn user)
- Show clear per-change status before confirming
- Use single confirmation for entire batch
- Track and report all outcomes (success/skip/fail)
- Preserve .openspec.yaml when moving to archive
- Archive directory target uses current date: YYYY-MM-DD-<name>
- If archive target exists, fail that change but continue with others
