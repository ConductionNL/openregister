# Tasks: Integration — XWiki

## Backend — [ConductionNL/openregister PR for `feature/1326/integration-xwiki`]

- [x] Create `lib/Service/Integration/Providers/XwikiProvider.php` — id='xwiki', label='Articles', icon='FileDocumentMultiple', group='external', requiredApp='openconnector' (the NC app that carries the source + credentials — more accurate than `null` for the admin UI), storage='external', `getOpenConnectorSource()='xwiki'`
- [x] Declare auth requirements — `authRequirements()` returns `{ type: 'external', configuredVia: 'openconnector', source: 'xwiki', supports: ['basic', 'oauth2'] }` (the actual auth is picked on the OpenConnector source)
- [x] Delegate CRUD to `ExternalIntegrationRouter` — list/get/create/update/delete all go through `router->call(...)` with the `{register, schema, object}` context; `health()` defers to `router->probe()`; `normalizeRow()` shapes rows to `{ id, reference, title, space, page, breadcrumb, url, content }`
- [x] Ship the OpenConnector source config template — at `docs/Integrations/xwiki-openconnector-source.yaml` (the repo's `config/` dir is gitignored, so it lives under `docs/Integrations/`); also `docs/Integrations/xwiki.md` user guide
- [x] Register the provider — DI service binding in `Application.php` + added to `bootBuiltinIntegrationProviders()`'s list so it self-registers with `IntegrationRegistry` at boot (the umbrella uses explicit `addProvider()` rather than DI tags — NC has no public `queryAll`)
- [x] Unit tests — `tests/Unit/Service/Integration/Providers/XwikiProviderTest.php` (10 tests, 38 assertions; OpenConnector router mocked); verified `composer phpcs` / `phpmd` / `psalm` clean + `phpunit-unit.xml` green in the container

## Frontend — Tab — [ConductionNL/nextcloud-vue#213]

- [x] `CnXwikiTab.vue` — linked pages with title + full breadcrumb; link-by-URL (resolved server-side to `Space.Page`) or link-by-path; unlink (removes the OR sub-resource pairing only, never deletes in XWiki); reconnect/unavailable banner on a 503; emits `linked` / `unlinked`
- [x] Barrel (`src/components/CnXwikiTab/index.js` + `src/components/index.js` + `src/index.js`) + tests (`tests/components/CnXwikiTab.spec.js` — 7)

## Frontend — Widget — [ConductionNL/nextcloud-vue#213]

- [x] `CnXwikiCard.vue` (surface-aware, AD-19):
  - `user-dashboard`: recent linked pages (compact list)
  - `app-dashboard`: same compact list
  - `detail-page`: linked pages list + text preview of the first page (HTML stripped to plain text, first ~500 chars, macros NOT executed — AD-1) + "Open in XWiki" link
  - `single-entity`: title + breadcrumb chip resolved from the `value` prop (for `referenceType: 'xwiki'` properties), with a minimal-chip fallback when the lookup fails
- [x] Barrel + surface tests (`tests/components/CnXwikiCard.spec.js` — 8)

## Registration — [ConductionNL/nextcloud-vue#213]

- [x] `src/integrations/builtin/xwiki.js` — `xwikiIntegration` descriptor (id `xwiki`, group `external`, requiredApp `openconnector`, referenceType `xwiki`, tab `CnXwikiTab`, widget `CnXwikiCard`) + `registerXwikiIntegration(registry?)`. It is a leaf, NOT part of `builtinIntegrations` — OpenRegister's bundle calls `registerXwikiIntegration()` explicitly (skip-on-collision: first wins). Exported from `src/integrations/index.js` + `src/index.js`. Tests: `tests/integrations/xwiki.spec.js` — 6.

## Quality

- [x] Parity gate — `tab` + `widget` both present (the registry throws at `register()` if not; `check-integration-parity.js` covers the built-in set; the leaf's own descriptor is asserted in `xwiki.spec.js`)
- [x] nl + en — all user-facing strings wrapped: PHP `$l->t(...)`, JS `t('nextcloud-vue', ...)` (the `l10n/*.json` are build-extracted, not hand-edited — matches the repo convention)
- [x] strict / ESLint — `composer phpcs|phpmd|psalm` clean on `XwikiProvider.php`; `npm run lint` clean on `CnXwikiTab.vue` / `CnXwikiCard.vue` / `xwiki.js`

## Acceptance verification

- [x] Link-by-URL test — `CnXwikiTab.spec.js` asserts the POST body is `{ reference: '<full URL>' }`; the OpenConnector source resolves it to a canonical `Space.Page` (covered by the source-config template's `create` endpoint)
- [x] Reference-property test — `CnXwikiCard.spec.js` `single-entity` surface renders a chip from `value` and falls back to a minimal chip on lookup failure; `referenceType: 'xwiki'` wiring is covered by the umbrella's `CnFormDialog` / `CnDetailGrid` referenceType tests
- [x] Security: macros NOT executed in preview — `CnXwikiCard.spec.js` asserts the detail-page preview strips all HTML tags + the `<script>` body + treats macro markup (`{{velocity}}…`) as inert text; the source returns already-rendered HTML and the widget only ever reads its text content, never injects it into the DOM
- [ ] Live E2E (configure an OpenConnector xwiki source against a running XWiki, link a page, verify the breadcrumb in the tab + the text preview on the detail page; auth-expired/hide behaviour) — deferred until the umbrella + leaf PRs merge and land in a deployed Nextcloud + a reachable XWiki instance

## Wave-5 frontend (strict layout) — [ConductionNL/nextcloud-vue feature/integration-xwiki-wave5]

- [x] Components under `src/integrations/builtin/xwiki/` — `CnXwikiTab.vue` (page-tree + breadcrumb, auth-status banners) + `CnXwikiCard.vue` (4-surface widget with auth-state badge) + `__tests__/CnXwikiCard.spec.js` (8 tests) + `__tests__/CnXwikiTab.spec.js` (7 tests)
- [x] Descriptor at `src/integrations/builtin/xwiki.js` — `xwikiIntegration` with `storageStrategy: 'external'`, `requiredApp: 'openconnector'`, `referenceType: 'xwiki'`, `group: 'external'`, `order: 32`
- [x] No touches to `builtin/index.js` or `leaves.js` — descriptor is a standalone leaf module that consumers/coordinators wire in explicitly
- [x] External-integration UX considerations — banner branches on 503 `details.cause` (AD-23 contract): `openconnector-down` / `openconnector-source-missing` → "Configure XWiki connection" CTA pointing at OpenConnector's source admin page; `provider-auth` → "Reconnect" CTA + "XWiki returned 401" message; `upstream-service-down` → "XWiki is currently unavailable" + Retry; dashboard surface collapses banner to a compact auth-status badge (configured / not configured / unhealthy)
- [x] Quality gates — `npx eslint src/integrations/builtin/xwiki src/integrations/builtin/xwiki.js` clean; `npx jest src/integrations/builtin/xwiki` → 15/15 green; `node scripts/check-integration-parity.js` exit 0
- [x] `jest.config.js` `testMatch` extended with `<rootDir>/src/**/__tests__/*.spec.js` (already done by wave-4.3 polls; reused here)
