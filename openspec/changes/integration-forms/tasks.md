# Tasks: Integration — Forms

## Backend

- [~] Create `FormLink` entity + `FormLinkMapper` + migration for `openregister_form_links` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `FormResponseService` wrapping Forms REST API (list responses, link/unlink, form-mapping management) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `FormResponsesController` with sub-resource endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] Create `FormsProvider` — id='forms', label='Forms', icon='ClipboardText', group='workflow', requiredApp='forms', storage='link-table'
- [~] DI-tag in `Application.php` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add routes to `appinfo/routes.php` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit tests for service + provider — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnFormsTab.vue` — linked responses list, "Link response" and "Map form for future responses" affordances, read-only response viewer — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnFormsCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: recent response count
  - `app-dashboard`: scoped
  - `detail-page`: responses list with inline question/answer preview
  - `single-entity`: chip with form name + submitted-at
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/forms.js` — register with `referenceType: 'forms'` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire + barrels — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate passes; nl+en translations; PHPCS/PHPMD/PHPStan/Psalm strict; ESLint clean — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: install Forms, link a response, verify display — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Form-mapping: configure a form mapping, submit a response, verify auto-link — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
