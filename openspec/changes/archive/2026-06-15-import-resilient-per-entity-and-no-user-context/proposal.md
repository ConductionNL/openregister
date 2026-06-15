# Resilient Per-Entity Config Import + No-User-Context Fallback

## Problem

`ImportHandler::importFromApp()` → `importFromJson()` aborts an app's **entire** data-layer import when a **single** entity fails to validate or persist. Observed live during `occ upgrade` on the `:8080` dev instance:

- **pipelinq**: `[ImportHandler] Failed to import configuration for app pipelinq: The required property (name) is missing` — a raw opis/json-schema validation failure on one entity bubbled up through the unwrapped register/object loops to the outer `catch` in `importFromApp()` (line ~2987), which re-throws and aborts the whole pipelinq import. Result: pipelinq's registers + schemas were never created.
- **softwarecatalog**: a later migration reported "register not found" — because the register was never created: the import aborted at/before register creation when an earlier entity threw.
- **shillinq**: `Access to folder 'NNNN' is denied for the acting user` — under `occ` there is **no** user session, so `$hasUserContext` is false and any folder/object operation that resolves the acting user from the session defaults to deny, aborting the import.

Per-**schema** import is already resilient (two-pass loop with per-schema `try/catch` at lines ~1514-1557 / ~1593-1633, plus the slug-guard skip in `importSchema()`). The remaining abort sources are:

1. The **register** import loop in `importFromJson()` (lines ~1649-1703) — `importRegister()` re-throws on failure and the loop body is **not** wrapped, so one bad register aborts every later register, the mappings, the objects and the seed data.
2. The main **object** import loop in `importFromJson()` (lines ~1810-1995) — `saveObject()` is called per object with **no** surrounding `try/catch`, so one title-less / name-missing / validation-failing seed object aborts the whole loop.
3. The `importSeedData()` invocation in `importFromJson()` (line ~2047) and the per-schema preamble inside it (target-register resolution, the per-`$schemaSlug` loop body before the inner `try`) are not wrapped, so a single bad schema slug there can throw past the existing per-object `try/catch`.
4. **No-user-context**: object/folder operations during import resolve the acting user from `IUserSession`, which is null under `occ`, producing `FolderAccessDeniedException` and aborting the import.

## Proposed Solution

Add resilience **only** — preserve every existing happy-path behaviour (this is high-blast-radius core code):

1. **Per-entity resilience.** Wrap each register, each mapping (already done), each main-loop object, the `importSeedData()` call, and the seed-data per-schema body in its own `try/catch` that logs a descriptive **warning** with the entity id/slug + reason and **continues**. The net effect: an app's registers + schemas are created even when some seed objects / title-less fragments / name-missing entities fail validation (those skip with a warning).

2. **Skip observability.** Collect skipped-entity counts (`skipped` map keyed by entity kind) into the returned `$result` so callers and tests can assert how many entities were skipped and why.

3. **No-user-context fallback.** When `IUserSession::getUser()` is null (the `occ`/system path), resolve a fallback **system user** — the first member of the `admin` group via `IGroupManager`, falling back to `IUserManager` — and forward it as the explicit `currentUser` acting user to `saveObject()` so folder/object operations succeed. When a real user session **is** present, behaviour is unchanged. If no admin is resolvable, log + skip only that user-dependent op (not the whole import).

4. **No silenced errors.** Every skip logs a clear WARNING with the entity id/slug + reason. Validation is **not** weakened on the happy path.

## Impact

- Affected code: `lib/Service/Configuration/ImportHandler.php`, `lib/AppInfo/Application.php` (DI wiring of the fallback-user resolver).
- Affected behaviour: app config import (installer `repair`, `occ upgrade`, runtime OAS import) becomes per-entity fault-tolerant; partial imports now produce registers + schemas + the valid subset of objects instead of nothing.
- Backwards compatible: a fully-valid configuration imports exactly as before; only the failure paths change (skip-and-continue instead of abort).
