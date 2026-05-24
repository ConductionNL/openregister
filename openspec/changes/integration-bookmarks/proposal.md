# Integration: Bookmarks

## Problem

Related reference URLs (standards, legal sources, competitor links, external documentation) are case-work context today scattered in object description fields or external notes. They deserve first-class structured linking to the object. Today this leaf is **partial** per the 2026-05-24 registry audit — the backend uses `BookmarkMapper` directly and returns real linked bookmarks, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnBookmarksTab` / `CnBookmarksCard` (preview cards). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like PipelinQ and Decidesk have no working integration UI path and reinvent URL-attachment locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: Direct `BookmarkMapper` usage — returns real linked NC Bookmarks rows
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (preview cards)
- **Target NC class(es)**: `OCA\Bookmarks\Db\BookmarkMapper` (already imported by the provider)
- **Storage strategy**: `link-table`
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

`BookmarkService` + `BookmarksController` + `BookmarksProvider` + `CnBookmarksTab` + `CnBookmarksCard`. Tab with URL preview cards, tag chips from Bookmarks' own tag system, add/unlink. Widget surfaces favicon + title for compact display. Provider continues to import `OCA\Bookmarks\Db\BookmarkMapper` and falls back to `IntegrationHealth::missingApp('bookmarks')` when NC Bookmarks is not installed.

## Scope

**In scope:** Backend service, link table, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Bookmark editing (goes to Bookmarks app); auto-archive via web.archive; deep-linking into URL content.

## Acceptance criteria

- [ ] Bookmarks tab appears when Bookmarks installed + schema has `bookmarks` in linkedTypes
- [ ] User can link existing bookmark or add a URL (auto-scraped title/favicon)
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'bookmarks'` works
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Bookmarks\Db\BookmarkMapper` import for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('bookmarks')` when NC Bookmarks absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnBookmarksTab` + `CnBookmarksCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
