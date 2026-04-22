# Design: Integration — Bookmarks

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths..

## Approach

Simple link-table provider; tab = list + add. The "add URL" flow delegates scraping (title, description, favicon) to Bookmarks app's existing logic.

## Architecture Decisions

### AD-1: Delegate URL scraping to Bookmarks app

**Decision**: "Add URL" calls Bookmarks' own create-bookmark endpoint, then links the result. No duplicate scraping logic in OR.

**Why**: Bookmarks app already handles URL metadata extraction robustly. Duplicating is churn.

### AD-2: Reuse Bookmarks' tag system in tab display

**Decision**: Linked bookmarks show their Bookmarks-side tags as filter chips at top of tab.

**Why**: Power users of Bookmarks categorise heavily; those categories are already meaningful. Respecting them avoids duplicate tagging effort.

## Files Affected

### Backend (new)
- `BookmarkService`, `BookmarksController`, `BookmarkLink` entity + mapper + migration, `BookmarksProvider`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnBookmarksTab/*`, `CnBookmarksCard/*`, `src/integrations/builtin/bookmarks.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| URLs requiring auth — scraping returns login page title | Tab offers manual title override |
| Dead links accumulate | Optional "check link" action surfaces HTTP status; detection is manual, not automatic |
