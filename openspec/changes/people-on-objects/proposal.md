# People on objects

## Why

A person belongs to an object in a role: the applicant of a case, the handler of a permit, the advisor on a decision. OpenRegister already keeps that for Nextcloud Contacts through the contact link (`openregister_contact_links`: one row per contact on an object, with a role, grouped by role in the sidebar tab, reverse lookup). It cannot hold a Nextcloud user, so every app that needs a person who signs in keeps its own field: dossiq carries `requester` plus three denormalised projection fields and a back-fill widget, and its case people are `role` objects that name a participant by a plain string. Nothing declares which roles an object can carry, so a role is free text and the documented `x-contactRoles` enum on the schema was never read by any code.

Ruben's call of 2026-09-13: "we should be able to link users and contacts to objects generically and be able to describe their link, like in what role they are coupled. This also solves the original indiener problem." The answers to the design questions: one link type for a user or a contact, roles declared per schema, and the ZGW `rol` in dossiq becomes a projection of these links.

## What changes

- **A link is a user or a contact.** The link table gains `user_id`, and `addressbook_id` and `contact_uri` become nullable. A user link stores `user:<uid>` as its `contact_uid`, so every route, index and reverse lookup that keys on the contact uid works unchanged. Display name, email and avatar come from the user account.
- **A link has validity and a note.** `valid_from`, `valid_until` and `note` land on the row. A link outside its window is still listed, marked inactive.
- **A person can hold more than one role on an object.** The unique index moves from `(object_uuid, contact_uid)` to `(object_uuid, contact_uid, role)`. Linking the same person in the same role updates the row; in another role it adds one.
- **A schema declares its roles.** `configuration.linkRoles` on a schema lists the roles its objects can carry, each with a key and a label. A link with a role outside a declared vocabulary is refused with 400. A schema that declares nothing keeps free-text roles. The list endpoint returns the vocabulary beside the links, so a picker reads one call.
- **The list groups by role.** `GET /api/objects/{register}/{schema}/{id}/contacts` answers `results`, `total`, `byRole` and `roles`.
- **The API takes a user.** `POST .../contacts` accepts `{userId, role, validFrom, validUntil, note}` beside the existing `{addressbookId, contactUri, role}`. `PUT .../contacts/{contactUid}` updates role, validity and note (it answered 501 before). `DELETE .../contacts/{contactUid}?role=` removes one role of a person, or all of them without the filter.
- **Events.** `PersonLinkedEvent`, `PersonLinkUpdatedEvent` and `PersonUnlinkedEvent` carry the link, so an app can project it (dossiq's ZGW `rol`).
- **Docs** replace the dead `x-contactRoles` with `linkRoles` and describe user links.

## What does not change

- The vCard side of a contact link (`X-OPENREGISTER-OBJECT`, `X-OPENREGISTER-ROLE`) and the Contacts app requirement for contact links. A user link needs no Contacts app.
- `ContactsProvider`, the sidebar tab, the reverse lookup route and the relations endpoint keep their shapes; they gain fields.
- The consumer-side surfaces (the picker that offers users, the role select fed by the vocabulary) are the next change, in @conduction/nextcloud-vue.

## Capabilities

- `people-on-objects` (new): the link model, the vocabulary, the API and the events.

## Impact

- `lib/Migration/Version1Date20260913180000.php`, `appinfo/info.xml` (version).
- `lib/Db/ContactLink.php`, `lib/Db/ContactLinkMapper.php`, `lib/Db/Schema.php`.
- `lib/Service/PersonLinkService.php` (new), `lib/Service/ContactService.php` (guards for user links), `lib/Event/PersonLinkedEvent.php`, `PersonLinkUpdatedEvent.php`, `PersonUnlinkedEvent.php` (new).
- `lib/Controller/ContactsController.php`.
- `docs/Integrations/contacts.md`.
- Tests under `tests/Unit/{Migration,Db,Service,Controller,Event}`.
