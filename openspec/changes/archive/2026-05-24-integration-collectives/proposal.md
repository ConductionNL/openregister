# Integration: Collectives (Knowledge)

## Problem

Team knowledge (procedures, policies, how-to docs) sits in Collectives wikis disconnected from the objects they pertain to. A case handler reading a zaak should see the relevant procedure page inline, not have to search separately. Today this leaf is **stub** per the 2026-05-24 registry audit — `CollectivesProvider.php` is a 137-line copy-paste of the MarkerLookupTrait template with no `OCA\Collectives\…` imports and `getLinkedItems()` returns `[]`. This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like PipelinQ and Procest have no working integration path and reinvent knowledge-page linking locally.

## Context

- **Audit bucket**: stub (2026-05-24)
- **Current backend**: 137-line MarkerLookupTrait template, no `OCA\Collectives\…` imports; `getLinkedItems()` returns `[]`
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget; backend returns `[]` so the tab is empty
- **Target NC class(es)**: `OCA\Collectives\Service\CollectiveService` + `PageMapper`
- **Storage strategy**: `link-table`
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Positions as:** Native alternative to the XWiki external integration (Q8 from umbrella exploration)

## Proposed Solution

`CollectivesPageService` + `CollectivesController` + `CollectivesProvider` + `CnCollectivesTab` + `CnCollectivesCard`. Tab lists linked pages with markdown preview. Detail-page widget renders the most-linked page inline. Link by page id or page path. Provider imports `OCA\Collectives\Service\CollectiveService` and `OCA\Collectives\Db\PageMapper` for page resolution and falls back to `IntegrationHealth::missingApp('collectives')` when NC Collectives is not installed.

## Scope

**In scope:** Backend wrapping Collectives pages, link table, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Page editing (lives in Collectives app); wiki-level permissions beyond what Collectives exposes; search across all collectives.

## Acceptance criteria

- [ ] Collectives tab appears when Collectives installed + schema has `collectives` in linkedTypes
- [ ] User can link existing page (picker by collective → page)
- [ ] Tab renders markdown preview of linked pages
- [ ] Detail-page widget shows the most-recent linked page's content inline
- [ ] Reference-property `referenceType: 'collectives'` renders page chip
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Collectives\Service\CollectiveService` + `PageMapper` imports for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('collectives')` when NC Collectives absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` keeps generic `leaf()` shell with notes — bespoke Tab + Widget components are OUT of this change's scope; file follow-up if needed
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
