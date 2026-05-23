---
name: opsx-verify
description: Verify implementation matches change artifacts before archiving
metadata:
  category: Workflow
  tags: [workflow, verify, experimental]
---

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — verifying implementation against specs and running tests needs stronger reasoning than Haiku can reliably provide. Please switch to Sonnet (`/model sonnet`) or Opus (`/model opus`) and re-run."
- **On Sonnet or Opus**: proceed normally.

---

Verify that an implementation matches the change artifacts (specs, tasks, design).

## Input modes

- **Per-change** (default) — `/opsx-verify <change-name>` or `/opsx-verify` (prompts). Verifies a single OpenSpec change against its artifacts. This is the mode used during normal feature work, before archiving.
- **Per-app retrofit DoD** — `/opsx-verify --app <slug>`. Runs the retrofit "definition of done" check across an entire retrofitted app. Walks all retrofit ghost changes under `{app}/openspec/changes/archive/retrofit-*`, scans for dangling `@spec` paths, audits cohort frontmatter, etc. Required by the [retrofit playbook](../../../../.github/docs/claude/retrofit.md) "When the retrofit is done" section.

**Mode dispatch:**

- If the input starts with `--app `, follow the **App Mode** steps in §A1–A6 below; **skip the per-change Steps 1–11**.
- Otherwise follow the per-change Steps 1–11 below.
- Disambiguation: if the user passes a single token that matches an app slug (e.g. `openregister`), ask once via AskUserQuestion whether they meant `--app openregister` (retrofit DoD) or the change literally named `openregister` (likely doesn't exist). Default to `--app` for the canonical Nextcloud app slugs (`openregister`, `procest`, `pipelinq`, `decidesk`, `docudesk`, `openconnector`, `nldesign`, `mydash`, `softwarecatalog`, `larpingapp`, `zaakafhandelapp`, `opencatalogi`).

---

## App Mode (`--app <slug>`)

Designed to satisfy the playbook's "When the retrofit is done" checklist mechanically, without depending on `openspec status` (which only knows active changes — archived retrofits would be invisible to it).

### A1. Verify app prereqs

- App exists at `<workspace>/{slug}/`
- `{slug}/openspec/` is a directory
- Working tree state may be dirty — App Mode is **read-only** (writes nothing, never modifies code)

### A2. Enumerate retrofit ghost changes

```bash
find {app}/openspec/changes -maxdepth 3 -type d -name 'retrofit-*' | sort
```

Include both `{app}/openspec/changes/retrofit-*` (active — should normally be empty after archive) and `{app}/openspec/changes/archive/retrofit-*`. Anything matching is a "retrofit ghost change" for the report.

### A3. Per-retrofit structural check

For each retrofit folder `R`:

| Check | Expected | Failure level |
|---|---|---|
| `R/proposal.md` exists | always | CRITICAL |
| `R/tasks.md` exists | always | CRITICAL |
| All tasks `[x]` | always (retrofit convention — code already exists) | CRITICAL |
| `R/design.md` exists | reverse-spec runs (cluster/extend) only — annotate runs and cross-ref ghosts are exempt | WARNING for missing on reverse-spec; not flagged otherwise |
| `R/specs/{cap}/spec.md` exists | reverse-spec runs only | WARNING for missing on reverse-spec; not flagged otherwise |
| `@spec openspec/changes/{R-basename}` count in `lib/` + `src/` | annotate runs: large (≥ 50). Reverse-spec runs: ≥ 1 method per task. Private-helper inheritance retrofits: 0 is intentional — read `R/proposal.md` first to confirm | WARNING if 0 on a non-inheritance retrofit |

**Annotate-vs-reverse-spec detection:** if `R` matches `retrofit-*-annotate-*`, treat as annotate. Otherwise treat as reverse-spec unless `R/proposal.md` explicitly says "cross-ref" or "private helper".

### A4. App-level aggregate checks

1. **Dangling `@spec` scan**: any `@spec openspec/changes/<X>` reference in `lib/`/`src/` whose `<X>` doesn't resolve to a folder in either `{app}/openspec/changes/` or `{app}/openspec/changes/archive/`.
   ```bash
   grep -roh "@spec openspec/changes/[a-z0-9-]*" {app}/lib/ {app}/src/ \
     --include="*.php" --include="*.js" --include="*.ts" --include="*.vue" 2>/dev/null \
     | sed 's|^@spec openspec/changes/||' | sort -u | while read change; do
       [ -d "{app}/openspec/changes/$change" ] || [ -d "{app}/openspec/changes/archive/$change" ] || echo "DANGLING: $change"
     done
   ```
   Any output is CRITICAL.

2. **Symlink scan**: any symlink under `{app}/openspec/changes/` is an anti-pattern (legacy of the `2026-05-01-retrofit-X-2026-05-01` half-archive workflow).
   ```bash
   find {app}/openspec/changes -maxdepth 1 -type l
   ```
   Any output is CRITICAL.

3. **Naming convention**: every retrofit folder must match `retrofit-{YYYY-MM-DD}-{descriptor}` (date right after `retrofit-`).
   ```bash
   ls {app}/openspec/changes/archive/ | grep -E '^retrofit-' \
     | grep -vE '^retrofit-[0-9]{4}-[0-9]{2}-[0-9]{2}-[a-z][a-z0-9-]*$'
   ```
   Any output is CRITICAL — usually means a retrofit was created before the convention switched (e.g. `retrofit-{descriptor}-{date}` order or the redundant `2026-05-01-retrofit-X-2026-05-01` form). Rename via `git mv` and update text references.

4. **Cohort frontmatter coverage**: every capability that received **new retrofit-derived REQs** must carry the cohort flag on its master spec. Documentation-only retrofits do NOT require frontmatter — the cohort flag is for tracking REQ provenance, not annotation provenance.

   **Step 1: classify each retrofit ghost change.** Walk every `R` and decide:
   - **REQ-adding retrofit** — has `R/specs/{cap}/spec.md` with at least one `### REQ-NNN:` heading. The capability `{cap}` enters the cohort and MUST carry frontmatter.
   - **Documentation-only retrofit** — no `specs/` delta, OR the `proposal.md` explicitly says one of: *"no new REQs"*, *"no new REQs needed"*, *"no new REQs drafted"*, *"no new REQs required"*, *"behaviors are fully covered"*. Examples: cross-capability annotation patches (b2b-crossrefs), private-helper inheritance retrofits (schema-hooks), scanner-misclassification cleanups (tenant-isolation-audit). These do NOT require cohort frontmatter on any capability.
   - **Annotate retrofit** — `retrofit-{date}-annotate-{app}`. Never adds REQs; never requires frontmatter.

   **Step 2: build the cohort set.** Union of `{cap}` values from REQ-adding retrofits only.

   **Step 3: verify each cohort capability** has `retrofit: true` (cluster) or `retrofit_extensions: [...]` (extend) in `{app}/openspec/specs/{cap}/spec.md` frontmatter.

   **Step 4: format check.** `retrofit_extensions` MUST be block YAML with bare REQ-IDs (per `/opsx-reverse-spec` SKILL.md Step 8). Inline `[REQ-005]` or quoted `["REQ-005"]` is WARNING; full requirement-text values are CRITICAL.

   Missing cohort flag on a REQ-adding capability is CRITICAL — `sync_spec_content.py` won't tag the capability as retrofit cohort in Specter. Missing cohort flag on a documentation-only capability is **expected** and not a finding.

5. **Coverage report freshness** (informational only): if `{app}/openspec/coverage-report.json` exists, compare its `generated_at` timestamp to the most recent retrofit ghost change date. Stale report ≠ failure, but worth reporting.

### A5. Generate app-level report

```markdown
## Retrofit Verify: {app}

### App-level checks
| Check | Status | Detail |
|---|:-:|---|
| Retrofit ghost changes | ✅ N found, M archived | Newest: <date> |
| Tasks completion | ✅ all [x] / ⚠️ N incomplete | |
| Dangling @spec paths | ✅ 0 / ❌ N | <list> |
| Symlinks under changes/ | ✅ 0 / ❌ N | <list> |
| Naming convention | ✅ N/N / ❌ N malformed | <list> |
| Cohort frontmatter | ✅ K/K / 🟡 K/K (missing: <caps>) | |
| Frontmatter format | ✅ block YAML / ⚠️ N inline / ❌ N full-text | |

### Per-retrofit details
(table with proposal/tasks/design/spec-delta/tasks-done/@spec-count per retrofit)

### Verdict
- ✅ **Retrofit complete** — all checks pass
- 🟡 **Retrofit partial** — only WARNINGs and informational items
- ❌ **Retrofit incomplete** — CRITICAL items remain (list them)
```

### A6. Final assessment

- ✅ **All clear**: state "Retrofit DoD passes for `{app}`. Safe to mark the playbook checklist complete."
- 🟡 **Partial**: list each gap with the exact remediation:
  - Cohort frontmatter missing → add `retrofit:` / `retrofit_extensions:` to `{app}/openspec/specs/{cap}/spec.md` and re-run `python3 concurrentie-analyse/scripts/sync_spec_content.py {app}`.
  - Naming-convention violation → `git mv` to canonical form + update text references.
  - Dangling `@spec` → either restore the missing change folder, or update the dangling annotations to point at an existing change.
- ❌ **Failed**: stop with a clear reason. Do NOT mark playbook checklist items as complete.

App Mode does not invoke `/opsx-archive` and does not propose fixes interactively — it is a read-only DoD audit.

---

## Per-change mode

The remaining steps describe the original per-change verify. Skipped when `--app` is specified.

**Steps**

1. **If no change name provided, prompt for selection**

   Run `openspec list --json` to get available changes. Use the **AskUserQuestion tool** to let the user select.

   Show changes that have implementation tasks (tasks artifact exists).
   Include the schema used for each change if available.
   Mark changes with incomplete tasks as "(In Progress)".

   **IMPORTANT**: Do NOT guess or auto-select a change. Always let the user choose.

2. **Check status to understand the schema**
   ```bash
   openspec status --change "<name>" --json
   ```
   Parse the JSON to understand:
   - `schemaName`: The workflow being used (e.g., "spec-driven")
   - Which artifacts exist for this change

3. **Get the change directory and load artifacts**

   ```bash
   openspec instructions apply --change "<name>" --json
   ```

   This returns the change directory and context files. Read all available artifacts from `contextFiles`.

   **Additionally, load optional artifacts if present:**
   - `openspec/changes/<name>/test-plan.md` — pre-defined test cases mapped to spec scenarios; use as the primary oracle for scenario coverage and testing
   - `openspec/changes/<name>/contract.md` — formal API contract; if present, it is the authoritative interface definition and takes precedence over design.md for API verification

4. **Initialize verification report structure**

   Create a report structure with three dimensions:
   - **Completeness**: Track tasks and spec coverage
   - **Correctness**: Track requirement implementation and scenario coverage
   - **Coherence**: Track design adherence and pattern consistency

   Each dimension can have CRITICAL, WARNING, or SUGGESTION issues.

5. **Verify Completeness**

   **Task Completion**:
   - If tasks.md exists in contextFiles, read it
   - Parse checkboxes: `- [ ]` (incomplete) vs `- [x]` (complete)
   - Count complete vs total tasks
   - If incomplete tasks exist:
     - Add CRITICAL issue for each incomplete task
     - Recommendation: "Complete task: <description>" or "Mark as done if already implemented"
   - **Sync already-complete tasks to GitHub** (only if plan.json exists): For every task that is already `[x]` in tasks.md but whose `plan.json` status is not `"done"`, treat it as just-completed and run the full GitHub sync below. If plan.json does not exist, skip all GitHub sync steps silently.
   - If browser or API tests (step 8) verify that acceptance criteria for an incomplete task are met:
     - Mark those criteria as `[x]` in tasks.md
     - If ALL criteria of that task are now checked, mark the task itself as `[x]` in tasks.md
   - **For every task marked `[x]` in tasks.md** (whether already complete before this run, or just completed above), if plan.json exists and that task's `status` in plan.json is not `"done"`:
     - **Check off this task and ALL its sub-checkboxes in the tracking issue body**:
       - Fetch the issue body once (batch all task updates before writing back)
       - For each task to check off: find the parent task line by matching its title (e.g., `- [ ] **1.1 Task title**`), change it to `- [x]`; then scan every immediately following line — for each line starting with `  - [ ]` (2-space indent), change it to `  - [x]`; stop scanning at any line that is NOT an indented sub-checkbox (blank line, new parent checkbox, section header, etc.)
       - **MCP (preferred):** `get_issue` → `{owner, repo, issue_number: <tracking_issue>}` → apply the above changes for all tasks → `update_issue` → `{owner, repo, issue_number: <tracking_issue>, body: <updated_body>}`
       - **CLI (fallback):** `gh issue view <tracking_issue> --repo <repo> --json body --jq '.body'` → apply the above changes for all tasks → `gh issue edit <tracking_issue> --repo <repo> --body "<updated_body>"`
       - **IMPORTANT**: Batch all updates into a single `update_issue` call — fetch the body once, apply all checkbox changes, then write it back once.
     - Update `plan.json`: set `"status": "done"` for that task
     - **Do NOT close the issue** — the issue will be closed when the PR is merged or during archive

   **Spec Coverage**:
   - If delta specs exist in `openspec/changes/<name>/specs/`:
     - Extract all requirements (marked with "### Requirement:")
     - For each requirement:
       - Search codebase for keywords related to the requirement
       - Assess if implementation likely exists
     - If requirements appear unimplemented:
       - Add CRITICAL issue: "Requirement not found: <requirement name>"
       - Recommendation: "Implement requirement X: <description>"

6. **Verify Correctness**

   **Requirement Implementation Mapping**:
   - For each requirement from delta specs:
     - Search codebase for implementation evidence
     - If found, note file paths and line ranges
     - Assess if implementation matches requirement intent
     - If divergence detected:
       - Add WARNING: "Implementation may diverge from spec: <details>"
       - Recommendation: "Review <file>:<lines> against requirement X"

   **Scenario Coverage**:
   - **If test-plan.md is loaded**: use the TCs as the canonical scenario checklist. For each TC:
     - Verify the acceptance criteria are met in the implementation
     - Note the TC's `test command` field — use it in step 8 to run the right test type
     - If a TC's expected result appears unmet: Add WARNING: "TC not satisfied: TC-N <title>"
   - **If no test-plan.md**: fall back to scanning spec scenarios directly:
     - For each scenario in delta specs (marked with "#### Scenario:"):
       - Check if conditions are handled in code
       - If scenario appears uncovered: Add WARNING: "Scenario not covered: <scenario name>"

7. **Verify Coherence**

   **Contract Adherence** (checked first if contract.md exists):
   - If contract.md is loaded: it is the authoritative interface definition — verify against it before design.md
     - For each declared endpoint: verify it exists in code with the correct method, path, and auth requirement
     - For each schema: verify request/response fields match the contract
     - For each error code: verify the declared HTTP status and condition are implemented
     - If an endpoint, schema field, or error code is missing or diverges:
       - Add CRITICAL: "Contract violation: <endpoint/schema/field> does not match contract.md"
       - Recommendation: "Implement contract as specified — contract is the cross-team interface agreement"

   **Design Adherence**:
   - If design.md exists in contextFiles:
     - Extract key decisions (look for sections like "Decision:", "Approach:", "Architecture:")
     - Verify implementation follows those decisions
     - If contradiction detected:
       - Add WARNING: "Design decision not followed: <decision>"
       - Recommendation: "Update implementation or revise design.md to match reality"
   - If neither contract.md nor design.md: Skip coherence check, note "No contract.md or design.md to verify against"

   **Code Pattern Consistency**:
   - Review new code for consistency with project patterns
   - Check file naming, directory structure, coding style
   - If significant deviations found:
     - Add SUGGESTION: "Code pattern deviation: <details>"
     - Recommendation: "Consider following project pattern: <example>"

   **Frontend Pattern Adherence** (run if the change touched any `.vue`/`.js`/`.ts` files in `src/`):

   These four checks mirror the mechanical gates 10–13 from `scripts/run-hydra-gates.sh`. Run them as part of verify so issues are caught at archive time even when the gate run was skipped or the change predates the gates. Each is a CRITICAL finding when violated — they map to ADR-004 hard rules.

   1. **Initial state, not DOM** (mirrors gate-10):
      ```bash
      grep -rnE "getElementById\\s*\\([^)]+\\)[^.]*\\.dataset\\b" src/ \
          --include='*.vue' --include='*.js' --include='*.ts' 2>/dev/null
      ```
      - If hits: Add CRITICAL: "DOM dataset read at <file>:<line> — server-side data must use `IInitialState::provideInitialState()` + `loadState()` from `@nextcloud/initial-state`"

   2. **No admin in vue-router** (mirrors gate-11):
      ```bash
      for f in src/router/index.js src/router/index.ts src/router.js src/router.ts; do
          [ -f "$f" ] || continue
          grep -nE "from\\s+['\"][^'\"]*(/Admin[A-Z][A-Za-z]*\\.vue|views/settings/)" "$f"
          grep -nE "path\\s*:\\s*['\"]/(settings|admin)\\b" "$f"
      done
      ```
      - If hits: Add CRITICAL: "Admin settings component routed at <file>:<line> — security regression. Admin settings must be registered via `AdminSettings.php` only, never as a vue-router route"

   3. **NcSelect labels** (mirrors gate-12):
      ```bash
      find src -name '*.vue' | while read v; do
          tr '\n' ' ' < "$v" | grep -oE '<NcSelect[^>]*>' \
              | grep -vE '(input-label|inputLabel|aria-label-combobox|ariaLabelCombobox)' \
              | sed "s|^|$v: |"
      done
      ```
      - If hits: Add CRITICAL: "NcSelect without `inputLabel`/`ariaLabelCombobox` at <file> — breaks WCAG 1.3.1 / 4.1.2; remove any manual `<label>` and use the built-in prop"

   4. **Modal/dialog file isolation** (mirrors gate-13):
      ```bash
      find src -name '*.vue' | grep -vE '^src/(modals|dialogs)/' | while read v; do
          grep -lE '<NcModal[ \t>/]|<NcDialog[ \t>/]' "$v" 2>/dev/null
      done
      ```
      - If hits: Add CRITICAL: "Inline modal/dialog at <file> — extract to `src/modals/<Name>.vue` (NcModal) or `src/dialogs/<Name>.vue` (NcDialog) and import in the parent"

   **Test Coverage**:
   - For each new PHP service/controller file, check if a corresponding test file exists in `tests/Unit/` or `tests/unit/`
   - For each new Vue component, check if a test file exists (if project has Jest/Vitest)
   - If a new service has NO test:
     - Add CRITICAL: "Missing unit test for <ServiceName>"
     - Recommendation: "Create tests/Unit/Service/<ServiceName>Test.php with at least 3 test methods"
   - If tests exist but cover fewer than 3 methods:
     - Add WARNING: "Insufficient test coverage for <ServiceName>"

   **Documentation**:
   - Check if the PR updates README.md or docs/ with new feature description
   - Check if new API endpoints are documented
   - If no documentation found:
     - Add WARNING: "No documentation for new feature"
     - Recommendation: "Add feature description to README.md and document new API endpoints"

8. **Ask about API and browser testing**

   After the code-level verification, use **AskUserQuestion** to ask:
   "Would you also like to run API and/or browser tests against the specs and implementation?"

   Options:
   - **Both API and browser tests** — Run API tests first, then browser tests
   - **API tests only** — Test API endpoints against spec requirements
   - **Browser tests only** — Test UI behavior against spec scenarios
   - **Skip testing** — Continue with code-level findings only

   **If API testing selected:**

   a. **Discover endpoints** — Read `{app}/appinfo/routes.php` to find endpoints affected by this change. Cross-reference with the specs to identify which endpoints should exist.

   b. **Test CRUD operations** — For each affected resource endpoint, test with curl:
   ```bash
   # CREATE
   curl -s -u admin:admin -X POST -H "Content-Type: application/json" \
     -d '{"name":"Verify Test"}' http://nextcloud.local/index.php/apps/{app}/api/{resource}
   # Returns 201 with created object including id

   # READ
   curl -s -u admin:admin http://nextcloud.local/index.php/apps/{app}/api/{resource}/{id}
   # Returns 200 with full object; 404 for non-existent

   # LIST
   curl -s -u admin:admin http://nextcloud.local/index.php/apps/{app}/api/{resource}
   # Returns 200 with array and pagination metadata

   # UPDATE
   curl -s -u admin:admin -X PUT -H "Content-Type: application/json" \
     -d '{"name":"Updated"}' http://nextcloud.local/index.php/apps/{app}/api/{resource}/{id}

   # DELETE
   curl -s -u admin:admin -X DELETE http://nextcloud.local/index.php/apps/{app}/api/{resource}/{id}
   ```

   c. **Verify against spec scenarios** — For each GIVEN/WHEN/THEN scenario in the specs, craft a curl request that exercises it. Check response codes, payloads, and error messages match expectations.

   d. **NLGov compliance spot-check** — Verify the basics:
   - URLs use lowercase plural nouns with hyphens
   - Collections include pagination metadata (`total`, `page`, `pages`)
   - Error responses include `message` or `detail` field with proper HTTP status
   - `Content-Type: application/json` on all responses

   e. **Add findings** as CRITICAL (endpoint broken/missing), WARNING (non-compliant), or SUGGESTION (improvement).

   **If browser testing selected:**

   a. **Set up browser session** — Use `browser-1` tools (`mcp__browser-1__*`):
   ```
   1. browser_resize → width: 1920, height: 1080
   2. browser_navigate → http://nextcloud.local/index.php/apps/{app}
   3. If redirected to login:
      - browser_fill_form with username: admin, password: admin
      - Submit the form
   4. browser_snapshot → confirm app loaded
   ```

   b. **Test spec scenarios via browser** — For each GIVEN/WHEN/THEN scenario from the specs:
   - **GIVEN**: Navigate to the correct page, verify precondition state
   - **WHEN**: Perform the action using `browser_click`, `browser_type`, `browser_fill_form`
   - **THEN**: `browser_snapshot` to verify expected outcome, `browser_take_screenshot` with filename: `test-results/verify/{change-name}-{scenario-slug}.png`

   c. **Monitor for errors** during testing:
   - `browser_console_messages` (level: "error") after each action
   - `browser_network_requests` to catch failed API calls (4xx/5xx)

   d. **Test core flows** relevant to the change:
   - CRUD: Create → verify in list → update → verify change → delete → verify removed
   - Navigation: sidebar links, back/forward, deep linking
   - Forms: required field validation, success feedback, cancel behavior
   - Loading/error states: indicators, empty states, error messages

   e. **Add findings** with screenshot evidence. CRITICAL for broken flows, WARNING for degraded UX, SUGGESTION for polish.

9. **Generate Verification Report**

   **Summary Scorecard**:
   ```
   ## Verification Report: <change-name>

   ### Summary
   | Dimension    | Status           |
   |--------------|------------------|
   | Completeness | X/Y tasks, N reqs|
   | Correctness  | M/N reqs covered |
   | Coherence    | Followed/Issues  |
   | API Tests    | Passed/Failed/Skipped |
   | Browser Tests| Passed/Failed/Skipped |
   ```

   **Issues by Priority**:

   1. **CRITICAL** (Must fix before archive):
      - Incomplete tasks
      - Missing requirement implementations
      - Failed API/browser tests
      - Each with specific, actionable recommendation

   2. **WARNING** (Should fix):
      - Spec/design divergences
      - Missing scenario coverage
      - Each with specific recommendation

   3. **SUGGESTION** (Nice to fix):
      - Pattern inconsistencies
      - Minor improvements
      - Each with specific recommendation

10. **Fix loop — resolve issues and re-verify**

   **If CRITICAL or WARNING issues found:**
   - Display the full report
   - Use **AskUserQuestion** to ask: "Found issues. Would you like me to fix them?"
     - **Yes, fix all issues** — Fix all CRITICAL and WARNING issues
     - **Yes, fix critical only** — Fix only CRITICAL issues
     - **No, leave as-is** — Skip fixing, proceed to final assessment

   **If fixing:**
   - Work through each issue, making the necessary code changes
   - After all fixes are applied, **re-run verification from step 5** (skip steps 1-4, reuse loaded context)
   - Show updated report with resolved issues marked
   - If new issues are found during re-verify, repeat this fix loop
   - Continue looping until no CRITICAL/WARNING issues remain or the user chooses to stop

11. **Final assessment and archive prompt**

   **FIRST: Re-check task completion** — regardless of other findings, re-read tasks.md and count `- [ ]` items:
   - If ANY tasks are still `- [ ]`: **do NOT offer archive**. Show:
     ```
     ⚠️ N task(s) still incomplete — archive is blocked until all tasks are done:
     - Task X: <description> (incomplete criteria: ...)
     ```
     End the session without offering archive.

   **If all tasks `[x]` AND CRITICAL issues remain (user chose not to fix):**
   - "X critical issue(s) remain. Recommend fixing before archiving."
   - Do NOT prompt for archive

   **If all tasks `[x]` AND only SUGGESTION issues or all clear:**
   - Display: "All checks passed. Implementation matches specs."
   - If plan.json exists, update the pipeline progress comment on the issue (search for `## Pipeline Progress`, update via PATCH if found, create if not):
     ```markdown
     ## Pipeline Progress

     | Stage | Status | Details |
     |-------|--------|---------|
     | Implementation | ✓ Complete | All N tasks done |
     | Quality Checks | ✓ Pass | lint, phpcs, phpstan clean |
     | Verification | ✓ Pass | Completeness, correctness, coherence |
     | Archive | ready | |

     *Updated: YYYY-MM-DD HH:MM UTC*
     ```
   - Also add a brief comment:
     - **MCP (preferred):** GitHub MCP `add_issue_comment` → `{owner, repo, issue_number: <tracking_issue>, body: "✓ Verified by /opsx-verify — all checks passed"}`
     - **CLI (fallback):** `gh issue comment <tracking_issue> --repo <repo> --body "✓ Verified by /opsx-verify — all checks passed"`
   - Use **AskUserQuestion** to ask: "Ready to archive this change?"
     - **Yes, archive now** — Execute `/opsx-archive` for this change
     - **Sync specs first, then archive** — Execute `/opsx-sync` then `/opsx-archive`
     - **No, not yet** — End the session

   **If all tasks `[x]` AND only WARNING issues remain (user chose not to fix):**
   - "No critical issues. Y warning(s) noted."
   - Use **AskUserQuestion** to ask: "Archive this change with noted warnings?"
     - **Yes, archive with warnings** — Execute `/opsx-archive` for this change
     - **Sync specs first, then archive** — Execute `/opsx-sync` then `/opsx-archive`
     - **No, I'll fix them first** — End the session

**Verification Heuristics**

- **Completeness**: Focus on objective checklist items (checkboxes, requirements list)
- **Correctness**: Use keyword search, file path analysis, reasonable inference - don't require perfect certainty
- **Coherence**: Look for glaring inconsistencies, don't nitpick style
- **Testing**: Test against spec scenarios, not exhaustive edge cases
- **False Positives**: When uncertain, prefer SUGGESTION over WARNING, WARNING over CRITICAL
- **Actionability**: Every issue must have a specific recommendation with file/line references where applicable

**Graceful Degradation**

- If only tasks.md exists: verify task completion only, skip spec/design checks
- If tasks + specs exist: verify completeness and correctness, skip design
- If full artifacts: verify all three dimensions
- Always note which checks were skipped and why

**Fix Loop Behavior**

- Re-verification after fixes reuses the already-loaded context (no need to re-read artifacts)
- Only re-verify the dimensions that had issues (skip clean dimensions)
- Track which issues were resolved vs newly introduced
- Maximum 3 fix-verify cycles before suggesting the user take over manually

**Output Format**

Use clear markdown with:
- Table for summary scorecard
- Grouped lists for issues (CRITICAL/WARNING/SUGGESTION)
- Code references in format: `file.ts:123`
- Specific, actionable recommendations
- No vague suggestions like "consider reviewing"

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`) when done.
