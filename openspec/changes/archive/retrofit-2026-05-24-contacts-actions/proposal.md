# Retrofit — contacts-actions (per-object link CRUD, reverse lookup, integration registry, frontend)

Describes observed behavior of 32 methods under the `contacts-actions` capability as 5 new REQs extending the existing spec. Code already exists — this change retroactively specifies it.

The original `contacts-actions` spec documents the **outbound ContactsMenu provider** path (matching a contact entry to OpenRegister entities and decorating the contacts-menu popup with action links). The cluster picked up by `/opsx-coverage-scan` is a complementary **inbound + management** surface: per-object contact-link CRUD, reverse lookup, match-API enrichment, the `ContactsProvider` integration-registry adapter, and the `ContactsTab` frontend. These REQs cover that surface.

## Affected code units

- `lib/Controller/ContactsController.php` (`index`, `create`, `update`, `destroy`, `objects`, `validateObject`, `match`, `enrichMatches`)
- `lib/Service/ContactService.php` (`getContactsForObject`, `linkContact`, `createAndLinkContact`, `updateRole`, `unlinkContact`, `getObjectsForContact`, `deleteLinksForObject`, `findUserAddressbook`)
- `lib/Service/ContactMatchingService.php` (`invalidateCache`, `invalidateCacheForObject`, `getRelatedObjectCounts`)
- `lib/Service/Integration/Providers/ContactsProvider.php` (`list`, `update`, `delete`)
- `src/components/object-relations/ContactsTab.vue` (`fetchContacts`)
- `src/store/modules/object-relations/contacts.js` (`useContactRelationsStore`)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes from the source.
- Draft REQs that match observed behavior, not aspirational design.
- Notes section flags two observed bugs that were left unfixed (per "DROP FPs / flag drifts" guardrail):
  - `ContactsController::destroy()` passes two strings to `ContactService::unlinkContact(int $linkId)` — runtime TypeError on every DELETE.
  - `ContactsController::update()` returns HTTP 501 even though `ContactService::updateRole()` is fully implemented.

## REQ scope

- **REQ-010** — Per-object contact link CRUD via `ContactsController` + `ContactService` (link existing / create-new / list / destroy)
- **REQ-011** — Reverse lookup: find OR objects linked to a vCard contact (`/api/contacts/{contactUid}/objects`)
- **REQ-012** — Match-API enrichment with deep-link URLs and icons (`enrichMatches`)
- **REQ-013** — Integration-registry adapter exposing contacts via the generic-integrations provider contract (`ContactsProvider`)
- **REQ-014** — Frontend `ContactsTab` + Pinia store with graceful 501 degradation when NC Contacts is missing

## Deferred

The IntegrationProvider metadata getters (`getId`, `getLabel`, `getIcon`, `getGroup`, `getRequiredApp`, `getStorageStrategy`, `isEnabled`, `health`) and the various `__construct` entries are dropped as FPs — they are self-documenting interface-contract implementations and don't carry independent observable behavior. The 6 entries already triaged as DROPs from sibling clusters are honored.

Source: `/tmp/or-scan/rspec-cluster-contacts-actions.json` generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
