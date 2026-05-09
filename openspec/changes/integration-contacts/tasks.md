# Tasks: Integration — Contacts

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
