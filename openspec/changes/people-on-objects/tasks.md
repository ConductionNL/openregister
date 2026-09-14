# Tasks: people on objects

## 1. Data

- [x] 1.1 Migration `Version1Date20260913180000`: add `user_id` (string 64, nullable, indexed), `valid_from` and `valid_until` (date, nullable), `note` (text, nullable); make `addressbook_id` and `contact_uri` nullable; replace `idx_contact_object_uid_uniq` with `(object_uuid, contact_uid, role)`. Idempotent. Unit test.
- [x] 1.2 Bump `<version>` in `appinfo/info.xml` (the migration bump script).
- [x] 1.3 `ContactLink`: fields `userId`, `validFrom`, `validUntil`, `note`; JSON adds `kind`, `userId`, `validFrom`, `validUntil`, `note`, `active`. Unit tests.
- [x] 1.4 `ContactLinkMapper`: `findByObjectContactAndRole`, `findByUserId`. `findByObjectAndContact` unchanged.

## 2. Vocabulary

- [x] 2.1 `Schema::validateLinkRolesValue` and `linkRoles` in the validated keys; `Schema::getLinkRoles(): array` normalising strings to entries. Unit tests in `SchemaTest`.

## 3. Service and events

- [x] 3.1 `PersonLinkService`: `link`, `update`, `unlink`, `listForObject` (results, total, byRole, roles), `roleVocabulary`; user lookup through `IUserManager`; events through `IEventDispatcher`. Unit tests.
- [x] 3.2 `PersonLinkedEvent`, `PersonLinkUpdatedEvent`, `PersonUnlinkedEvent`.
- [x] 3.3 `ContactService`: guard vCard paths on the kind in `getContactsForObject`, `updateRole`, `unlinkContact`, `deleteLinksForObject`; include user links in `getObjectsForContact`. Unit tests.

## 4. API

- [x] 4.1 `ContactsController`: `index` through `listForObject`; `create` through `link` (userId or addressbookId+contactUri); `update` implemented; `destroy` with `role` filter. Unit tests.

## 5. Docs and checks

- [x] 5.1 `docs/Integrations/contacts.md`: user links, validity, `linkRoles` replaces `x-contactRoles`.
- [ ] 5.2 `openspec validate people-on-objects --strict`; lint, phpcs, phpmd, phpstan, psalm on touched files; PHPUnit on touched suites; `composer check:strict` once before the PR.
