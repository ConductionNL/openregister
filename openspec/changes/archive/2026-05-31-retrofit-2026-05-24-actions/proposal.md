# Retrofit — actions (partial pass 2 of N)

Describes observed behavior of 10 method bodies under the `actions` capability that the first retrofit pass (`retrofit-2026-05-01-actions`) explicitly deferred or did not cover. Drafts 5 new REQs (REQ-006..REQ-010). Code already exists — this change retroactively specifies it.

## Context

The first retrofit pass on actions covered the core CRUD + execution lifecycle (REQ-001..REQ-005). It explicitly listed two follow-ups in its Notes section that this pass now picks up:

- The dry-run **test endpoint** (`ActionsController::test()` + `ActionService::testAction()`)
- The **hook-migration utility** (`ActionsController::migrateFromHooks()` + `ActionService::migrateFromHooks()`)

The Phase-2 coverage scan additionally surfaced three uncovered sub-behaviors of methods already touched by REQ-001..REQ-005:

- **Admin-only gating** (`ActionsController::requireAdmin()`) — a defence-in-depth helper with a long security comment block but no spec coverage.
- **Per-action execution log retrieval** (`ActionsController::logs()`) — a sibling of the CRUD endpoints, never specced.
- **List endpoint pagination/filtering/search semantics** (`ActionsController::index()`) — REQ-001 references it but does not pin down `_page`/`_limit`/`_offset`/`_search` semantics, the supported `filterableFields` set, or the dual-query total-count behavior.

## Affected code units

- `lib/Controller/ActionsController.php::requireAdmin` → REQ-006
- `lib/Controller/ActionsController.php::test` → REQ-007
- `lib/Service/ActionService.php::testAction` → REQ-007
- `lib/Controller/ActionsController.php::migrateFromHooks` → REQ-008
- `lib/Service/ActionService.php::migrateFromHooks` → REQ-008
- `lib/Controller/ActionsController.php::logs` → REQ-009
- `lib/Controller/ActionsController.php::index` → REQ-010 (existing REQ-001 link retained)

## Out-of-scope (deferred to `future-pass:next`)

- `ActionService::updateStatistics` — observable side effects of execution counters; deferred for its own REQ in a later pass.
- `ActionService::HOOK_EVENT_MAP` semantics — observable but cross-cutting with REQ-008's normalisation.
- `getNestedValue` dot-notation accessor — internal helper, covered transitively by REQ-007's filter-condition scenario.

## Out-of-cluster (DROP, sibling capability)

The Phase-2 cluster scan name-matched 78 additional methods because they sit under `lib/Service/TextExtraction/*` and `lib/Service/TextExtractionService.php`; these are **not** workflow Actions:

- `TextExtraction/EmlParser`, `EmlAttachment`, `EmlBody`, `EmlStructure` → belong to capability **text-extraction-eml**
- `TextExtraction/EntityRecognitionHandler` (Presidio / OpenAnonymiser / hybrid PII detection) → belongs to a future **pii-entity-recognition** capability (currently uncovered)
- `TextExtraction/FileHandler`, `ObjectHandler`, `TextExtractionService` → belong to capability **text-extraction-vectorization** (currently uncovered)
- `BackgroundJob/FileTextExtractionJob` → belongs to capability **text-extraction-vectorization** (background-job sub-behavior)
- `src/components/files-sidebar/ExtractionTab.vue` → frontend tab for text-extraction status; sibling
- `src/mail-sidebar/components/ActionsTab.vue::objectName` → mail-sidebar UI tab, not workflow actions; sibling capability **mail-sidebar**

The previous pass already triaged most of these as DROPs; this proposal carries that triage forward without re-litigation. The right home for each is flagged in the per-method DROP notes inside `tasks.md`.

## Approach

For each in-scope method:
- Read the body, capture observed inputs, outputs, side effects, failure modes, preconditions.
- Draft a REQ that pins down only the observed behavior.
- Surface any observed-but-suspicious behavior in a Notes section (per first-pass convention).

Bias: observed, not aspirational. Bugs stay bugs — surfaced, not silently spec-fixed.

Source: `/tmp/or-scan/rspec-cluster-actions.json` (96 methods, 17 files). See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
