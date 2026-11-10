## 1. Related objects (uses / used / contracts)

- [x] 1.1 Inject `DeepLinkRegistryService` (and the URL generator) into the single object-URL stamping site (controller layer or one private helper) used by `/uses`, `/used`, `/contracts`.
- [x] 1.2 Stamp `url` on each related object by calling `DeepLinkRegistryService::resolveUrl(registerId, schemaId, objectData)` with a flat `objectData` (top-level `uuid`/`register`/`schema` + object keys), mirroring `ObjectsProvider`.
- [x] 1.3 Add the `openregister.objects.show` fallback URL (register, schema, id) when `resolveUrl()` returns `null`; make resolution defensive so a failure omits `url` without altering returned records.

## 2. Leaf URLs (contacts / events / tasks / deck)

- [x] 2.1 In `ContactService::getContactsForObject`, resolve `addressbookId → addressbook URI` via the injected `CardDavBackend` and build `url` = `/apps/contacts/All contacts/<base64url(contactUid~addressbookUri)>`.
- [x] 2.2 In `CalendarEventService`, resolve the calendar URI and build the Calendar app dav-path event `url` on each event record.
- [x] 2.3 In `TaskService`, build `url` = `/apps/tasks/#/calendars/{calendarUri}/tasks/{taskUri}` on each task record.
- [x] 2.4 In `DeckCardService`, build `url` = `/apps/deck/board/{boardId}/card/{cardId}` from ids already on the record.
- [x] 2.5 Reuse the existing files `accessUrl`/permalink (no new URL); leave notes `url` unset; make every leaf URL build defensive (omit on unresolvable URI, never throw).

## 3. Tests & quality

- [x] 3.1 Unit test: related object `url` from a mocked `DeepLinkRegistryService` registration, and the `openregister.objects.show` fallback when the resolver returns `null` (use nil UUID `00000000-0000-0000-0000-000000000000`).
- [x] 3.2 Unit test: one leaf service (e.g. `DeckCardService` or `ContactService`) builds the expected `url`, and omits `url` when its identifier/URI cannot be resolved.
- [x] 3.3 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix any issues touched by this change.

## 4. Acceptance criteria

- Every record from `/uses`, `/used`, `/contracts` carries `url` (registered template when present, else `openregister.objects.show`); object-URL logic exists at exactly one site with no duplication of `DeepLinkRegistryService`.
- Contacts/events/tasks/deck leaf records carry the owning app's deep-link `url`; files reuse `accessUrl`; notes have no `url`.
- URL resolution never changes which records are returned, their other fields, or the relation error handling.
- `kind: code` only — no schema, register-config, or seed-data changes.
- No regression in opencatalogi or softwarecatalog relation responses.
- nc-vue `CnRelatedObjectsWidget` deep-links from the stamped `url` with no frontend change (consumer contract; widget NOT modified here).
