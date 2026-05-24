# Design: Integration — Collectives

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths.

## Approach

`CollectivesPageService` wraps Collectives REST API. Links store collective-id + page-id. Tab renders markdown client-side (via Collectives' own renderer where possible, or a standard markdown renderer with sanitization).

## Architecture Decisions

### AD-1: Link-only integration — no page creation

**Decision**: Users link existing pages only; cannot create Collectives pages from OR.

**Why**: Collectives pages have structural requirements (parent page, collective scope) that OR shouldn't abstract over. Linking existing is the common case.

**Trade-off**: Small friction for "I want to document this case" workflow; user creates page in Collectives, then links. Acceptable.

### AD-2: Detail-page surface renders inline page content

**Decision**: Unlike most integrations where detail-page is a list, for Collectives the detail-page surface defaults to the most recently linked page's content inline (with tabs to switch if multiple are linked).

**Why**: A procedure document or policy page is context the handler needs while working on the object. Inline beats list-then-click.

**Trade-off**: Long pages consume detail-page real estate. User can collapse.

## Files Affected

### Backend (new)
- `CollectivesPageService`, `CollectivesController`, `CollectiveLink` entity + mapper + migration, `CollectivesProvider`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnCollectivesTab/*`, `CnCollectivesCard/*`, `src/integrations/builtin/collectives.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Markdown rendering differences vs Collectives native | Use a safe default renderer; acknowledge minor divergence (e.g., plugin syntax) |
| Page permissions vary per user | Collectives RBAC enforced transitively; inaccessible pages show "No access" placeholder |
| Long pages slow down detail-page surface | Collapsible with "Read more" link to Collectives |
