# Integration: Shares

## Problem

NC Shares (file/folder shares, public links) pertain to case files but are invisible from the object. "Who has access to this case?" requires clicking into Files, finding the folder, opening the share panel. Today this leaf is **partial** per the 2026-05-24 registry audit — the backend uses MarkerLookupTrait against `share.note` which NC rarely populates, so results are usually empty; the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnSharesTab` / `CnSharesCard` (share recipient list). **Lower priority — note that NC's `share.note` is rarely populated, so the scope of this leaf may shrink** (likely to live-query Share Manager rather than scan share notes). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Zaakafhandelapp and Procest have no working integration path and reinvent share-recipient surfacing locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: MarkerLookupTrait on `share.note` — NC rarely populates this column so results are usually empty; should pivot to `OCP\Share\IManager` live-query
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (share recipient list)
- **Target NC class(es)**: `OCP\Share\IManager` (NC core) — live-query for shares on the object's linked files
- **Storage strategy**: `query-time` (shares are queried live from Share Manager filtered by object's linked files; the `share.note` MarkerLookupTrait path should be retired)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

`ShareService` + `SharesController` + `SharesProvider` + `CnSharesTab` + `CnSharesCard`. Tab aggregates shares across object's linked files — shows who has access, with what permissions, via what mechanism (user share, group share, public link, federated). Quick revoke action. Provider imports `OCP\Share\IManager` (NC core) and queries live; given NC core is always present, `health()` simply reports `ok` unless the share-filesystem subsystem is unreachable.

## Scope

**In scope:** Backend service querying Share Manager for all shares on object's linked files, provider with `query-time` storage, tab, widget, revoke action, registration, tests, nl+en.

**Out of scope:** Creating new shares (NC Files UI owns); share-expiry management UI (NC Files UI); federated share negotiation.

## Acceptance criteria

- [ ] Shares tab always appears (no required app) when schema has `shares` in linkedTypes
- [ ] Tab aggregates shares across all object's linked files
- [ ] User can revoke a share from the tab (delegated to Share Manager)
- [ ] Widget shows share count on dashboards
- [ ] Reference-property `referenceType: 'shares'` renders share chip
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCP\Share\IManager` import for the backing NC app (skip for `query-time` providers that genuinely should DB-query only — this provider is query-time so the IManager import is the canonical path)
- [ ] `health()` returns `ok` (NC core, no missingApp scenario); never throws
- [ ] PHPUnit tests cover: happy-path (shares present), no-shares (graceful empty), unreachable-subsystem (degraded)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnSharesTab` + `CnSharesCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
