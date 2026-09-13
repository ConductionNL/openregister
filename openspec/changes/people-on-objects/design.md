# Design: people on objects

## D-1 One table, two kinds

The contact link table stays the one table for a person on an object. A row is a contact link when `addressbook_id` and `contact_uri` are set, a user link when `user_id` is set. `contact_uid` is always set: the vCard `UID` for a contact, `user:<uid>` for a user. That keeps the routes (`.../contacts/{contactUid}`), the reverse lookup (`/api/contacts/{contactUid}/objects`) and the mapper's lookups keyed on one column. `kind` in the JSON (`user` | `contact`) is derived, not stored.

Alternative rejected: a second table for user links. Every consumer (tab, provider, relations endpoint, reverse lookup) would read two tables and merge, and roles and validity would live twice.

## D-2 Validity is data, activity is derived

`valid_from` and `valid_until` are dates (no time; a role holds for a day at least). The JSON carries `active`: true when today is inside the window, with an open end on either side. Listing does not filter on activity: the tab shows the past handler with the badge, and the ZGW projection needs the row to end a `rol` rather than lose it.

## D-3 Vocabulary on the schema

`configuration.linkRoles` is a list of `{key, label, description?}`; a bare string is accepted and read as `{key: s, label: s}`. Keys are unique, non-empty, at most 64 characters (the column). The validator lives with the other configuration validators in `Schema`, and the key is in the validator's vocabulary so an app import round-trips it (an unknown key is dropped in silence there, which is how `x-contactRoles` in the docs never did anything).

Enforcement is in the service, at link and update time: when the schema of the object declares a vocabulary and the role is not in it, 400 with the allowed keys in the message. A schema with no vocabulary accepts any role, as today.

## D-4 A facade over both kinds

`PersonLinkService` is the write surface the controller uses: `link(objectUuid, registerId, schemaId, payload)` decides by payload (`userId` or `addressbookId`+`contactUri`), validates the role, delegates a contact link to `ContactService::linkContact` (which keeps the vCard in step) and writes a user link itself, then applies validity and note and dispatches the event. `update` and `unlink` likewise: the vCard steps run for a contact link only. `ContactService` stays the CardDAV side; its list, unlink and cleanup guard on the kind, since `CardDavBackend::getCard()` takes an int and a user link has none.

Alternative rejected: growing `ContactService`. It is 900 lines and carries phpmd suppressions already; the user side shares nothing with vCards.

## D-5 One person, many roles

The unique index becomes `(object_uuid, contact_uid, role)`. `findByObjectAndContact` keeps returning the first row for callers that do not care; `findByObjectContactAndRole` is the upsert key. `DELETE .../contacts/{contactUid}` without `role` removes every role of that person on the object, which is what "remove this person" means in the tab.

## D-6 Events

Three events with the `ContactLink` as payload, dispatched after the write. dossiq listens and projects a link on a case into its `role` object; other apps ignore them. No event on the list path.

## D-7 Reverse lookup for users

`getObjectsForContact` filters contact links to the caller's own address books. A user link has no address book; it is returned to any signed-in caller, since a user's membership of an object is not private to an address book owner. Object RBAC still applies where the objects are read.
