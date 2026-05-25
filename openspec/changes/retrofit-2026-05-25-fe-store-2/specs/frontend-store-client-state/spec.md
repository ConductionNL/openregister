---
retrofit: true
---

# Frontend Store Client State

**Status**: in-progress
**Scope**: openregister

> Consolidated into the `frontend-store-client-state` capability (was the separate `frontend-client-state-orchestration` cap). This delta adds the import-keepalive heartbeat + saved-view apply/capture orchestration REQs; the sibling `retrofit-2026-05-25-fe-store-1` change adds the store-internal caching/preload/memoisation REQs. Both archive into one capability.

## Purpose

Specifies observable client-side behaviour in OpenRegister's Pinia stores that is
**not** a mirror of any backend endpoint — orchestration that lives only in the
browser. Most store actions are thin passthroughs to the REST API and inherit their
contract from the backend capability that owns the endpoint; those are excluded from
spec coverage via `@spec exclude`. This capability captures the handful of store
behaviours that have no backend counterpart and therefore need their own REQs: the
import-keepalive heartbeat that prevents gateway timeouts, and the saved-view
apply/capture bridge between the persisted view store and the live search store.
This spec describes behaviour that already exists in `src/store/modules/` so the
coverage matcher can resolve `@spec` annotations on those methods.

## ADDED Requirements

### Requirement: Long register imports MUST be kept alive by a client-side heartbeat

The register store (`src/store/modules/register.js`) MUST provide a client-side
heartbeat that, while an import is in flight, periodically issues a lightweight
`GET /index.php/apps/openregister/api/heartbeat` request to keep the session warm,
because register imports can run far longer than a typical HTTP request and risk
gateway and session timeouts. The heartbeat MUST run on a fixed interval (default 15 seconds), MUST
track consecutive failures, MUST consider itself unhealthy only after 3 consecutive
failures, MUST NOT stop polling on transient failure, and MUST surface health
transitions through an optional status callback. The heartbeat MUST return a handle
exposing a `stop()` method that clears the interval, and `importRegister` MUST always
call `stop()` when the import settles (success or error).

#### Scenario: Heartbeat pings on its interval during an import

- **GIVEN** an import started via `importRegister(file)` for the selected register
- **WHEN** `startImportHeartbeat(15000, onStatusChange)` is invoked
- **THEN** the store SHALL issue `GET …/api/heartbeat` every 15 seconds with `Cache-Control: no-cache` and a 10-second per-request abort timeout
- **AND** the returned handle SHALL expose `stop()` and `getStatus()`

#### Scenario: Transient failures do not stop the heartbeat

- **GIVEN** a running heartbeat whose first two pings fail
- **WHEN** the failures occur
- **THEN** the heartbeat SHALL keep polling (the interval is not cleared)
- **AND** it SHALL remain "healthy" until a third consecutive failure flips it to unhealthy
- **AND** a recovered ping SHALL reset the failure count back to zero and report `healthy: true` via the status callback

#### Scenario: Import settlement stops the heartbeat

- **GIVEN** an in-flight import with an active heartbeat
- **WHEN** the import resolves or rejects
- **THEN** `importRegister` SHALL call the handle's `stop()` in its `finally` block
- **AND** `stop()` SHALL clear the polling interval so no further heartbeat requests are issued

### Requirement: A saved view's configuration MUST be applied onto the live search store

The views store (`src/store/modules/views.js`) MUST be able to project a saved view's
persisted `configuration` onto a live search-store instance so that selecting a saved
view restores the full search context. `applyView(view, searchStore)` MUST apply each
present configuration facet — selected registers, schemas, source, search terms, facet
filters, enabled facets, advanced filters, pagination, sorting, and columns — to the
provided search store via that store's setters, skip facets that are absent from the
configuration, and mark the applied view as active. A view without a `configuration`
MUST be rejected as a no-op (with a warning) rather than partially applied.

#### Scenario: Applying a complete saved view

