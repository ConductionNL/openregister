# Integration: Forms

## Problem

Case intake, surveys, and feedback collection happen via NC Forms today, disconnected from OR objects. Form responses should be first-class linked artefacts on the case/object they pertain to. Today this leaf is **stub** per the 2026-05-24 registry audit — `FormsProvider.php` is a 137-line copy-paste of the MarkerLookupTrait template with no `OCA\Forms\…` imports and `getLinkedItems()` returns `[]`. This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like PipelinQ and Procest have no working integration path and reinvent Forms-style intake locally.

## Context

- **Audit bucket**: stub (2026-05-24)
- **Current backend**: 137-line MarkerLookupTrait template, no `OCA\Forms\…` imports; `getLinkedItems()` returns `[]`
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget; backend returns `[]` so the tab is empty
- **Target NC class(es)**: `OCA\Forms\Db\FormMapper`
- **Storage strategy**: `link-table` (`openregister_form_links` mapping object ↔ form + response)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

Build `FormResponseService` + `FormResponsesController` + `FormsProvider` + `CnFormsTab` + `CnFormsCard`. Tab displays linked responses (read-only), with "Link an existing response" and "Link a form for future responses" flows. Widget on detail-page shows response count and quick access to the most recent. Provider imports `OCA\Forms\Db\FormMapper` for the linked-form query and falls back to `IntegrationHealth::missingApp('forms')` when NC Forms is not installed.

## Scope

**In scope:** Backend service + controller, link table + migration, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Form authoring (lives in Forms app); response editing (responses are immutable in Forms); PII redaction.

## Acceptance criteria

- [ ] Forms tab appears when Forms installed + schema has `forms` in linkedTypes
- [ ] User can link existing response OR configure form-for-future-responses mapping
- [ ] Response viewer renders questions + answers inline (read-only)
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'forms'` works
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Forms\Db\FormMapper` import for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('forms')` when NC Forms absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` keeps generic `leaf()` shell with notes — bespoke Tab + Widget components are OUT of this change's scope; file follow-up if needed
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
