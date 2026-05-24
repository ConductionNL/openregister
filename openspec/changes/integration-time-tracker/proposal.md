# Integration: Time Tracker

## Problem

Billable hours per case/project are a common government-consulting requirement. Handlers log time today in disconnected systems or spreadsheets. A first-class time integration keeps hours attached to the object. Today this leaf is **stub** per the 2026-05-24 registry audit — `TimeProvider.php` is a 137-line copy-paste of the MarkerLookupTrait template with no `OCA\TimeManager\…` imports and `getLinkedItems()` returns `[]`. This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Procest and PipelinQ have no working integration path and reinvent time-logging locally.

## Context

- **Audit bucket**: stub (2026-05-24)
- **Current backend**: 137-line MarkerLookupTrait template, no `OCA\TimeManager\…` imports; `getLinkedItems()` returns `[]`
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget; backend returns `[]` so the tab is empty
- **Target NC class(es)**: `OCA\TimeManager\Db\ClientMapper` + `TaskMapper`
- **Storage strategy**: `link-table` (time entries linked to object/user)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

`TimeEntryService` + `TimeController` + `TimeProvider` + `CnTimeTab` + `CnTimeCard`. Tab: quick-log form (duration + description), list of entries grouped by user/date, total hours on object. Widget on dashboard: "Today's hours," on detail-page: breakdown by user/week. Provider imports `OCA\TimeManager\Db\ClientMapper` and `TaskMapper` for client/task resolution and falls back to `IntegrationHealth::missingApp('timemanager')` when NC Time Manager is not installed.

## Scope

**In scope:** Backend service wrapping time-tracker, link table, provider, tab with quick-log, widget, registration, tests, nl+en.

**Out of scope:** Invoicing; approval workflows (those belong in a separate billing app); rate management.

## Acceptance criteria

- [ ] Time tab appears when Time Manager installed + schema has `time-tracker` in linkedTypes
- [ ] User can log time quickly (duration + description)
- [ ] Total hours visible per object; breakdown by user
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'time-tracker'` renders hours chip
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\TimeManager\Db\ClientMapper` + `TaskMapper` imports for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('timemanager')` when NC Time Manager absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` keeps generic `leaf()` shell with notes — bespoke Tab + Widget components are OUT of this change's scope; file follow-up if needed
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
