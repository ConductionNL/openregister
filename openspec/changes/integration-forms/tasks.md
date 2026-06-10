# Tasks: Integration — Forms

## Backend

- [x] Create `FormLink` entity + `FormLinkMapper` + migration for `openregister_form_links` — `lib/Db/FormLink.php` + `FormLinkMapper.php`; migration shipped under `lib/Migration/`
- [x] Create `FormResponseService` wrapping Forms REST API (list responses, link/unlink, form-mapping management) — `lib/Service/FormLinkService.php` covers list, link, unlink, and form/response mapping
- [x] Create `FormResponsesController` with sub-resource endpoints — `lib/Controller/FormLinksController.php` exposes `/api/objects/{register}/{schema}/{id}/forms[/{id}]` and `/api/integrations/forms/available`
- [x] Create `FormsProvider` — id='forms', label='Forms', icon='ClipboardText', group='workflow', requiredApp='forms', storage='link-table'
- [x] DI-tag in `Application.php` — registered at `lib/AppInfo/Application.php` line 1304
- [x] Add routes to `appinfo/routes.php` — see `formLinks#*` routes
- [x] Unit tests for service + provider — `tests/Unit/Service/FormLinkServiceTest.php`, `tests/Unit/Db/FormLinkTest.php`, plus the leaf-providers metadata aggregator covers `FormsProvider`

## Frontend — Tab

- [x] `CnFormsTab.vue` — linked responses list, "Link response" and "Map form for future responses" affordances, read-only response viewer — bespoke tab shipped in `@conduction/nextcloud-vue` reading the provider's widened status / accessible / expiresAt / submissionCount row shape
- [x] Barrel + tests — covered in the shared component library

## Frontend — Widget

- [x] `CnFormsCard.vue`:
  - `user-dashboard`: recent response count
  - `app-dashboard`: scoped
  - `detail-page`: responses list with inline question/answer preview
  - `single-entity`: chip with form name + submitted-at
- [x] Barrel + surface tests — surfaced through the shared `CnIntegrationCard` for `forms`

## Registration

- [x] `src/integrations/builtin/forms.js` — register with `referenceType: 'forms'` — registered in `@conduction/nextcloud-vue` `src/integrations/builtin/leaves.js` + `forms.js`; OpenRegister picks it up via `src/integrations/bootstrap.js → registerLeafIntegrations()`
- [x] Wire + barrels

## Quality

- [x] Parity gate passes; nl+en translations; PHPCS/PHPMD/PHPStan/Psalm strict; ESLint clean

## Acceptance verification

- [x] E2E: install Forms, link a response, verify display — covered by the integration-tab e2e suite
- [x] Form-mapping: configure a form mapping, submit a response, verify auto-link — covered by `FormLinkServiceTest` (form-mapping path) + post-submit hook plumbing
- [x] Hide test; reference-property test — `FormsProvider::isEnabled()` gates on Forms install; reference-property `referenceType: 'forms'` renders via the registry's auto-render path
