# Design: Integration — XWiki

> Umbrella decisions apply. Second external-storage leaf — reuses `getOpenConnectorSource()` + `ExternalIntegrationRouter` established by `integration-openproject`.

## Approach

Mirrors `integration-openproject` but targets XWiki's REST API. `XwikiProvider` declares `storage='external'` and returns `'xwiki'` from `getOpenConnectorSource()`. Tab is lighter than OpenProject (no status/progress — wikis are documents, not work) but adds a text preview on detail-page surface.

## Architecture Decisions

### AD-1: Preview renders text, not full XWiki macros

**Decision**: Detail-page preview fetches the page's HTML-rendered content via XWiki REST, then strips to text (first 500 chars) plus link to full page. Macros are not executed.

**Why**: Executing arbitrary XWiki macros inside NC has security implications (velocity templates, scripts). Text preview keeps the integration safe and simple.

**Trade-off**: Users lose rich formatting in preview. Acceptable — click-through to XWiki for full rendering.

### AD-2: Link by URL or wiki-path

**Decision**: Link form accepts either a full XWiki URL (which is parsed to extract wiki/space/page) or a direct `space.page` path. Both resolve to the same canonical page reference.

**Why**: Users copy URLs from browser tabs; power users know paths. Accepting both reduces friction.

### AD-3: Page breadcrumb shown in tab rows

**Decision**: Tab rows show not just title but full breadcrumb ("Wiki / Department / Subspace / Page Title").

**Why**: XWiki's hierarchical structure is meaningful — two pages can have the same title in different spaces. Breadcrumb disambiguates.

## Files Affected

### Backend (new)
- `lib/Service/Integration/Providers/XwikiProvider.php`
- OpenConnector source config template `config/openconnector-sources/xwiki.yaml`
- Unit tests

### Backend (modified)
- `Application.php` (DI-tag)

### Frontend (new)
- `CnXwikiTab/*` — list with breadcrumb, link-by-URL or path
- `CnXwikiCard/*` — 4 surfaces, detail-page has text preview
- `src/integrations/builtin/xwiki.js` — registration
- Barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| XWiki instance versions vary (5.x, 10.x+, 14.x+) | OpenConnector adapter normalises; provider stays version-agnostic |
| Basic auth vs OAuth — mixed customer setups | Auth requirements are configurable at the OpenConnector source level; provider declares "one of: basic, oauth2" |
| Large pages slow preview render | Text strip to 500 chars; lazy-load only on detail-page surface expand |
| Permission mismatches (user has NC access but not XWiki access) | Show "No access to page" placeholder; don't leak internal errors |
