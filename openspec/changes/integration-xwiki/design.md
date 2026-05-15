# Design: Integration — XWiki

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths. First **external-storage** leaf — reuses the `getStorageStrategy() === 'external'` + `getOpenConnectorSource()` + `ExternalIntegrationRouter` machinery established by the `pluggable-integration-registry` umbrella (AD-4 external routing / AD-22 storage strategies / AD-23 failure-path contract).

## Approach

Targets XWiki's REST API. `XwikiProvider` declares `storage='external'` and returns `'xwiki'` from `getOpenConnectorSource()`; all CRUD is delegated to `ExternalIntegrationRouter` (the provider carries no HTTP client and no credentials). The tab is light — wikis are documents, not work, so there's no status/progress — but the widget adds a **text** preview on the detail-page surface.

## Architecture Decisions

### AD-1: Preview renders text, not executed XWiki macros

**Decision**: The detail-page preview shows the page's HTML-**rendered** body, stripped to plain text (first ~500 chars) plus a link to the full page. Macros are never executed *inside Nextcloud*.

**Implementation note (verified against `xwiki:lts` 17.10)**: XWiki's REST page representation (`GET /rest/wikis/{wiki}/spaces/{Space}/pages/{Page}`) returns `content` as **raw `xwiki/2.1` syntax**, not rendered HTML. So the OpenConnector `xwiki` source fetches the rendered body from `GET /bin/get/{Space}/{Page}?xpage=plain` (XWiki executes the page's macros *server-side, in XWiki's own sandbox*) and maps that to `content`. `XwikiProvider::normalizeRow()` round-trips it under `content` (with a `renderedContent` alias fallback); the `@conduction/nextcloud-vue` widget then strips all HTML tags + the `<script>`/`<style>` bodies to text and truncates — it never injects the HTML into the DOM. (Mapping `content` straight from the REST `content` field instead is still safe — macro markup is inert text — just less polished.)

**Why**: Executing arbitrary XWiki macros inside NC has security implications (velocity templates, scripts). Text preview keeps the integration safe and simple.

**Trade-off**: Users lose rich formatting in preview. Acceptable — click-through to XWiki for full rendering.

### AD-2: Link by URL or wiki-path

**Decision**: The link form accepts either a full XWiki URL (parsed to extract wiki/space/page) or a direct `Space.Page` path. Both resolve to the same canonical page reference. `XwikiProvider::create()` passes the raw `reference` through; the OpenConnector `xwiki` source's `create` endpoint does the resolution.

**Why**: Users copy URLs from browser tabs; power users know paths. Accepting both reduces friction.

### AD-3: Page breadcrumb shown in tab rows

**Decision**: Tab rows show not just title but the full breadcrumb ("Wiki / Department / Subspace / Page Title").

**Implementation note**: XWiki exposes a native breadcrumb at `hierarchy.items[].label` in the page representation; the OpenConnector source maps `breadcrumb` from it. When the source doesn't supply one, `XwikiProvider::normalizeRow()` derives a coarse breadcrumb from `space` + `title`.

**Why**: XWiki's hierarchical structure is meaningful — two pages can have the same title in different spaces. Breadcrumb disambiguates.

## Files Affected

### Backend (new)
- `lib/Service/Integration/Providers/XwikiProvider.php` — `IntegrationProvider`: metadata getters, `authRequirements()` (`type: 'external'`, configured via OpenConnector), `isEnabled()` (mirrors `IAppManager::isInstalled('openconnector')`), `list/get/create/update/delete` (all via `router->call(...)` with the `{register, schema, object}` context), `health()` (defers to `router->probe()`), `normalizeList/normalizeRow` shaping
- `docs/Integrations/xwiki-openconnector-source.yaml` — the OpenConnector source-config template to import (the repo's `config/` directory is gitignored, so it lives under `docs/Integrations/` rather than `config/openconnector-sources/`)
- `docs/Integrations/xwiki.md` — user-facing setup + usage guide
- `tests/Unit/Service/Integration/Providers/XwikiProviderTest.php` — 10 tests / 38 assertions (OpenConnector router mocked)

### Backend (modified)
- `lib/AppInfo/Application.php` — DI service binding for `XwikiProvider`, and added to the `bootBuiltinIntegrationProviders()` provider list so it self-registers with `IntegrationRegistry` at `boot()`. (The umbrella registers providers via explicit `addProvider()` rather than a DI tag — modern Nextcloud has no public `queryAll(<tag>)`; documented in the umbrella's design.)

### Frontend (new — `@conduction/nextcloud-vue`)
- `src/components/CnXwikiTab/` — list with breadcrumb, link-by-URL-or-path form, unlink (removes the OR sub-resource pairing only, never deletes in XWiki), reconnect/unavailable banner on a 503; emits `linked` / `unlinked`
- `src/components/CnXwikiCard/` — surface-aware (AD-19): detail-page text preview + "Open in XWiki" link; compact list on the dashboard surfaces; title+breadcrumb chip on `single-entity` (for `referenceType: 'xwiki'` properties)
- `src/integrations/builtin/xwiki.js` — `xwikiIntegration` descriptor + `registerXwikiIntegration()` (a *leaf*, not part of `builtinIntegrations` — OpenRegister's bundle registers it explicitly when OpenConnector is installed; skip-on-collision so a consuming app can override `xwiki`)
- barrels + 4 doc files + tests (`CnXwikiTab.spec.js` ×7, `CnXwikiCard.spec.js` ×8, `tests/integrations/xwiki.spec.js` ×6)

## Implementation deviations (as-built vs original spec)

| Original spec said | As built | Why |
|---|---|---|
| `requiredApp: null` | `requiredApp: 'openconnector'` | The integration genuinely needs the OpenConnector app (it carries the `xwiki` source + credentials) — `null` would mislead the admin UI. `ExternalIntegrationRouter` still degrades gracefully if OpenConnector is absent or the source is missing. |
| Source template at `config/openconnector-sources/xwiki.yaml` | `docs/Integrations/xwiki-openconnector-source.yaml` | The repo's `config/` directory is gitignored. |
| `Application.php (DI-tag)` | `addProvider()` at `boot()` | Matches the umbrella's registration mechanism (no public NC `queryAll`). |
| Parity check `check-integration-parity.sh` | `check-integration-parity.js` (Node) | Matches the `@conduction/nextcloud-vue` repo's existing script convention (`check-docs.js`, `check-jsdoc.js`); wired into that repo's CI + pre-commit hook, and into hydra's `run-hydra-gates.sh` as gate-15. |

## Risks

| Risk | Mitigation |
|---|---|
| XWiki instance versions vary (5.x, 10.x+, 14.x+) | The OpenConnector source normalises field-name drift; `XwikiProvider::normalizeRow()` reads several aliases per field; the provider stays version-agnostic. Verified live against `xwiki:lts` 17.10. |
| Basic auth vs OAuth — mixed customer setups | Auth is configured on the OpenConnector source; `authRequirements()` declares `supports: ['basic', 'oauth2']`; OR's admin UI surfaces the source's auth status + a "Configure" deep-link into OpenConnector. |
| Large pages slow preview render | Text strip to ~500 chars; the rendered body is fetched only for the detail-page surface (and the single-entity lookup), not for the compact list. |
| Permission mismatches (user has NC access but not XWiki access) | The integration inherits the object's RBAC + OpenConnector's; a "No access to page" placeholder is shown; internal errors aren't leaked (the controller translates `ProviderUnavailableException` to 503 + a `reason`, the UI shows a banner). |
