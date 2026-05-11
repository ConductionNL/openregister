# Tasks: Integration — Forms

## Backend

- [ ] Create `FormLink` entity + `FormLinkMapper` + migration for `openregister_form_links`
- [ ] Create `FormResponseService` wrapping Forms REST API (list responses, link/unlink, form-mapping management)
- [ ] Create `FormResponsesController` with sub-resource endpoints
- [ ] Create `FormsProvider` — id='forms', label='Forms', icon='ClipboardText', group='workflow', requiredApp='forms', storage='link-table'
- [ ] DI-tag in `Application.php`
- [ ] Add routes to `appinfo/routes.php`
- [ ] Unit tests for service + provider

## Frontend — Tab

- [ ] `CnFormsTab.vue` — linked responses list, "Link response" and "Map form for future responses" affordances, read-only response viewer
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnFormsCard.vue`:
  - `user-dashboard`: recent response count
  - `app-dashboard`: scoped
  - `detail-page`: responses list with inline question/answer preview
  - `single-entity`: chip with form name + submitted-at
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/forms.js` — register with `referenceType: 'forms'`
- [ ] Wire + barrels

## Quality

- [ ] Parity gate passes; nl+en translations; PHPCS/PHPMD/PHPStan/Psalm strict; ESLint clean

## Acceptance verification

- [ ] E2E: install Forms, link a response, verify display
- [ ] Form-mapping: configure a form mapping, submit a response, verify auto-link
- [ ] Hide test; reference-property test
