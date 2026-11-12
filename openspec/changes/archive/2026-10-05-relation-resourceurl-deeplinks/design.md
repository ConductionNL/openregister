## Context

OpenRegister exposes related items for an object through two families of endpoints:

- `GET /api/objects/{register}/{schema}/{id}/relations` → `RelationsController::index()` → `gatherRelations()`, which aggregates **leaf** records from per-app services: `NoteService` (notes / NC Comments), `TaskService` (tasks), `EmailService` (emails / NC Mail), `CalendarEventService` (events), `ContactService` (contacts / CardDAV), `DeckCardService` (deck).
- `GET /api/objects/{register}/{schema}/{id}/uses`, `/used`, `/contracts` → `ObjectsController` → `RelationHandler::getUses()` / `getUsedBy()` / `getContracts()`, which return related OpenRegister **objects**.

None of these records carry a URL to open the item in the UI. The frontend `CnRelatedObjectsWidget` consequently re-derives open-URLs per app. OpenRegister already owns the authoritative resolution for objects: `lib/Search/ObjectsProvider.php` (the unified-search provider) resolves each object's URL via `DeepLinkRegistryService::resolveUrl(registerId, schemaId, objectData)`, falling back to the `openregister.objects.show` route when no app has registered a template (apps register via `DeepLinkRegistrationEvent`; e.g. shillinq maps `account → /apps/shillinq/chart-of-accounts/{uuid}`). For leaves, each owning Nextcloud app exposes a deep-link to the specific item, but the leaf records do not yet surface the URI-based identifiers needed to build those links.

Constraints: `kind: code` (PHP only); no schema/register-config changes; no seed data; reuse existing utilities (ADR-011); use OCP interfaces and the OR service layer.

## Goals / Non-Goals

**Goals:**
- Every related record from the relation endpoints carries an optional `url` deep-link to where the item actually lives.
- Object URLs reuse the EXISTING `DeepLinkRegistryService` resolver — the single source of truth already shared with unified search — plus the same `openregister.objects.show` fallback `ObjectsProvider` uses. No new object-URL logic.
- Leaf URLs are built server-side inside each owning leaf service as the owning Nextcloud app's deep-link to the specific item.
- The change is additive and non-breaking: `url` is an optional field; records without a resolvable URL simply omit it.

**Non-Goals:**
- No frontend change in this spec (consumer contract documented below).
- No new capability for object-URL resolution — `DeepLinkRegistryService` stays canonical.
- No change to which records are returned, to filtering, or to error handling of the relation endpoints.
- No `url` for notes (NC Comments has no standalone app page).
- No rebuild of file URLs — files already carry `accessUrl`/permalink.

## Decisions

### Declarative-vs-imperative (ADR-031): N/A

ADR-031 governs declarative lifecycle / aggregation / calculation / notification / relation / widget declarations expressed as schema config. This change adds **none** of those: it reuses the existing **imperative** `DeepLinkRegistryService` for objects and adds imperative URL construction inside the leaf services. It introduces no schema configuration, no register config, no lifecycle/aggregation/notification dialect, and no widget declaration. ADR-031's declarative-first preference therefore does not apply — there is no declarative alternative to a per-record URL stamp computed from runtime CardDAV/CalDAV/Deck identifiers and the in-memory deep-link registry.

### Reuse `DeepLinkRegistryService` for related objects (do not duplicate)

For `/uses`, `/used`, `/contracts`, build `url` by calling `DeepLinkRegistryService::resolveUrl(registerId, schemaId, objectData)` exactly as `ObjectsProvider` does, with the same fallback to `urlGenerator->linkToRoute('openregister.objects.show', ['register'=>…, 'schema'=>…, 'id'=>$uuid])` when the resolver returns `null`. `objectData` is the flat array the resolver expects (top-level `uuid`, `register`, `schema`, plus `@self`/object keys for template substitution), mirroring `ObjectsProvider`'s `$flatData`.

**Where to stamp.** Two viable insertion points: (1) inside `RelationHandler::getUses()/getUsedBy()/getContracts()`; or (2) centralized in `ObjectsController`/`RelationsController` after the handler returns. **Decision: centralize object-URL stamping in one place** (the controller layer or a single private helper) so `resolveUrl` + fallback is called from exactly one site across all three object endpoints, matching the single-source-of-truth intent and avoiding three copies of the resolve+fallback dance. `DeepLinkRegistryService` is injected wherever that stamping site lives. *(Provisional — see Open Questions; either location satisfies the spec as long as the resolve+fallback logic is not duplicated.)*

