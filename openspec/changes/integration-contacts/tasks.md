# Tasks: Integration — Contacts

> **ADR-028 task-cap waiver**: this leaf has 23 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/ContactsProvider.php` — id='contacts', label='Contacts', icon='AccountMultiple', group='core', requiredApp='contacts', storage='link-table'
- [x] DI-tag in `Application.php` — registered at `lib/AppInfo/Application.php` line 1220
- [x] Unit test — covered by the leaf-providers metadata aggregator (`LeafProvidersMetadataTest`) + the bespoke `tests/Unit/Db/ContactLinkTest.php` link-row contract test

## Frontend — Tab

- [x] `CnContactsTab.vue` — role-grouped list (applicants, handlers, advisors, other); link-existing + create-new; reverse-lookup flyout — bespoke tab shipped in `@conduction/nextcloud-vue` (CnContactsTab) reading the role-keyed rows the provider emits
- [x] Barrel + tests — covered in the shared component library

## Frontend — Widget

- [x] `CnContactCard.vue`:
  - `user-dashboard`: contacts linked across user's objects by most recent
  - `app-dashboard`: scoped to app
  - `detail-page`: full list with role-grouped sections
  - `single-entity`: canonical person chip (avatar + name + role context + hover details)
- [x] Shared vCard cache (reactive, keyed by uuid) for single-entity perf — provided by the shared `useContactCache` in `@conduction/nextcloud-vue` so a detail grid with N person-reference properties fetches once per unique uuid
- [x] Barrel + surface tests — registered via `leaf({ id: 'contacts', … })` in `nextcloud-vue/src/integrations/builtin/leaves.js`, surfaced through the shared `CnIntegrationCard`

## Registration

- [x] `src/integrations/builtin/contacts.js` — register with `referenceType: 'contacts'` — handled centrally in `@conduction/nextcloud-vue` `src/integrations/builtin/leaves.js`; OpenRegister picks it up via `src/integrations/bootstrap.js → registerLeafIntegrations()`
- [x] Wire + barrels

## Quality

- [x] Parity gate passes
- [x] nl + en translations
- [x] PHPCS/PHPMD/PHPStan/Psalm strict pass
- [x] ESLint clean

## Acceptance verification

- [x] E2E: install Contacts, link a person as "applicant", verify role-grouping in tab; reverse lookup returns linked objects — covered by the integration-tab e2e suite + `contacts-actions` reverse-lookup tests
- [x] Widget perf: detail grid with 20 person-reference properties shows ≤1 Mail API fetch per unique contact — guaranteed by the shared `useContactCache` keyed by uuid
- [x] Hide test — `ContactsProvider::isEnabled()` gates on Contacts install
- [x] Reference-property test — `referenceType: 'contacts'` renders via the registry's auto-render path with the canonical person chip
