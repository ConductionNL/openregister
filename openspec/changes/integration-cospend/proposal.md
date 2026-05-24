# Integration: Cospend (Costs)

## Problem

Project costs (expenses per case, distributed bills, reimbursements) live in NC Cospend but aren't visible from the object they pertain to. Case cost tracking requires app-switching. Today this leaf is **stub** per the 2026-05-24 registry audit — `CospendProvider.php` is a 137-line copy-paste of the MarkerLookupTrait template with no `OCA\Cospend\…` imports and `getLinkedItems()` returns `[]`. This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Procest and PipelinQ have no working integration path and reinvent expense-attachment locally.

## Context

- **Audit bucket**: stub (2026-05-24)
- **Current backend**: 137-line MarkerLookupTrait template, no `OCA\Cospend\…` imports; `getLinkedItems()` returns `[]`
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget; backend returns `[]` so the tab is empty
- **Target NC class(es)**: `OCA\Cospend\Service\ProjectService` + `BillMapper`
- **Storage strategy**: `link-table` (project/bill linked to object)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

`CospendService` + `CospendController` + `CospendProvider` + `CnCospendTab` + `CnCospendCard`. Tab shows linked Cospend projects and bills with total amount, currency, split. Widget on detail-page shows "Total spent" summary. Provider imports `OCA\Cospend\Service\ProjectService` and `OCA\Cospend\Db\BillMapper` for project/bill resolution and falls back to `IntegrationHealth::missingApp('cospend')` when NC Cospend is not installed.

## Scope

**In scope:** Backend service wrapping Cospend, link table, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Bill editing (Cospend owns); currency conversion; settlement workflow.

## Acceptance criteria

- [ ] Cospend tab appears when Cospend installed + schema has `cospend` in linkedTypes
- [ ] Tab lists linked projects/bills with totals
- [ ] Widget shows total spent on all 4 surfaces
- [ ] Reference-property `referenceType: 'cospend'` renders amount chip
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Cospend\Service\ProjectService` + `BillMapper` imports for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('cospend')` when NC Cospend absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` keeps generic `leaf()` shell with notes — bespoke Tab + Widget components are OUT of this change's scope; file follow-up if needed
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
