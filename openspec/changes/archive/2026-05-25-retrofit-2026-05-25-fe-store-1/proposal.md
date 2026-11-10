# Retrofit — Pinia stores (Bucket fe-store, chunk 1)

## Why

The coverage scanner flagged 153 methods across 9 files under `src/store/` as
uncovered. Most Pinia store actions are thin API passthroughs or plain
getters/setters that mirror an already-specified backend capability; those are
`@spec exclude`d with a concrete reason. A minority implement a genuine
**client-side state contract** (cross-store coordination, optimistic cache
mutation, per-key cache invalidation, memoised data-source fetching) that has no
backend equivalent — those are captured by **one new capability** with **3 REQs**.

## What Changes

- Mint one new capability `frontend-store-client-state` with 3 REQs covering the
  browser-only client-state contracts: cross-store coordination + preload gating
  (REQ-001), optimistic per-key cache mutation with an app-unavailable fallback
  (REQ-002), and memoised widget-data fetching by data-source identity (REQ-003).
- Annotate the 20 store methods that carry real client-state behaviour to those REQs.
- Carry the remaining 133 store methods as reasoned `@spec exclude` tags (thin API
  passthroughs, getters/setters, dialog toggles, download helpers).

This is a retroactive specification of behaviour that already exists; no code changes.
The sibling change `retrofit-2026-05-25-fe-store-2` adds three further REQs (import
heartbeat, saved-view apply/capture) to the same `frontend-store-client-state`
capability — the two sets are disjoint and coherent as one capability.

## Counts

- Methods in batch: **153**
- Annotated to a REQ (real client-state contract): **20**
- Excluded (passthrough / getter / setter / dialog-toggle / fan-out / download helper): **133**
- New capabilities: **1** (`frontend-store-client-state`)
- New REQs: **3**

## Why a new capability (not an existing one)?

The store actions that carry real behavior describe **browser-side** contracts —
how the Vue layer caches, coordinates, and optimistically mutates local state
around the REST endpoints. The backend caps these stores call
(`rapportage-bi-export`, `register-i18n`, `built-in-dashboards`,
`nextcloud-entity-relations`, `platform-administration-modals`) already specify
the server contract; duplicating them on the client side would blur the
boundary. `frontend-store-client-state` is the canonical home for the
client-state-management concerns that are not visible server-side:

1. **Cross-store coordination & preload gating** — the dashboard store wires
   Vue watchers across the register/schema stores and gates an expensive
   parallel preload behind an `isInitialized` flag.
2. **Optimistic mutation & per-key cache invalidation** — relation stores
   (deck, emails) and the translations store mutate their local cache
   immediately on a successful write and key caches per object so one object's
   refresh never invalidates another; they also flip an "app unavailable" flag
   on HTTP 501 to render an empty state instead of an error.
3. **Memoised data-source fetching** — the reports store memoises widget data by
   data-source identity so a dashboard with two widgets sharing one source fires
   a single network call.

## Affected files

- src/store/modules/agent.js — all CRUD/getters/setters excluded
- src/store/modules/dashboard.js — coordination/preload/reset → REQ-001; chart/stat fetchers excluded
- src/store/modules/deleted.js — optimistic list-prune on delete/restore → REQ-002; rest excluded
- src/store/modules/object-relations/deck.js — optimistic link/unlink + 501 fallback → REQ-002; rest excluded
- src/store/modules/object-relations/emails.js — optimistic unlink + 501 fallback → REQ-002; rest excluded
- src/store/modules/reports.js — widget memo cache + dashboard fanout → REQ-003; rest excluded
- src/store/modules/schema.js — all CRUD/getters/setters/download excluded
- src/store/modules/source.js — all CRUD/getters/setters excluded
- src/store/modules/translations.js — optimistic status patch + RTL derivation → REQ-002; rest excluded
- src/store/settings.js — all settings passthroughs + dialog toggles excluded

## Notes / drifts

- deck.js / emails.js already carry a *file-level* `@spec` pointing at the
  `data-integrity-relations` retrofit; the scanner still flags them because they
  lack *per-method* tags. We add per-method tags here. The genuinely novel
  optimistic-cache + 501-fallback behavior is annotated to REQ-002; the plain
  `_url`/`get` helpers are excluded.
- `settings.js` is a 1.6k-line admin store; every action is a 1:1 wrapper over a
  `/api/settings/*` or `/api/solr/*` endpoint already specified server-side, or a
  pure local dialog-visibility toggle. All 67 batch methods are excluded.

## Impact

- **New capability**: `frontend-store-client-state` (REQ-001..REQ-003) — shared with the
  sibling `retrofit-2026-05-25-fe-store-2` change (disjoint REQ sets, one archive home).
- **Specs touched**: `specs/frontend-store-client-state/spec.md` (ADDED only).
- **Code**: none — annotation-only retrofit across the ten `src/store/` files listed above.

Source: `/tmp/or-scan/fw-fe-store-1.json`, generated 2026-05-25. Retrofit
playbook (ADR-003 two-tool approach).
