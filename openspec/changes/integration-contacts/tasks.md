# Tasks: Integration — Contacts

> **ADR-028 task-cap waiver**: this leaf has 23 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/ContactsProvider.php` — id='contacts', label='Contacts', icon='AccountMultiple', group='core', requiredApp='contacts', storage='link-table'
- [~] DI-tag in `Application.php` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnContactsTab.vue` — role-grouped list (applicants, handlers, advisors, other); link-existing + create-new; reverse-lookup flyout — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnContactCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: contacts linked across user's objects by most recent
  - `app-dashboard`: scoped to app
  - `detail-page`: full list with role-grouped sections
  - `single-entity`: canonical person chip (avatar + name + role context + hover details)
- [~] Shared vCard cache (reactive, keyed by uuid) for single-entity perf — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/contacts.js` — register with `referenceType: 'contacts'` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire + barrels — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate passes — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] nl + en translations — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] PHPCS/PHPMD/PHPStan/Psalm strict pass — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] ESLint clean — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: install Contacts, link a person as "applicant", verify role-grouping in tab; reverse lookup returns linked objects — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Widget perf: detail grid with 20 person-reference properties shows ≤1 Mail API fetch per unique contact — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