*Alternative considered:* re-implement object URLs in the widget — rejected, that is exactly the duplication this change removes and it bypasses app-registered templates.

### Build leaf URLs inside each leaf service

Each leaf's URL is the owning app's deep-link to the specific item, built in the service that already produces the record (it holds the identifiers and the backend handles to resolve URIs):

- **Contacts** (`ContactService::getContactsForObject`): NC Contacts 8.x URL `/apps/contacts/All contacts/<base64url(uid~addressbookUri)>`. Records expose `contactUid` + numeric `addressbookId` + `contactUri` (`.vcf`) but NOT the addressbook URI string; resolve `addressbookId → addressbook URI` via the already-injected `CardDavBackend` (`getAddressBookById()`), then base64url-encode `"{uid}~{addressbookUri}"`.
- **Calendar events** (`CalendarEventService`): the Calendar app's dav-path event URL; resolve the calendar URI for the event and build the Calendar deep-link.
- **Tasks** (`TaskService`): `/apps/tasks/#/calendars/{calendarUri}/tasks/{taskUri}`.
- **Deck** (`DeckCardService`): `/apps/deck/board/{boardId}/card/{cardId}` — both ids already present on the record.
- **Files**: reuse the existing `accessUrl`/permalink the record already carries — do not rebuild.
- **Notes** (`NoteService` / NC Comments): no standalone app page → leave `url` unset; the widget renders the item non-navigating.

*Alternative considered:* resolve all leaf URLs centrally in the controller — rejected, the controller does not hold the CardDAV/CalDAV identifiers and would have to re-fetch them; the owning service already has the backend handle, so building the URL there avoids a second round-trip and keeps each app's URL shape next to the record it produces.

### Consumer contract (informational — NO change in THIS spec)

nc-vue `CnRelatedObjectsWidget.resolveItemHref` already prefers `raw.url || raw.link || raw.accessUrl`. Once OR stamps `url` on each record, the widget deep-links with **zero** frontend change, and its per-app URL guessing degrades to a fallback for records that omit `url` (e.g. notes). This is stated as the consumer contract; the widget is not modified by this change.

## Risks / Trade-offs

- [Addressbook URI resolution adds a CardDAV lookup per contact group] → `getAddressBookById()` is cheap and the contacts list is small; resolve once per distinct `addressbookId` within the request if needed. Failure to resolve a URI MUST degrade gracefully: omit `url` for that record, never throw.
- [NC Contacts URL format varies across versions (base64 path vs other)] → target the documented NC Contacts 8.x format; if the addressbook URI cannot be resolved, omit `url`. Format is isolated to `ContactService` so a later version bump is a one-file change.
- [Deep-link registrations are request-scoped/in-memory] → identical behavior to unified search, which already relies on this; no new risk, and the `openregister.objects.show` fallback guarantees a usable URL.
- [URL building inside a relation request could raise an exception and break the whole group] → each `url` computation MUST be defensive (try/resolve, omit on failure) so a URL-resolution error never changes which records are returned or their existing fields.

## Migration Plan

Pure additive code change. No DB migration, no schema/register-config change, no seed data. Deploy is a code+bundle ship (bump `info.xml` <version> per the immutable-cache rule when bundling, though this is backend PHP only). Rollback = revert the PHP changes; the `url` field simply disappears and the widget falls back to its prior per-app guessing.

## Open Questions

- **Capability name**: attaching the spec delta to a new `relation-resource-urls` capability (no single existing capability owns both `/relations` leaves and `/uses`/`/used`/`/contracts` objects). Provisional: create `relation-resource-urls`.
- **Object-URL stamping location**: centralize in the controller layer vs. inside each `RelationHandler` method. Provisional: centralize at the controller layer (one resolve+fallback site).
- **Exact contacts URL format**: base64url path segment per NC Contacts 8.x vs. version variance. Provisional: NC Contacts 8.x `base64url(uid~addressbookUri)` path, omit `url` if unresolvable.
