---
name: sync-docs
description: "Check and sync documentation to reflect current project state — app feature docs (docs/) for a specific Nextcloud app, or developer/Claude docs in the .github repo (docs/claude/)"
metadata:
  category: Workflow
  tags: [docs, sync, maintenance]
---

# Sync Docs — Check and Update Documentation

Two documentation targets can be synced:

- **`app`** — Feature and user-facing docs for a specific Nextcloud app in `{APP_DIR}/docs/` (feature docs, architecture docs, standards references, etc.)
- **`dev`** — Developer/Claude guides in the `.github` repo at `{GITHUB_REPO}/docs/claude/` (workflow.md, writing-specs.md, writing-docs.md, commands.md, testing.md, etc.)

**Input**: Optional argument `app [app-name]`, `dev`, or just the app name directly. If omitted, ask which target.

---

## Step 0: Determine Target

If no argument provided, ask using AskUserQuestion:

**"Which docs do you want to sync?"**
- **`app` — App and user-facing docs** (`{APP_DIR}/docs/`) — feature documentation, standards references, admin guides
- **`dev` — Developer and Claude guides** (`~/.github/docs/claude/`) — workflow docs, spec writing guide, command reference

Store as `{SYNC_TARGET}` (`app` or `dev`).

### If `dev` is selected — locate the `.github` repo

