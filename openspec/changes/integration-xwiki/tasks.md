# Tasks: Integration — XWiki

## Backend

- [ ] Create `lib/Service/Integration/Providers/XwikiProvider.php` — id='xwiki', label='Articles', icon='FileDocumentMultiple', group='external', requiredApp=null, storage='external', `getOpenConnectorSource()='xwiki'`
- [ ] Declare auth requirements (basic or oauth2, configurable at source level)
- [ ] Delegate CRUD to `ExternalIntegrationRouter`
- [ ] Ship OpenConnector source config template `config/openconnector-sources/xwiki.yaml`
- [ ] DI-tag
- [ ] Unit tests (OpenConnector client mocked)

## Frontend — Tab

- [ ] `CnXwikiTab.vue` — linked pages with title + full breadcrumb; link-by-URL (parses to space.page) or link-by-path; unlink; auth-expired banner
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnXwikiCard.vue`:
  - `user-dashboard`: recent linked pages
  - `app-dashboard`: scoped
  - `detail-page`: linked pages list + text preview (first 500 chars, macros not executed) + link to full page
  - `single-entity`: page-title + breadcrumb chip
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/xwiki.js` — register with `referenceType: 'xwiki'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: configure OpenConnector xwiki source, link a page, verify breadcrumb in tab; text preview on detail-page
- [ ] Link-by-URL test: paste full URL, verify resolution to canonical space.page
- [ ] Auth test; hide test; reference-property test
- [ ] Security: verify XWiki macros are NOT executed in preview (text-only)
