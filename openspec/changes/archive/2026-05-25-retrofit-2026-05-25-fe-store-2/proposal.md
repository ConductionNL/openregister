# Retrofit — frontend coverage, store (chunk 2)

## Why

The 2026-05-25 coverage scan surfaced 150 public methods across 11 `src/store/`
files that carry no `@spec` annotation linking them to a capability. This change
brings every one of those methods under ADR-003's annotation convention using the
two-tool approach: each method ends tagged with either a `@spec` pointer to a REQ
it implements, or a `@spec exclude <reason>` tag documenting why it is deliberately
unspecified. Code already exists — this change describes observed behaviour, it
does not change runtime behaviour.

## What Changes

- Mint **one new capability** `frontend-store-client-state` with **3 REQs**
  (REQ-001..REQ-003) describing genuinely novel client-side behaviour that no
  backend endpoint mirrors:
  - REQ-001 — client-side import heartbeat keeping the session alive during long
    register imports (`register.js::startImportHeartbeat` + its returned `stop`).
  - REQ-002 — applying a saved view's configuration onto a live search-store
    instance (`views.js::applyView`).
  - REQ-003 — capturing the live search-store state into a saveable view
    configuration object (`views.js::createViewFromSearchState`).
- **Annotate** methods that map to an existing REQ to that REQ's task pointer
  (search-trail store → the existing `retrofit-2026-04-23-annotate-openregister`
  task-89; per-object contact/event relation stores → their existing file-level
  task pointers).
- **Exclude** every other method — thin CRUD/import API passthroughs whose
  observable contract is owned by the mirrored backend capability spec, plus
  getters/setters/UI-state mutators, no-op compatibility stubs, and delegation to
  the `@conduction/nextcloud-vue` package object store. Each exclude carries a
  required reason.

This is a retroactive specification. No code behaviour changes.

## Scope of this batch

**Batch source**: `/tmp/or-scan/fw-fe-store-2.json` — 150 methods / 11 files.

After triage:

- **4 methods → spec'd to NEW REQs** (3 REQs in `frontend-store-client-state`):
  - `src/store/modules/register.js::startImportHeartbeat` → REQ-001
  - `src/store/modules/register.js::stop` (closure returned by startImportHeartbeat) → REQ-001
  - `src/store/modules/views.js::applyView` → REQ-002
  - `src/store/modules/views.js::createViewFromSearchState` → REQ-003

- **33 methods → annotated to EXISTING REQs**:
  - `src/store/modules/searchTrail.js` (22 methods) → `retrofit-2026-04-23-annotate-openregister/tasks.md#task-89` (search-trail capability the file already references at file scope).
  - `src/store/modules/object-relations/contacts.js` (5 methods) → `retrofit-2026-05-24-contacts-actions/tasks.md#task-5` (contact-relations capability the file already references at file scope).
  - `src/store/modules/object-relations/events.js` (6 methods) → `retrofit-2026-05-24-data-integrity-relations/tasks.md#task-5` (event-relations capability the file already references at file scope).

- **113 methods → `@spec exclude <reason>`**:
  - `application.js` (11), `avg.js` (12), `configuration.js` (16), `conversation.ts` (18), `navigation.js` (4), `organisation.js` (21), `register.js` (16 of 18 — the two heartbeat methods are spec'd), `views.js` (8 of 10 — applyView/createViewFromSearchState are spec'd), `object.js` (5).
  - Reasons fall into four families: (a) thin API passthrough mirrored by a backend capability spec (avg-verwerkingsregister, data-import-export, chat-ai, tenant-lifecycle, zoeken-filteren, register lifecycle); (b) pure client UI-state getter/setter/mutator with no backend contract; (c) no-op compatibility stub (`object.js::initializeColumnFilters`/`initializeProperties`); (d) delegation to the shared `@conduction/nextcloud-vue` package object store.

## Counts

| Outcome | Methods |
|---|---|
| Spec'd to NEW REQs | 4 |
| Annotated to EXISTING REQs | 33 |
| Excluded (with reason) | 113 |
| **Total** | **150** |

New REQs minted: **3** (`frontend-store-client-state` REQ-001..REQ-003).

## Impact

- **Capability**: `frontend-store-client-state` (REQ-001..REQ-003) — the same capability
  the sibling `retrofit-2026-05-25-fe-store-1` change extends; this change was reconciled
  from a duplicate `frontend-client-state-orchestration` cap into the shared one. The two
  changes' REQ sets are disjoint (this one: heartbeat + saved-view apply/capture; sibling:
  caching/preload/memoisation) and coherent as a single capability.
- **Specs touched**: `specs/frontend-store-client-state/spec.md` (ADDED only).
- **Code**: none — annotation-only retrofit across the eleven `src/store/` files.

## Approach

For each method: read the body, classify as spec-to-new-REQ, annotate-to-existing,
or exclude-with-reason. Methods that are API passthroughs whose contract is the
backend's are excluded (the backend capability spec already owns the observable
behaviour); methods that hold novel client-only orchestration get a REQ; methods
that mirror an already-specified store surface inherit that surface's REQ.

See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
