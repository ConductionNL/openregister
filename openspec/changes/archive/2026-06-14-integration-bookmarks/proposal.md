# Integration: Bookmarks

## Problem

Related reference URLs (standards, legal sources, competitor links, external documentation) are case-work context today scattered in object description fields or external notes. They deserve first-class structured linking to the object.

## Context

- **Backend:** greenfield — wrap NC Bookmarks REST API
- **Required NC app:** `bookmarks`
- **Storage:** `link-table`
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`BookmarkService` + `BookmarksController` + `BookmarksProvider` + `CnBookmarksTab` + `CnBookmarksCard`. Tab with URL preview cards, tag chips from Bookmarks' own tag system, add/unlink. Widget surfaces favicon + title for compact display.

## Scope

**In scope:** Backend service, link table, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Bookmark editing (goes to Bookmarks app); auto-archive via web.archive; deep-linking into URL content.

## Acceptance criteria

- [ ] Bookmarks tab appears when Bookmarks installed + schema has `bookmarks` in linkedTypes
- [ ] User can link existing bookmark or add a URL (auto-scraped title/favicon)
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'bookmarks'` works
- [ ] Parity gate passes; nl+en done
