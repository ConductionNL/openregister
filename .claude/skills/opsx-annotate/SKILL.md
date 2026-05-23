---
name: opsx-annotate
description: Apply Bucket 1 `@spec` tags from a coverage report — creates a ghost change and opens an annotation-only PR (Experimental)
metadata:
  category: Retrofit
  tags: [retrofit, annotate, experimental]
---

**Check the active model** from your system context.

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — applying spec annotations correctly across many files needs stronger reasoning than Haiku can reliably provide. Please switch to Sonnet or Opus and re-run."
- **On Sonnet or Opus**: proceed normally.

---

Apply `@spec openspec/changes/{change}/tasks.md#task-N` PHPDoc tags to the Bucket 1 entries from a coverage report. Creates **one ghost change** per run and points all annotations at its tasks. Produces an **annotation-only PR** — no logic changes, no refactors, no formatting cleanups.

Part of the [retrofit playbook](../../../../.github/docs/claude/retrofit.md). Run `/opsx-coverage-scan {app}` first.

## How ghost changes work

Legacy code doesn't have a change artifact to point at. This skill creates one:

- Name: `retrofit-{YYYY-MM-DD}-annotate-{app}`
- `proposal.md`: "Retrofit — annotate {N} existing methods against {M} REQs across {K} capabilities"
- `specs/` delta: **empty** (all REQs already exist in `openspec/specs/`)
- `tasks.md`: one task per REQ with Bucket 1 matches (format: `- [x] task-N: {capability}#{REQ-NNN} — {REQ title} (retroactive annotation)`)

The change is archived at the end of the run so it lands in `openspec/changes/archive/`. Tag paths remain valid because `@spec openspec/changes/...` is a textual reference, not a live lookup.

**Input**: `{app}` — app slug. Must have `openspec/coverage-report.json` < 24h old.

**Steps**

