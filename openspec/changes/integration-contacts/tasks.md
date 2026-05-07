# Tasks: Integration — Contacts

> **ADR-028 task-cap waiver**: this leaf has 23 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [ ] Create `lib/Service/Integration/Providers/ContactsProvider.php` — id='contacts', label='Contacts', icon='AccountMultiple', group='core', requiredApp='contacts', storage='link-table'
- [ ] DI-tag in `Application.php`
- [ ] Unit test

## Frontend — Tab

- [ ] `CnContactsTab.vue` — role-grouped list (applicants, handlers, advisors, other); link-existing + create-new; reverse-lookup flyout
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnContactCard.vue`:
  - `user-dashboard`: contacts linked across user's objects by most recent
  - `app-dashboard`: scoped to app
  - `detail-page`: full list with role-grouped sections
  - `single-entity`: canonical person chip (avatar + name + role context + hover details)
- [ ] Shared vCard cache (reactive, keyed by uuid) for single-entity perf
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/contacts.js` — register with `referenceType: 'contacts'`
- [ ] Wire + barrels

## Quality

- [ ] Parity gate passes
- [ ] nl + en translations
- [ ] PHPCS/PHPMD/PHPStan/Psalm strict pass
- [ ] ESLint clean

## Acceptance verification

- [ ] E2E: install Contacts, link a person as "applicant", verify role-grouping in tab; reverse lookup returns linked objects
- [ ] Widget perf: detail grid with 20 person-reference properties shows ≤1 Mail API fetch per unique contact
- [ ] Hide test
- [ ] Reference-property test
