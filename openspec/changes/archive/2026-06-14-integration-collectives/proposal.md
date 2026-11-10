# Integration: Collectives (Knowledge)

## Problem

Team knowledge (procedures, policies, how-to docs) sits in Collectives wikis disconnected from the objects they pertain to. A case handler reading a zaak should see the relevant procedure page inline, not have to search separately.

## Context

- **Backend:** greenfield — wrap Collectives REST API (markdown pages)
- **Required NC app:** `collectives`
- **Storage:** `link-table`
- **Depends on:** `pluggable-integration-registry`
- **Positions as:** Native alternative to the XWiki external integration (Q8 from umbrella exploration)

## Proposed Solution

`CollectivesPageService` + `CollectivesController` + `CollectivesProvider` + `CnCollectivesTab` + `CnCollectivesCard`. Tab lists linked pages with markdown preview. Detail-page widget renders the most-linked page inline. Link by page id or page path.

## Scope

**In scope:** Backend wrapping Collectives pages, link table, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Page editing (lives in Collectives app); wiki-level permissions beyond what Collectives exposes; search across all collectives.

## Acceptance criteria

- [ ] Collectives tab appears when Collectives installed + schema has `collectives` in linkedTypes
- [ ] User can link existing page (picker by collective → page)
- [ ] Tab renders markdown preview of linked pages
- [ ] Detail-page widget shows the most-recent linked page's content inline
- [ ] Reference-property `referenceType: 'collectives'` renders page chip
- [ ] Parity gate passes; nl+en done