1. **Verify prereqs**

   - `{app}/openspec/coverage-report.json` exists and its `generated_at` field is < 24h old (use `generated_at`, not filesystem mtime). Missing/stale → stop, run `/opsx-coverage-scan {app}` first.
   - **`.opsx-ignore` is honored transitively** — this skill never re-reads `{app}/.opsx-ignore`; it trusts the coverage-scan to have already filtered Bucket 1. If the user adds entries to `.opsx-ignore` and expects them to take effect during annotation, they MUST re-run `/opsx-coverage-scan {app}` first.
   - **Annotation staleness check**: grep `lib/**/*.php` and `src/**/*.js` for `@spec openspec/changes/retrofit-*-annotate-{app}` tags. If any are found, extract the date suffix from the ghost change name (e.g., `retrofit-2026-04-23-annotate-{app}` → `2026-04-23`). If that date is **newer** than the coverage report's `generated_at` date, warn the user:
     > "⚠️ Found annotations from `retrofit-{date}-annotate-{app}` that post-date this coverage report (generated `{generated_at}`). The report may have been generated before those annotations were applied, so Bucket 1 could include already-annotated methods. Re-running `/opsx-coverage-scan {app}` is strongly recommended before proceeding."
     Show the count of already-annotated methods and proceed only if user confirms.
   - Working tree is clean (`git status --porcelain` empty). **Refuse dirty trees.**
   - Checkout the branch that has the specs (same branch used by the coverage scan — it's recorded in `coverage-report.json.branch`).
   - Create feature branch: `retrofit/annotate-{app}-{YYYY-MM-DD}`. If it exists, reuse it.

1.5. **Idempotent-rerun guard**

   Grep `lib/**/*.php` and `src/**/*.js` for `@spec openspec/changes/retrofit-*-annotate-` tags. Also list any `openspec/changes/archive/retrofit-*-annotate-{app}` directories on disk. If any tags or archived ghost changes are found, a prior run has already annotated this app.

   Display what was found:
   - **Tag sample**: which files already carry `retrofit-*-annotate-{app}` tags, and which ghost change names they reference
   - **Archive entries**: `openspec/changes/archive/retrofit-{date}-annotate-{app}/` directories present

   Ask via AskUserQuestion:
   - **Resume** — reuse the existing ghost change and skip already-annotated files
   - **Fresh run** — create a new dated ghost change; any method already carrying **any** `@spec openspec/changes/retrofit-*-annotate-{app}` tag (regardless of date) will be skipped — only unannotated methods from the current report are touched
   - **Cancel**

   Do not proceed silently — a second ghost change mints duplicate tasks and produces a confusing PR.

2. **Load the plan from JSON**

   Read `openspec/coverage-report.json`. Extract `buckets.bucket_1`.

   - **Skip `needs_review: true` entries.** Tell user how many were skipped.
   - **Skip entries already annotated.** The JSON's `annotated` bucket already separates them out, but double-check during the edit pass (defensive).

   Group Bucket 1 by `capability` → `req_id` → list of methods. This becomes the task layout.

3. **Announce the plan and confirm**

   ```
   ## Annotation Plan — {app}

   Ghost change: retrofit-{YYYY-MM-DD}-annotate-{app}
   Tasks to create: {M} (one per REQ with matches)
   Files to touch:  {F}
   Methods to tag:  {N}
   Capabilities:    {list}
   Skipped (NEEDS-REVIEW): {K}
   ```

   Use **AskUserQuestion**:
   - **Proceed** — create ghost change + annotate
   - **Review first** — print the full file list
   - **Cancel**

4. **Bootstrap labels on the target repo (once per repo)**

   Check whether `retrofit` and `annotation-only` labels exist:
   ```bash
   gh label list --repo ConductionNL/{app} | grep -E "^(retrofit|annotation-only)\b" || true
   ```
   If missing, create them:
   ```bash
   gh label create retrofit --color 5319E7 --repo ConductionNL/{app} || true
   gh label create annotation-only --color C5DEF5 --repo ConductionNL/{app} || true
   ```

5. **Create the ghost change scaffold**

   Prefer the existing skill (handles schema + scaffolding):
   ```
   /opsx-new retrofit-{YYYY-MM-DD}-annotate-{app}
   ```

   If `/opsx-new` isn't available, create the directory manually:
   ```
   {app}/openspec/changes/retrofit-{YYYY-MM-DD}-annotate-{app}/
     proposal.md
     tasks.md
   ```

   Fill in:

   **proposal.md**:
   ```markdown
   # Retrofit — annotate {app} against existing specs

   Retroactive annotation of {N} methods across {F} files against {M} REQs in {K} capabilities. No code logic changes. No spec deltas (all REQs already exist in openspec/specs/).

   Source: openspec/coverage-report.md generated {YYYY-MM-DD} (Bucket 1 only).

   See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
   ```

   **tasks.md** — one task per REQ, numbered by encounter order. All tasks arrive `[x]` because the code is pre-existing:
   ```markdown
   # Tasks

   - [x] task-1: meeting-management#REQ-001 — Meetings are listed chronologically (retroactive annotation)
   - [x] task-2: meeting-management#REQ-003 — Meetings can be cancelled (retroactive annotation)
   - [x] task-3: motion-and-voting#REQ-012 — Motions collect votes until close (retroactive annotation)
   ```

   Record the task-number-for-each-REQ map — you'll need it for annotation.

6. **Annotate code — one file at a time, single Edit pass per file**

   For each unique file in Bucket 1:

   a. **Read the file.**

   b. **Determine the set of `@spec` tags this file needs.** Collect every `req_id` for methods in this file + any helpers that inherited REQs from this file's methods. Map each to its task-N from step 5. The file docblock needs one `@spec` tag per distinct task.

   c. **Apply file-docblock edits:**
      - Locate the main file docblock (the `/** */` block immediately above `class`, `namespace`, or first declaration).
      - Insert `@spec openspec/changes/retrofit-{YYYY-MM-DD}-annotate-{app}/tasks.md#task-N` tags after the `@link` tag per the [hydra-gate-spdx](../hydra-gate-spdx/SKILL.md) format. One tag per distinct task. Preserve the existing tag order.
      - If the file has no main docblock, add one following the [hydra-gate-spdx](../hydra-gate-spdx/SKILL.md) template (description, `@category`, `@package`, `@author`, `@copyright`, `@license`, `@link`, `@spec` tag(s)).
      - If docblock already has `@spec` tags pointing at non-retrofit changes, leave them — append the retrofit ones after.

   d. **Apply method-docblock edits:** For each method in this file that's in Bucket 1 or inherited-from-Bucket-1:
      - **Per-method duplicate guard**: before inserting any tag, scan the method's existing docblock for a line matching `@spec openspec/changes/retrofit-*-annotate-{app}`. If found (any date suffix), **skip this method entirely** — log: `  [skip] {ClassName}::{method}() — already has retrofit-annotate tag`. Do not add a second tag, do not remove the old tag. This prevents accumulating duplicate `@spec` lines across re-runs.
      - Locate method docblock; if none, add a minimal one (`/** {one-line description}\n * @spec ... */`).
      - Append `@spec openspec/changes/retrofit-{YYYY-MM-DD}-annotate-{app}/tasks.md#task-N` after any existing `@param`/`@return`/`@throws`/`@spec` tags.
      - **PHPCS blank-line rule**: PHPCS requires a blank comment line (`*`) between the last non-`@spec` tag and the `@spec` tag. Always insert `*` before the first `@spec` if any tag precedes it. Example:
        ```php
        * @return Configuration[] Array of entities
        *
        * @spec openspec/changes/.../tasks.md#task-N
        ```
        Missing the blank line causes `Expected 1 blank line before summary; 0 found` PHPCS errors. Run `phpcbf` to auto-fix indentation; blank-line errors must be inserted manually.

   e. **Write the file via Edit tool.** Never sed/awk/python. If the linter hook reverts the Edit, rewrite the whole file via Write (project rule: high probability of breaking code when scripting edits).

   One file at a time, sequential — Edit tool's file-state tracking doesn't batch safely.

7. **Run the linter gates**

   From the workspace root:
   ```bash
   cd {app} && composer phpcs 2>&1
   ```
   For frontend-heavy apps, also:
   ```bash
   cd {app} && npm run lint 2>&1
   ```

   Also run Hydra's mechanical gates (they're what the reviewer checks against):
   ```
   /hydra-gates
   ```

   If any gate fails due to tag ordering/placement: **do not reorder tags to satisfy the linter.** The ADR-003 + hydra-gate-spdx format is fixed. Fix the PHPCS config instead. Stop, report the specific rule, wait for guidance.

