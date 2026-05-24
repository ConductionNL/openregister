# Tasks: Integration — Forms

## Backend

- [ ] Create `FormLink` entity + `FormLinkMapper` + migration for `openregister_form_links` (deferred — Tier-2 follow-up; bespoke link-table + service + controller stay out of the Bucket-A stub-completion scope per ADR-019)
- [ ] Create `FormResponseService` wrapping Forms REST API (list responses, link/unlink, form-mapping management) (deferred — same Tier-2 follow-up)
- [ ] Create `FormResponsesController` with sub-resource endpoints (deferred — same Tier-2 follow-up)
- [x] Create `FormsProvider` — id='forms', label='Forms', icon='ClipboardText', group='workflow', requiredApp='forms', storage='link-table'
- [x] DI-tag in `Application.php` (already present via the greenfield-providers registration block)
- [ ] Add routes to `appinfo/routes.php` (deferred — depends on FormResponsesController)
- [x] Unit tests for provider — `tests/Unit/Service/Integration/Providers/FormsProviderTest.php` covers metadata, happy-path (form + submissions surfaced), absent-app (graceful empty + missingApp health), empty-result, container-error fallback, health-ok

## Frontend — Tab

- [ ] `CnFormsTab.vue` — linked responses list, "Link response" and "Map form for future responses" affordances, read-only response viewer (deferred — bespoke Tab + Widget components are out of this change's scope per the refreshed proposal acceptance criteria; the generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` continues to serve)
- [ ] Barrel + tests (deferred — same)

## Frontend — Widget

- [ ] `CnFormsCard.vue` (deferred — same)
- [ ] Barrel + surface tests (deferred — same)

## Registration

- [x] Generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` (already shipped — `referenceType` follow-up tracked separately)

## Quality

- [x] Parity gate passes (`nextcloud-vue/scripts/check-integration-parity.js` green); PHPCS / Psalm / PHPStan strict pass on the new backend files; PHPMD parity vs. baseline (no new violations introduced — same `UnusedFormalParameter` shape every leaf provider ships with for the interface-contract `$register/$schema/$filters` triple); nl+en translations not introduced (provider label routes through `IL10N::t` — existing translation infrastructure)

## Acceptance verification

- [ ] E2E: install Forms, link a response, verify display (deferred — depends on the Tier-2 frontend)
- [ ] Form-mapping: configure a form mapping, submit a response, verify auto-link (deferred — depends on FormResponsesController post-submit hook)
- [ ] Hide test; reference-property test (deferred — depends on the Tier-2 frontend)
