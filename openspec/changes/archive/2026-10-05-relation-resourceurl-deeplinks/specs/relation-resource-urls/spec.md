## ADDED Requirements

### Requirement: Related objects SHALL carry a deep-link `url` resolved via the shared registry

Every related OpenRegister object returned by the relation-objects endpoints — `GET /api/objects/{register}/{schema}/{id}/uses`, `/used`, and `/contracts` — SHALL include a `url` field holding the canonical URL to open that object in the UI. The `url` MUST be produced by reusing the existing `OCA\OpenRegister\Service\DeepLinkRegistryService::resolveUrl(registerId, schemaId, objectData)` — the same resolver `lib/Search/ObjectsProvider.php` uses for unified search — so consuming apps' registered URL templates (registered via `DeepLinkRegistrationEvent`) are honoured. When `resolveUrl()` returns `null` (no registration matches the object's register/schema), the system SHALL fall back to OpenRegister's own object route `openregister.objects.show` with `register`, `schema`, and the object `id`/`uuid`, mirroring `ObjectsProvider`'s fallback. No new object-URL logic SHALL be introduced; `DeepLinkRegistryService` remains the single source of truth shared with search. The resolve-and-fallback logic SHALL exist at a single site (not duplicated across the three endpoints).

#### Scenario: Related object resolves to a registered consuming-app URL
- **WHEN** a consuming app has registered a URL template for the related object's (register, schema) pair and the `/uses` endpoint returns that object
- **THEN** the object's `url` field is the value returned by `DeepLinkRegistryService::resolveUrl()` for that object (the consuming app's deep-link, e.g. `/apps/shillinq/chart-of-accounts/{uuid}`)

#### Scenario: Related object with no registration falls back to the OpenRegister object route
- **WHEN** no app has registered a template for the related object's (register, schema) pair and the `/used` or `/contracts` endpoint returns that object
- **THEN** the object's `url` field is the `openregister.objects.show` route URL built from the object's register, schema, and id (e.g. for the nil object `00000000-0000-0000-0000-000000000000`)

#### Scenario: URL resolution never alters which records are returned
- **WHEN** URL resolution fails or throws for a related object
- **THEN** the endpoint still returns that object with all its existing fields unchanged, and `url` is omitted for that record rather than the request failing

### Requirement: Leaf relation records SHALL carry the owning app's deep-link `url`

Every leaf record returned by `GET /api/objects/{register}/{schema}/{id}/relations` SHALL include a `url` field — the owning Nextcloud app's deep-link to the specific item — built server-side inside the leaf service that produces the record. The system SHALL resolve URI-based identifiers the records do not expose today and build each URL as follows:

- **Contacts** (`ContactService::getContactsForObject`): `/apps/contacts/All contacts/<base64url(uid~addressbookUri)>` for NC Contacts 8.x, resolving the addressbook URI from the numeric `addressbookId` via the CardDAV backend and combining it with `contactUid`.
- **Calendar events** (`CalendarEventService`): the Calendar app's dav-path event URL, resolving the calendar URI for the event.
- **Tasks** (`TaskService`): `/apps/tasks/#/calendars/{calendarUri}/tasks/{taskUri}`.
- **Deck** (`DeckCardService`): `/apps/deck/board/{boardId}/card/{cardId}` using the board and card ids already present on the record.
- **Files**: SHALL reuse the existing `accessUrl`/permalink the record already carries and SHALL NOT rebuild it.
- **Notes** (NC Comments): SHALL leave `url` unset, because NC Comments has no standalone app page.

Leaf URL construction MUST be defensive: if a required identifier or URI cannot be resolved, the service SHALL omit `url` for that record rather than throw.

#### Scenario: Contact record deep-links into the Contacts app
- **WHEN** the `/relations` endpoint returns a contact whose link has a numeric `addressbookId`, a `contactUri`, and a `contactUid`
- **THEN** the contact's `url` is `/apps/contacts/All contacts/<base64url(contactUid~addressbookUri)>`, where the addressbook URI was resolved from `addressbookId` via the CardDAV backend

#### Scenario: Deck card deep-links into the Deck app
- **WHEN** the `/relations` endpoint returns a deck card with a board id and a card id
- **THEN** the card's `url` is `/apps/deck/board/{boardId}/card/{cardId}`

#### Scenario: File record reuses its existing access URL
- **WHEN** the `/relations` endpoint returns a file record that already carries `accessUrl`
- **THEN** no new URL is computed for the file and its existing `accessUrl`/permalink is used unchanged

#### Scenario: Note record is left non-navigating
- **WHEN** the `/relations` endpoint returns a note (NC Comment)
- **THEN** the note record has no `url` field set, so the consumer renders it non-navigating

#### Scenario: Unresolvable leaf identifier omits the URL without failing
- **WHEN** a leaf service cannot resolve the addressbook/calendar/task URI required to build a record's `url`
- **THEN** the record is returned with its existing fields and `url` omitted, and the relation group does not error

### Requirement: The `url` field SHALL be the documented consumer contract for relation deep-links

The `url` field stamped on relation records SHALL be the contract consumed by the nc-vue `CnRelatedObjectsWidget`, whose `resolveItemHref` already prefers `raw.url || raw.link || raw.accessUrl`. The widget SHALL require no change to deep-link related items once OpenRegister stamps `url`; its prior per-app URL guessing SHALL act only as a fallback for records that omit `url`. This requirement documents the contract only; the widget is NOT modified by this change.

#### Scenario: Widget deep-links from the stamped URL with no frontend change
- **WHEN** OpenRegister returns a related record carrying a `url` field
- **THEN** `CnRelatedObjectsWidget.resolveItemHref` uses that `url` to deep-link the item, without any change to the widget

#### Scenario: Widget falls back when `url` is absent
- **WHEN** OpenRegister returns a related record with no `url` field (e.g. a note)
- **THEN** the widget renders the item non-navigating or uses its existing per-app fallback, and no error occurs