8. **Commit annotations + ghost change**

   ```bash
   cd {app}
   git add -A
   git commit -m "retrofit: annotate {N} methods across {F} files (Bucket 1)

   Applied @spec tags pointing at ghost change retrofit-{YYYY-MM-DD}-annotate-{app}.
   No logic changes. Source: openspec/coverage-report.md."
   ```

   Capture the commit SHA.

9. **Update `.git-blame-ignore-revs`**

   Append the annotation commit's SHA:
   ```bash
   printf '# Retrofit annotation commit (opsx-annotate, {YYYY-MM-DD})\n{SHA}\n' >> .git-blame-ignore-revs
   git add .git-blame-ignore-revs
   git commit -m "retrofit: add annotation commit to blame-ignore-revs"
   ```

   Tell the user (don't run automatically — per-developer choice):
   > "Each developer cloning this repo must enable it once: `git config blame.ignoreRevsFile .git-blame-ignore-revs`"

10. **Archive the ghost change**

    ```
    /opsx-archive retrofit-{YYYY-MM-DD}-annotate-{app}
    ```

    Moves the change dir to `openspec/changes/archive/retrofit-*-annotate-.../`. Since the ghost change has no spec deltas, no capability specs are modified.

11. **Sanity check — verify annotations**

    Re-read `openspec/coverage-report.json` (it's stale, but still shows Bucket 1 items). Grep each file for the expected `@spec ... retrofit-{YYYY-MM-DD}-annotate-{app}` tag. Count annotations and compare against the Bucket 1 count. Mismatch → stop, investigate before pushing.

    Do NOT call `/opsx-coverage-scan` again here — it's expensive and the sanity check above is sufficient.

12. **Push and create PR**

    ```bash
    git push -u origin retrofit/annotate-{app}-{YYYY-MM-DD}
    ```

    Invoke `/create-pr` (or `gh pr create` directly). PR title:
    > `retrofit: annotate {app} Bucket 1 (N methods / M REQs)`

    PR body:
    ```markdown
    ## Retrofit — Annotation Only

    Applies `@spec openspec/changes/retrofit-{date}-annotate-{app}/tasks.md#task-N` tags per [ADR-003 §Spec traceability](hydra/openspec/architecture/adr-003-backend.md).

    Ghost change: `openspec/changes/archive/retrofit-{date}-annotate-{app}/` (empty spec delta, {M} tasks).

    ### What this PR does
    - Creates ghost change with {M} tasks (one per REQ with Bucket 1 matches)
    - Adds {N} method-level `@spec` tags across {F} files
    - Adds file-docblock `@spec` tags where missing
    - Adds this commit to `.git-blame-ignore-revs`

    ### What this PR does NOT do
    - No logic changes
    - No formatting / whitespace / reordering
    - No Bucket 2 (see follow-up `/opsx-reverse-spec` PRs)
    - No Bucket 3/4 (separate follow-ups)

    ### Verification
    - [ ] `composer phpcs` / `npm run lint` passes
    - [ ] `/hydra-gates` passes
    - [ ] Diff is annotations + ghost change only
    - [ ] `.git-blame-ignore-revs` includes the annotation commit

    Source: `openspec/coverage-report.md` generated {YYYY-MM-DD}
    ```

    Labels: `retrofit`, `annotation-only`.

13. **Summary**

    ```
    ## Annotation Complete — {app}

    Ghost change:   retrofit-{YYYY-MM-DD}-annotate-{app} (archived)
    Tasks created:  {M}
    Files touched:  {F}
    Methods tagged: {N}
    Skipped (NEEDS-REVIEW): {K}
    Branch: retrofit/annotate-{app}-{YYYY-MM-DD}
    PR: {url}

    Next:
    1. Merge this PR before proceeding to Bucket 2
    2. For each Bucket 2a cluster: `/opsx-reverse-spec {app} --extend <capability>`
    3. For each Bucket 2b cluster: `/opsx-reverse-spec {app} --cluster <name>`
    4. Optionally re-run `/opsx-coverage-scan {app}` after merge to refresh the report
    ```

**Guardrails**

- **Annotation-only PRs.** Never mix with logic changes, refactors, or formatting cleanups. Separate reviewers, separate PRs.
- **Idempotent.** Re-running on an already-annotated repo should produce no code diff (ghost change creation does produce a small diff — detect this and stop, asking if the user wants to create a fresh dated ghost change or reuse).
- **Never sed/awk/python.** Edit tool, or Write for full-file rewrite if the linter reverts. Project rule.
- **SPDX lives in the main docblock.** Per hydra-gate-spdx: `@license` + `@copyright` + `@spec` all in the same block, never as `// SPDX-...` line comments.
- **Respect hydra-gate formats.** If the PHPCS config rejects ADR-003 ordering, fix the config — don't reorder tags.
- **One file at a time.** Sequential Edits only. Edit tool's file-state tracking doesn't safely batch across files.
- **No duplicate retrofit-annotate tags.** If a method's docblock already carries any `@spec openspec/changes/retrofit-*-annotate-{app}` tag (from any prior dated run), **skip it entirely** — do not add a second annotation-pass tag. You may still add tags from opsx-reverse-spec ghost changes (e.g., `retrofit-2026-04-30-chat-ai`) alongside an existing annotate-pass tag; those are reverse-spec tags, not annotation-pass tags. Don't rewrite or remove old tags.

## Capture Learnings

After the pass, route each new observation through the two-stage buffer:

1. **High-confidence observation** (directly confirmed this run, matches an existing pattern, or fixes a measured eval failure in `evals/grading.json`) → append directly to [learnings.md](learnings.md) under the appropriate section: **Patterns That Work**, **Mistakes to Avoid**, **Domain Knowledge**, or **Open Questions**.

2. **Unverified observation** (only seen once, feels useful but not yet confirmed) → append to [learning-candidates.md](learning-candidates.md). Entries are promoted to `learnings.md` once the promotion criteria are met (confirmed in 3+ executions, resolve a measured eval failure, or receive explicit user endorsement) and discarded after 30 days otherwise.

Categories to capture:

- **Tag placement surprises** — docblocks that were harder than expected (weird nesting, mixed styles, minified PHP)
- **Linter friction** — PHPCS rules that required config changes
- **Bucket 1 precision** — how often the scan's high-confidence matches were actually correct after human review
- **Ghost change ergonomics** — how well the task layout matched human expectations

Each entry must start with today's date in `YYYY-MM-DD` format. One insight per bullet. Skip entirely if nothing new was learned.

**Consolidation trigger** — if `learnings.md` exceeds ~80–100 entries, run a consolidation pass: merge duplicates, remove outdated items, and promote any pattern confirmed in 3+ entries into the **Consolidated Principles** section. Principles there are candidates for promotion to SKILL.md Guardrails on the next edit.

> 💡 Switch models back with `/model <name>` when done.