- **GIVEN** a saved view whose `configuration` contains registers, schemas, source, searchTerms, facetFilters, enabledFacets, advancedFilters, pagination, sorting, and columns
- **WHEN** `applyView(view, searchStore)` is called
- **THEN** each corresponding `searchStore.set*` setter SHALL be invoked with the configuration value
- **AND** `setActiveView(view)` SHALL be called so the view becomes the active view

#### Scenario: Partial configuration applies only present facets

- **GIVEN** a saved view whose `configuration` only contains `registers` and `searchTerms`
- **WHEN** `applyView` is called
- **THEN** only `setSelectedRegisters` and `setSearchTerms` SHALL be invoked
- **AND** the other search-store facets SHALL be left untouched

#### Scenario: View without configuration is a guarded no-op

- **GIVEN** a `view` that is null or lacks a `configuration` object
- **WHEN** `applyView(view, searchStore)` is called
- **THEN** the method SHALL warn and return without mutating the search store or the active view

### Requirement: The live search-store state MUST be capturable into a saveable view configuration

Conversely, the views store MUST be able to snapshot the current search context into a
view object suitable for persistence. `createViewFromSearchState(searchStore, name,
description, isDefault, isPublic)` MUST return a plain object carrying the supplied
metadata (name, description, default/public flags) and a `configuration` block built
from the search store's current selectors — registers, schemas, source, search terms,
facet filters, enabled facets, advanced filters, pagination, sorting, and columns —
applying sensible defaults for any selector that is empty or unset (e.g. `source`
defaults to `'auto'`, pagination to `{ page: 1, limit: 20 }`).

#### Scenario: Capturing the current search state

- **GIVEN** a search store with selected registers, a source of `'graphql'`, and custom pagination
- **WHEN** `createViewFromSearchState(searchStore, 'My view', 'desc', false, true)` is called
- **THEN** the returned object SHALL carry `name: 'My view'`, `description: 'desc'`, `isDefault: false`, `isPublic: true`
- **AND** its `configuration` SHALL mirror the search store's current registers, schemas, source, searchTerms, facetFilters, enabledFacets, advancedFilters, pagination, sorting, and columns

#### Scenario: Empty selectors fall back to defaults

- **GIVEN** a freshly-initialised search store with no source and no pagination set
- **WHEN** `createViewFromSearchState(searchStore, 'Blank', '', false, false)` is called
- **THEN** the returned `configuration.source` SHALL be `'auto'`
- **AND** `configuration.pagination` SHALL be `{ page: 1, limit: 20 }`
- **AND** array selectors SHALL default to `[]` and object selectors to `{}`

## Non-Functional Requirements

- **Resilience:** The heartbeat (REQ-001) MUST be fault-tolerant — a flaky network during a long import must not abort the import or wedge the SPA. Failures are logged and counted, never thrown out of the interval callback.
- **Separation of concerns:** REQ-002/REQ-003 keep the persisted view store and the live search store decoupled — the views store reads/writes the search store only through its public setters and selectors, never its internal state.
- **Internationalisation:** These store methods carry no user-facing strings; locale does not affect their behaviour (ADR-007).

## Acceptance Criteria

- [ ] `@spec` annotations exist on `register.js::startImportHeartbeat` (+ its `stop` closure), `views.js::applyView`, and `views.js::createViewFromSearchState`, pointing at `openspec/changes/retrofit-2026-05-25-fe-store-2/tasks.md#task-N`.
- [ ] `npm run lint -- src/store/modules/register.js src/store/modules/views.js` passes.
- [ ] Coverage scan re-run after archive resolves these methods to their REQ (no longer uncovered).

## Notes

- The heartbeat's health threshold (3 consecutive failures) and interval (15s) are the observed defaults; they are captured as the contract, not hardened — tuning is out of scope for this retrofit.
- `applyView`/`createViewFromSearchState` take the search store as an argument rather than importing it, because the views store predates a stable cross-store import path; the REQs capture the current argument-injection contract.