The `.github` repo (https://github.com/ConductionNL/.github) holds the developer/Claude guides this mode reads from and writes to. Dev mode **modifies files in this repo**, so we need a local clone on the right branch.

**Step 1 — Find or clone the repo:**

Check if `~/.github` exists and contains a `.git` directory. Expand `~` to the actual home directory.

- **Found** — store the absolute path as `{GITHUB_REPO}` (e.g. `/home/wilco/.github`). Continue to Step 2.
- **Not found** — use AskUserQuestion to ask:

  **"The .github repo isn't at `~/.github`. What would you like to do?"**
  - **Clone to `~/.github` (recommended)** — run `git clone https://github.com/ConductionNL/.github.git ~/.github`. Set `{GITHUB_REPO}` = `~/.github`.
  - **It's at a different path** — ask for the absolute path. Verify it contains a `.git` directory; if not, ask again or cancel.
  - **Clone to a custom path** — ask for the absolute path, then `git clone https://github.com/ConductionNL/.github.git {path}`. Set `{GITHUB_REPO}` to that path.
  - **Cancel** — stop the skill.

**Step 2 — Verify the active branch (always — for both pre-existing and freshly cloned repos):**

Run `git -C "{GITHUB_REPO}" branch --show-current` to get the current branch.

Use AskUserQuestion: **"The .github repo is on branch `{branch}`. Sync changes will be made on this branch. Continue, or switch?"**
- **Yes, continue on `{branch}`** — proceed to Step 3.
- **Switch to a different branch** — ask for the branch name, then run `git -C "{GITHUB_REPO}" checkout {new-branch}`. If checkout fails (uncommitted changes, branch doesn't exist), report the error and ask again or cancel.
- **Cancel** — stop the skill.

**Step 3 — Compare local with remote (recommended before editing):**

Run `git -C "{GITHUB_REPO}" fetch origin {branch}` to refresh remote-tracking info, then compare local `HEAD` with `origin/{branch}`:

- **Local is behind remote** — warn the user; offer to `git pull` before editing. Editing stale files risks producing changes that conflict with remote work.
- **Local is ahead of remote** — note it; the user has unpushed work and should be aware before this skill adds more changes on top.
- **Diverged** — warn the user; this skill's edits will compound the divergence.
- **Up to date** — proceed.

The docs to sync live at `{GITHUB_REPO}/docs/claude/`.

### If `app` is selected — locate `.github` repo, `apps-extra`, and resolve `{APP_NAME}`

Also resolve `{GITHUB_REPO}` — needed for reading `writing-docs.md` as guidance. App mode does **not modify** files in `.github`, so the branch/sync checks are not required.

**Find the repo:**

Check if `~/.github` exists and contains a `.git` directory. If found, set `{GITHUB_REPO}` to that path. If not found, use AskUserQuestion:

**"`writing-docs.md` lives in the `.github` repo, which isn't at `~/.github`. What would you like to do?"**
- **Clone to `~/.github` (recommended)** — clone and use it.
- **It's at a different path** — ask for the absolute path; verify it contains `.git`.
- **Continue without it** — `writing-docs.md` will be skipped as guidance; sync proceeds with reduced rigour. Set `{GITHUB_REPO}` to empty.
- **Cancel** — stop the skill.

Discover the `apps-extra` directory:
1. Check if the workspace root's parent directory is named `apps-extra` — if so, use that parent as `{APPS_EXTRA}`.
2. Otherwise, check if an `apps-extra/` subdirectory exists inside the workspace root.
3. If neither is found, inform the user: *"Cannot find an apps-extra directory (checked parent of workspace and workspace/apps-extra/). This directory is required for app docs sync."* **Stop the skill.**

Determine `{APP_NAME}`:
- If passed as argument (e.g. `/sync-docs app openregister` or `/sync-docs openregister`), use it directly.
- Otherwise, scan `{APPS_EXTRA}/` for subdirectories that are git repos (contain a `.git` directory) and ask the user which app to sync docs for. If no git repos are found, inform the user and stop.

Store the resolved app directory path as `{APP_DIR}` (e.g. `{APPS_EXTRA}/openregister`).

---

## Step 0.5: Check writing-docs.md Currency *(optional)*

After determining the target, ask using AskUserQuestion:

**"Run pre-flight metadata checks before syncing?"**

These checks validate `config.yaml` rules, Sources of Truth accuracy, and schema alignment with `writing-specs.md`. Useful for catching project-level drift, but skippable for quick syncs.

- **Yes, run checks** — continue with Step 0.5
- **No, skip** — proceed directly to the relevant sync mode

Only run the following if the user selected "Yes":

Run the four checks described in [references/preflight-checks.md](references/preflight-checks.md) (Checks A–D) in parallel, then follow the reporting and confirmation flow in that file.

---

## Documentation Principles (applies to all modes)

All auditing and updating follows `writing-docs.md` (located at `{GITHUB_REPO}/docs/claude/writing-docs.md`). Read it before starting any sync. The sections most relevant to gap analysis:

- **[Reference, Don't Duplicate](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#the-core-rule-reference-dont-duplicate)** — every piece of information should live in exactly one place; flag any content that restates a source of truth and replace it with a link
- **[Sources of Truth](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#sources-of-truth)** — the authoritative table mapping each concern to its canonical file; use this to determine what to load and what to link to
- **[Audience Determines Location](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#audience-determines-location)** — each doc has one target audience; flag content written for the wrong audience
- **[Document Lifecycle Markers](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#document-lifecycle-markers)** — rules for `[Future]` and `[Legacy]` markers
- **[Outdated and Legacy Documentation](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#outdated-and-legacy-documentation)** — when to update, move, mark, or delete
- **[Writing Anti-Patterns](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#writing-anti-patterns)** — flag time-sensitive language, hardcoded versions, vague actors
- **[Formatting Alignment](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#formatting-alignment)** — check markdown table separator width and cell padding; check ASCII diagram `│` alignment and label/description spacing; fix misalignments when editing any file

### Universal guard: the anti-pattern purpose check

Before flagging any anti-pattern for removal or rewording, run the three-question purpose check from [writing-docs.md → Before removing an anti-pattern](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#before-removing-an-anti-pattern-check-the-notes-purpose): (1) carries a *reason* → reword, (2) flags a *known mismatch* → softer qualifier, (3) scopes *downstream advice* on a current observation → explicit temporal scoping. Only pure dated provenance is safe to delete outright. When in doubt, flag as `[Verify — load-bearing?]`.

This applies to every audit path — Pre-flight (Checks A–D), Phase 3 gap analysis (both modes), Phase 6 Part A/B/C. Delegated agents must be told to run the purpose check too.

### Additional audit categories (beyond anti-patterns)

Run the extra finding classes listed in [references/audit-categories.md](references/audit-categories.md) — ToC staleness, mermaid drift, screenshot naming, broken anchor links, hardcoded versions, and orphan source-of-truth files. Surface each as its own class, not as "stale references".

Do not re-derive these principles inline; read `writing-docs.md` and apply them directly.

---

## APP DOCS MODE

Syncs the feature and user-facing documentation for a specific Nextcloud app to match what is actually implemented.

### Phase 1: Load Source of Truth

Read all of the following in parallel:

**Specs and roadmap:**
1. All spec files in `{APP_DIR}/openspec/specs/*/spec.md` — the specified behavior for each capability
2. `{APP_DIR}/openspec/ROADMAP.md` — project phases and current phase (if present)
3. `{APP_DIR}/openspec/app-config.json` — app identity, goals, features list (if present)

**Company-wide Architectural Design Rules:**
4. All ADR files in `../../../openspec/architecture/` (relative to this skill's base dir — Hydra repo ADRs) — the constraints every Conduction app must follow (API conventions, NL Design, i18n requirements, test coverage, screenshots, etc.). These are **read as auditing context only** — never link to them from app docs.

**App-specific ADRs** (if present):
5. All ADR files in `{APP_DIR}/openspec/architecture/` — app-level overrides or additions to company-wide rules.

### Phase 2: Read Existing Docs

Read all user- and admin-facing documentation files for the app. At minimum check for:

**Root level:**
- `{APP_DIR}/README.md` — the primary public-facing description: feature summary, screenshots, setup/install instructions, external links

**`{APP_DIR}/docs/` tree:**
- `{APP_DIR}/docs/features/README.md` and all individual feature docs in `{APP_DIR}/docs/features/`
- `{APP_DIR}/docs/ARCHITECTURE.md` — high-level architecture and data model description (if present)
- `{APP_DIR}/docs/FEATURES.md` — consolidated feature overview (if present)
- `{APP_DIR}/docs/GOVERNMENT-FEATURES.md` — government/standards-specific feature notes (if present)
- `{APP_DIR}/docs/DESIGN-REFERENCES.md` — standards and design references (if present)
- `{APP_DIR}/docs/zgw-implementation.md` or similar standards implementation notes (if present)
- Any other `.md` files in `{APP_DIR}/docs/` that are not clearly developer-internal

**Developer-internal folders to skip** (do not audit for user-facing correctness):
- `{APP_DIR}/docs/development/`, `{APP_DIR}/docs/development-notes/`, `{APP_DIR}/docs/Technical/` — these are developer notes, not user docs; flag only if they contain content that belongs in user-facing docs instead

### Phase 3: Gap Analysis

For each doc file, compare content against the loaded specs, ADRs, and documentation principles (see [Documentation Principles](#documentation-principles-applies-to-all-modes)). Identify:

**Outdated content** — describes functionality that has changed or been removed
**Missing content** — features that are implemented (per specs with status `in-progress` or `done`) but not documented
**Stale `[Future]` markers** — things marked as future that are now implemented; apply the full removal checklist from [Document Lifecycle Markers](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#document-lifecycle-markers)
**Broken cross-references** — links to spec files or other docs that have moved or been renamed (see [Link Structure](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#link-structure))
**Duplicated content** — information that already lives in a spec; flag and propose replacing with a link per [Reference, Don't Duplicate](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#the-core-rule-reference-dont-duplicate)
**Wrong audience content** — developer/technical content in user-facing guides; flag for removal or relocation per [Audience Determines Location](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#audience-determines-location)
**Writing anti-patterns** — time-sensitive language, hardcoded versions, vague actors; see [Writing Anti-Patterns](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#writing-anti-patterns)
**Missing standards references** — features that lack GEMMA, ZGW, or Forum Standaardisatie references where applicable

**ADR compliance gaps** — use the loaded ADRs as a checklist against the docs:
- ADR-010 (NL Design System) — does the UI/UX description reflect NL Design components?
- ADR-007 (i18n) — does the docs mention both Dutch and English support where relevant?
- ADR-005 (Security) — are authentication and role requirements described accurately?
- ADR-009 (Documentation with Screenshots) — does `{APP_DIR}/README.md` and feature docs include screenshots for key views? Are the image files present on disk? Use `docs/features/img/` for feature-specific screenshots (reference as `img/{feature}-{view}.png`), `docs/img/` for general or multi-doc screenshots, and `docs/screenshots/` for App Store gallery shots.
- Any app-specific ADRs — does the documentation reflect app-level architectural decisions?

**`{APP_DIR}/README.md`-specific checks:**
- Does the feature list match what is actually implemented (per specs)?
- Are setup/install instructions still accurate?
- Do screenshots exist on disk for all screenshot references?
- Are external links (docs site, GitHub badges) still correct?

Present a summary table:

```
{APP_DIR}/README.md:
  ✓ Feature list — matches implemented specs
  ✗ Screenshots missing for "Settings" view — ADR-010 requires screenshots for all key views
  ~ Install instructions — reference old environment variable name

{APP_DIR}/docs/features/README.md:
  ✓ Search feature — up to date
  ✗ Export feature — not documented, but openspec/specs/export/spec.md exists with status done
  ~ Export section still marked [Future] — check if implemented

{APP_DIR}/docs/ARCHITECTURE.md:
  ✓ Data model — accurate
  ✗ API layer diagram — does not reflect current controller structure per specs
  ...
```

### Phase 4: Confirm and Update

Use AskUserQuestion:

**"I found N outdated or missing items across {APP_NAME} docs. How would you like to proceed?"**
- **Update all** — apply all identified updates
- **Review each file** — go file by file, confirm before each update
- **Show full diff first** — show all proposed changes, then confirm once
- **Cancel** — no changes

Apply updates using the Edit tool (never rewrite entire files unless everything needs changing).

**When updating**, follow the full guidance in `writing-docs.md` (at `{GITHUB_REPO}/docs/claude/writing-docs.md`):
- Keep the existing writing style and structure
- Update factual content only (URLs, feature descriptions, steps, settings)
- Move `[Future]` items to implemented sections when appropriate — follow the full removal checklist in [Document Lifecycle Markers](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#document-lifecycle-markers)
- Use `[Legacy]` markers for superseded content — see [Outdated and Legacy Documentation](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#outdated-and-legacy-documentation)
- Add new sections for features not yet documented
- Preserve any content that is still accurate
- **Replace duplicated content with links** — follow [Reference, Don't Duplicate](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#the-core-rule-reference-dont-duplicate) and [Handling large duplicates](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#handling-large-duplicates)
- Avoid writing anti-patterns — see [Writing Anti-Patterns](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#writing-anti-patterns)
- **Never add links pointing into `.claude/`** — see the No `.claude/` Links rule in [Guardrails](#guardrails)
- **Screenshot storage** — all feature screenshots go in `{APP_DIR}/docs/features/img/` with filenames `{feature}-{view}.png` (e.g., `projects-list.png`). Reference them from feature docs with relative paths (`img/projects-list.png`). Copy from `{APPS_EXTRA}/test-results/` after test runs — that directory is ephemeral.

### Phase 5: Report

```
Docs Sync — {APP_NAME} App Docs
──────────────────────────────────
{APP_DIR}/README.md                       — N changes applied
{APP_DIR}/docs/features/README.md         — N changes applied
{APP_DIR}/docs/features/search.md         — up to date, no changes
{APP_DIR}/docs/ARCHITECTURE.md            — N changes applied
{APP_DIR}/docs/ARCHITECTURE.md            — up to date, no changes

All {APP_NAME} docs are now current.
```

---

## DEV DOCS MODE (`{GITHUB_REPO}/docs/claude/`)

Syncs the developer and Claude workflow documentation in the `.github` repo to match current commands, skills, and project conventions.

**Prerequisites:** `{GITHUB_REPO}` was resolved in Step 0. The docs to sync live at `{GITHUB_REPO}/docs/claude/`.

### Phase 1: Load Source of Truth

The authoritative list of what counts as a source of truth for this project lives in the **Sources of Truth** table in `{GITHUB_REPO}/docs/claude/writing-docs.md`. Read that table first, then load all sources relevant to the dev docs. Sources come from two locations — the `.github` repo and the current workspace:

**From the `.github` repo (`{GITHUB_REPO}`):**
8. `{GITHUB_REPO}/global-settings/settings.json` and `{GITHUB_REPO}/global-settings/VERSION` — source of truth for harness configuration; used to verify `global-claude-settings.md` accuracy
9. `{GITHUB_REPO}/usage-tracker/README.md`, `{GITHUB_REPO}/usage-tracker/SETUP.md`, `{GITHUB_REPO}/usage-tracker/MODELS.md` — source of truth for usage tracker setup and model list; used to verify tracker references in `README.md` and `global-claude-settings.md`

**From the current workspace:**
1. All skill SKILL.md files in `.claude/skills/`
3. All spec files in `openspec/specs/*/spec.md` — for writing-specs.md accuracy check
4. `openspec/config.yaml` — active schema name and context rules
5. The conduction schema: `openspec/schemas/conduction/schema.yaml` and all files in `openspec/schemas/conduction/templates/`
6. All files in `personas/` — source of truth for persona names, behavior, and device preferences; used to verify `testing.md` and persona tester references
7. The workspace root `Makefile` — source of truth for available `make` targets; used to verify `make` command references in `README.md` and `getting-started.md`

### Phase 2: Read Existing Dev Docs

Read all files in `{GITHUB_REPO}/docs/claude/`:
- `README.md`
- `commands.md`
- `workflow.md`
- `writing-specs.md`
- `writing-docs.md`
- `testing.md`
- `docker.md`
- `getting-started.md`
- `global-claude-settings.md`
- `parallel-agents.md`
- Any other `.md` files found

### Phase 3: Gap Analysis

Check each doc for accuracy, completeness, and documentation principle violations (see [Documentation Principles](#documentation-principles-applies-to-all-modes)). For all files, also apply:
- [Reference, Don't Duplicate](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#the-core-rule-reference-dont-duplicate) — flag any content that restates a source of truth elsewhere
- [Audience Determines Location](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#audience-determines-location) — flag content written for the wrong audience
- [Link Structure](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#link-structure) — flag broken or absolute-path links
- [Writing Anti-Patterns](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#writing-anti-patterns) — flag time-sensitive language, hardcoded versions, vague actors

**`commands.md`** — Does it list all current skills/commands? Are any commands missing or have outdated descriptions? Are there command descriptions that duplicate content already in command files (should link instead)?

**`workflow.md`** — Does the artifact progression diagram match actual command behavior? Are the step descriptions accurate?

**`writing-specs.md`** — Does the spec structure template match what actual specs look like? Is the field reference table accurate? Is the grouping rule for the `**OpenSpec changes**` list present?

**`writing-docs.md`** — Do the documentation principles reflect current project rules? Is the Sources of Truth table up to date? Are all entries pointing to files that actually exist?

**`testing.md`** — Are the testing commands described accurately? Do persona references match current `personas/` in the workspace?

**`getting-started.md`** — Are the setup steps still accurate for the current Docker/bootstrap setup? Do any `make` commands referenced exist as targets in the workspace root `Makefile`?

**`global-claude-settings.md`** — Do the permissions, hooks, and env vars described match what is actually in `{GITHUB_REPO}/global-settings/settings.json`? Are any settings documented that no longer exist in `settings.json`? Are there settings or hooks in `settings.json` that are undocumented or misdescribed?

**`README.md`** (docs/claude/) — Is the docs index complete? Does the Quick Reference flow match the actual workflow?

**`schema.yaml` (specs artifact instruction)** — Does the `specs` artifact instruction in `openspec/schemas/conduction/schema.yaml` align with `writing-specs.md`? Apply the same logic as Check C from Step 0.5. Flag any scenario format, RFC 2119 guidance, or required-section differences that were introduced in `writing-specs.md` but not reflected in the schema instruction.

**`templates/spec.md`** — Does the template in `openspec/schemas/conduction/templates/spec.md` use GIVEN/WHEN/THEN? Does it include all three delta operations (ADDED/MODIFIED/REMOVED)? Does it match the delta spec format documented in `writing-specs.md`?

**`{GITHUB_REPO}/README.md`** — Does it accurately describe the project, workspace structure, and setup steps? Is it consistent with what's actually implemented? Do any `make` commands referenced exist as targets in the workspace root `Makefile`? Do any references to usage-tracker (setup steps, CLI commands, model list) still match `{GITHUB_REPO}/usage-tracker/README.md`, `SETUP.md`, and `MODELS.md`?

Present a summary per file showing what's accurate, what's outdated, what's missing, and what violates documentation principles.

### Phase 4: Confirm and Update

Same flow as App Docs Phase 4 — ask before making changes, offer per-file or all-at-once.

**When updating `{GITHUB_REPO}/docs/claude/`**, follow `{GITHUB_REPO}/docs/claude/writing-docs.md`:
- Never change the *intent* of the documentation without user confirmation — these docs guide Claude's behavior
- Focus on factual accuracy: command names, file paths, step descriptions
- **Replace duplicated content with links** — if a dev doc restates what's already in a command file or spec, replace with a reference per [Reference, Don't Duplicate](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#the-core-rule-reference-dont-duplicate)
- Use `[Legacy]` markers for superseded approaches — see [Outdated and Legacy Documentation](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#outdated-and-legacy-documentation)
- Avoid writing anti-patterns — see [Writing Anti-Patterns](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#writing-anti-patterns)
- If a significant behavioral change is proposed, flag it for user review

### Phase 5: Report

```
Docs Sync — Dev Docs ({GITHUB_REPO})
──────────────────────────────────────
{GITHUB_REPO}/README.md                                         — N changes applied
{GITHUB_REPO}/docs/claude/commands.md                           — N commands added/updated
{GITHUB_REPO}/docs/claude/workflow.md                           — artifact diagram updated
{GITHUB_REPO}/docs/claude/writing-specs.md                      — up to date, no changes
{GITHUB_REPO}/docs/claude/testing.md                            — N changes applied
{GITHUB_REPO}/docs/claude/README.md                             — N links updated
openspec/schemas/conduction/schema.yaml                         — specs instruction updated
openspec/schemas/conduction/templates/spec.md                   — template updated
```

### Phase 6: Commands and Skills Review
Follow the full review protocol in [references/commands-skills-review.md](references/commands-skills-review.md) — Part A (change-impact check on modified skills), Part B (standalone health check, optional), Part C (doc structure review, optional), then combined summary and confirmation.

---

## Capture Learnings

After execution, review what happened and route each new observation through the two-stage buffer:

1. **High-confidence observation** (directly confirmed this run, matches an existing pattern, or fixes a measured eval failure from `evals/grading.json`) → append directly to [learnings.md](learnings.md) under the appropriate section: **Patterns That Work**, **Mistakes to Avoid**, **Domain Knowledge**, or **Open Questions**.

2. **Unverified observation** (only seen once, feels useful but not yet confirmed) → append to [learning-candidates.md](learning-candidates.md). Entries there are promoted to `learnings.md` once they meet the promotion criteria (confirmed across 3+ executions, resolve a measured eval failure, or receive explicit user endorsement) and discarded after 30 days otherwise.

Each entry must start with today's date in `YYYY-MM-DD` format. One insight per bullet. Skip entirely if nothing new was learned.

**Consolidation trigger** — if `learnings.md` exceeds ~80–100 entries, run a consolidation pass: merge duplicates, remove outdated items, and promote any pattern confirmed in 3+ entries into the **Consolidated Principles** section. Principles in that section are candidates for promotion to SKILL.md **Guardrails** on the next edit of this file.

---

## Guardrails

- **Never auto-save** — always show what will change and ask for confirmation before writing
- **Docs only — never touch code or config** — this command makes changes exclusively to `.md` documentation files. Never modify source code, scripts, JSON, YAML, TOML, shell scripts, or any other non-markdown file, even if they contain documentation-adjacent content (e.g., inline comments, descriptions in `settings.json`). Load non-markdown files as read-only reference — never write to them
- **Non-standard doc files require confirmation** — if documentation content is found in a file that is not a `.md` file (e.g., a `README` without extension, an `.rst` file, or a `CHANGELOG`), always ask for confirmation before making any changes to it
- **Preserve writing style** — match the tone and structure of existing docs
- **Don't invent features** — only document what is in specs with status `in-progress` or `done`
- **Cross-reference accurately** — when adding links to specs or other files, verify the file exists first
- **Flag ambiguities** — if you're unsure whether something is implemented, mark it as `[Verify]` in your proposed changes rather than assuming
- **App docs stay user-friendly** — `{APP_DIR}/docs/` is for end users and admins, not developers; keep it jargon-free
- **Dev docs stay precise** — `{GITHUB_REPO}/docs/claude/` is read by Claude at runtime; accuracy matters more than prose quality
- **Follow writing-docs.md** — the full documentation principles live at `{GITHUB_REPO}/docs/claude/writing-docs.md`; apply them when writing any update
- **No ADR links in app docs** — ADRs are internal development constraints; app users and admins must never be directed to them. When auditing against ADRs, use them as context to check correctness — never insert links to ADR files into app documentation. If a doc needs to acknowledge an architectural standard, name it in prose and be explicit about its origin: use "Conduction ADR-002" (for company-wide rules from the Hydra repo's `openspec/architecture/`) or "{APP_NAME} ADR-001" (for app-specific rules from `{APP_DIR}/openspec/architecture/`) — because both levels use the same numbering scheme and an unqualified "ADR-001" is ambiguous. Never use a file link to either location. Mentioning the qualified ADR name inline is fine; linking to ADR paths is not.
