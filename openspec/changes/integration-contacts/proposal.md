# Integration: Contacts

## Problem

`ContactService` (381 LOC) manages vCard links with a first-class `role` field and reverse lookup. No UI. Case handlers can't see which people are associated with an object without leaving OR.

## Context

- **Backend shipped:** [ContactService.php](openregister/lib/Service/ContactService.php), [ContactsController.php](openregister/lib/Controller/ContactsController.php) — link existing/create new, vCard X-OPENREGISTER-* + `openregister_contact_links` dual storage, role field, reverse lookup "all objects where person X is applicant"
- **Required NC app:** `contacts`
- **Storage:** `link-table` + CardDAV X-OPENREGISTER-* properties (dual)
- **Key feature:** Role ("applicant", "handler", "advisor") is a first-class field per AD-4 of `nextcloud-entity-relations`
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`ContactsProvider` + `CnContactsTab` (grouped by role, inline link/create) + `CnContactCard` widget. The `single-entity` surface is heavily used — this is the integration that enables reference-property rendering for ANY schema property typed as a person reference.

## Scope

**In scope:** Provider, tab with role-grouped display, widget with 4 surfaces, registration, tests, nl+en, spec delta.

**Out of scope:** Contact editing beyond what `ContactService` already exposes; contact merging; vCard field extensions.

## Acceptance criteria

- [ ] Contacts tab appears when Contacts installed + schema has `contacts` in linkedTypes
- [ ] Contacts grouped visually by role in the tab
- [ ] User can link an existing contact with role selection
- [ ] User can create a new contact with role
- [ ] Reverse lookup: from a contact, see all linked OR objects
- [ ] Reference-property `referenceType: 'contacts'` renders contact chip (THE key enabler for person-reference properties everywhere)
- [ ] Parity gate passes; nl+en done
