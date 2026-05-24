# Integration: Activity

## Problem

NC Activity provides a user-facing feed of system events (file uploads, shares, comments, etc.). This is different from OR's audit trail (which is immutable, object-scoped, admin-facing). Case handlers want to see "what happened recently around this case" — a blended activity view on the object. Today this leaf is **borderline/partial** per the 2026-05-24 registry audit — the backend is template-stamped but the query on `activity.subject` is plausibly correct (NC Activity does write a single string subject), so it's treated as partial; the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnActivityTab` / `CnActivityCard` (timeline of activity entries). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Procest and Zaakafhandelapp have no working integration UI path and reinvent activity-stream surfacing locally.

## Context

- **Audit bucket**: borderline (treated as partial) (2026-05-24)
- **Current backend**: template-stamped but query on `activity.subject` is plausibly correct — NC Activity does write a single string subject column so the MarkerLookupTrait path can match in practice
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (timeline of activity entries)
- **Target NC class(es)**: `OCA\Activity\Data` (or `OCP\Activity\IManager` for the public-API path) — replace the marker scan with the canonical Activity API
- **Storage strategy**: `query-time` (no link table — events are transient; `activity.subject` is the marker column and it is verified to exist in NC core's Activity schema)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

`ActivityFeedService` + `ActivityController` + `ActivityProvider` + `CnActivityTab` + `CnActivityCard`. Unlike most integrations, this has no link table — activity is queried per-render based on the object's relationships (linked files, linked tasks, etc.). `getStorageStrategy()` returns `'query-time'`. Provider imports `OCP\Activity\IManager` (or `OCA\Activity\Data`) and falls back to `IntegrationHealth::missingApp('activity')` when NC Activity is not installed.

## Scope

**In scope:** Query-time storage strategy, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Modifying NC Activity itself; custom activity types; filtered subscriptions.

## Acceptance criteria

- [ ] Activity tab shows relevant activity events for the object and its linked entities
- [ ] Blended feed combines NC Activity stream with OR's cross-integration events
- [ ] Widget on dashboards shows "N new activities today"
- [ ] Reference-property `referenceType: 'activity'` works (though niche — activity events aren't typically referenced)
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app (this provider is `query-time` against the verified `activity.subject` column, so the MarkerLookupTrait usage is permitted)
- [ ] Real `OCP\Activity\IManager` (or `OCA\Activity\Data`) import for the backing NC app (skip for `query-time` providers that genuinely should DB-query only — for this provider the IManager path is preferred over a raw `activity.subject` DB scan)
- [ ] `health()` returns `IntegrationHealth::missingApp('activity')` when NC Activity absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + events present), absent-app (graceful empty), empty-result (app installed, no events)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnActivityTab` + `CnActivityCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
