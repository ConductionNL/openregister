---
name: sub-agent-prompt-template
description: Sub-agent prompt template for the Hydra opsx-pipeline parallel implementation agents (Conduction Nextcloud apps)
type: reference
user-invocable: false
---

IMPORTANT: Do NOT ask questions. Execute immediately. Do NOT follow CLAUDE.md
workflow rules about asking clarifying questions. Resolve any warnings or issues
autonomously. If a quality check fails, fix the code and re-run. If a task is
unclear, make the best reasonable decision and continue.

You are processing an OpenSpec change through the full lifecycle. Work in the
worktree directory — do NOT touch the main working directory.

## Context
- App: <app-name>
- Change: <change-name>
- Worktree: /tmp/worktrees/<app>-<change-name>
- Branch: feature/<issue-number>/<change-name>
- GitHub repo: <owner/repo>
- Issue: #<issue-number>
- Browser: <browser-N or "none"> (if assigned, use mcp__browser-N__* tools for UI testing)
- Working directory: /tmp/worktrees/<app>-<change-name>

## Phase 1: Fast-Forward (generate artifacts)

cd /tmp/worktrees/<app>-<change-name>

Run the OpenSpec artifact generation:
1. Run `openspec status --change "<change-name>" --json` to check what artifacts exist
2. If only proposal.md exists, generate all artifacts:
   - Run `openspec instructions <artifact-id> --change "<change-name>" --json` for each
   - Read dependency artifacts before creating new ones
   - Create specs, design (with seed data section per ADR-001), and tasks
   - Include a seed data task when schemas are introduced/modified
3. After all artifacts are created, verify with `openspec status --change "<change-name>" --json`

## Phase 2: Plan to Issues

1. Parse tasks.md into plan.json
2. Determine labels: `openspec`, `<app-name>`, and one label per delta spec in `openspec/changes/<change-name>/specs/`
3. Create a single issue: "[OpenSpec] [<app-name>] <change-name>" with task checkboxes (including nested acceptance criteria)
4. Update plan.json with the `tracking_issue` number
5. Update the original change issue (#<issue-number>) with a link to the tracking issue

## Phase 3: Implement (Apply)

1. Read all context files (proposal, specs, design, tasks)
2. For each task in order:
   - Implement the code changes
   - Write PHPUnit tests for new PHP services (3+ test methods each)
   - Write Vue component tests if applicable
   - Update documentation (README.md or docs/)
   - Mark task as [x] in tasks.md
   - Update task checkbox (and nested acceptance criteria) in the single GitHub issue
   - Do NOT close the issue — it stays open until PR merge or archive
   - Commit after each task: "feat(<app>): <task-title> [#<issue>]"
3. After all tasks: run quality checks
   - PHP: `composer check:strict` (or phpcs + phpmd + psalm individually)
   - Frontend: `npm run lint` + `npm run stylelint`
   - Fix any failures (up to 3 cycles)

## Phase 4: Verify

1. Check task completion (all [x] in tasks.md)
2. Verify spec coverage (requirements → code mapping)
3. Check design adherence
4. Verify test coverage (every new service has tests)
5. Fix any CRITICAL or WARNING issues found
6. Re-verify after fixes

## Phase 4b: Browser Verify (if enabled)

> This phase runs only when {BROWSER_TESTING} is "all", or "ui-only" and this change
> touches frontend files. Skip entirely if {BROWSER_TESTING} is "none".

Use browser <browser-N> (assigned by the main agent).

1. **Navigate and authenticate**:
   ```
   mcp__browser-N__browser_navigate → http://nextcloud.local
   mcp__browser-N__browser_fill_form → username: admin, password: admin (if login page)
   mcp__browser-N__browser_navigate → http://nextcloud.local/index.php/apps/<app>
   ```

2. **Test spec scenarios**: For each GIVEN/WHEN/THEN in the specs:
   - GIVEN: Navigate to correct page, verify precondition
   - WHEN: Perform the action (click, type, fill form)
   - THEN: Take snapshot to verify outcome

3. **Take screenshots** as evidence (minimum: feature main view, a successful action, an error/empty state if applicable):
   ```
   mcp__browser-N__browser_take_screenshot
   ```

4. **Check for errors**:
   ```
   mcp__browser-N__browser_console_messages → level: error
   mcp__browser-N__browser_network_requests → check for 4xx/5xx
   ```

5. Fix any CRITICAL issues found during browser testing, re-verify after fixes.

Include browser verification results in the Phase 6 report.

## Phase 5: Archive & Feature Documentation

1. Sync delta specs to main specs if they exist
2. Move change to archive: openspec/changes/archive/YYYY-MM-DD-<change-name>
3. **Update feature documentation**:
   a. If `docs/features/README.md` exists in the project root:
      - Read the **Spec-to-Feature Mapping** section to find which feature doc maps to this change name or its delta spec names
      - If a matching feature doc is found: read it and the synced main spec(s), then update the feature doc to reflect new/changed/removed features. Preserve document structure (headings, Specs section, Features section, Planned sections). Move features from "Planned" to implemented where the spec now marks them done.
      - If no matching feature doc is found: create `docs/features/<change-name>.md` with feature title, one-line summary, standards references (GEMMA, TEC, Forum Standaardisatie if applicable), overview, and key capabilities from the spec requirements
   b. **Update the feature overview table** in `docs/features/README.md`:
      - Add/update a row for the feature (name, summary, Standards column with GEMMA/TEC/ZGW references, link to feature doc)
      - If `docs/features/README.md` doesn't exist, create it with app name, Standards Compliance table, and Features table
   c. Commit: `docs(<app>): feature documentation for <change-name> [#<issue>]`
4. Do NOT close the GitHub issue — the main agent will ask the user about closing after PR creation

## Phase 6: Push and report

1. Push the branch:
   ```bash
   cd /tmp/worktrees/<app>-<change-name>
   git push origin feature/<issue-number>/<change-name>
   ```
2. Report back with:
   - Total tasks completed
   - Quality check results
   - Verification status
   - Browser test results (Pass / Skipped with reason) and scenario count
   - Feature docs created or updated (file paths)
   - Branch name ready for PR
   - Any issues encountered

Do NOT create the PR — the main agent handles that after reviewing the results.
Do NOT add Co-Authored-By trailers to commit messages.
