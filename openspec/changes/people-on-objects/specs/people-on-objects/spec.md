## ADDED Requirements

### Requirement: A link on an object is a user or a contact, in a role, for a period

A person on an object SHALL be one row of `openregister_contact_links`. A contact link carries `addressbook_id` and `contact_uri`; a user link carries `user_id` and stores `user:<uid>` as its `contact_uid`. Both carry `role`, `valid_from`, `valid_until` and `note`. The JSON of a link SHALL carry `kind` (`user` or `contact`), `userId`, `validFrom`, `validUntil`, `note` and `active` (true when today lies inside the validity window; an unset bound is open). One person MAY hold several roles on one object: the unique key is `(object_uuid, contact_uid, role)`.

#### Scenario: A user is linked in a role
- **GIVEN** object `case-1` of a schema without a role vocabulary
- **WHEN** `POST /api/objects/{register}/{schema}/case-1/contacts` carries `{"userId": "jan", "role": "handler"}`
- **THEN** the response is 201 with `kind: "user"`, `userId: "jan"`, `contactUid: "user:jan"`, `role: "handler"`, `active: true`
- **AND** `displayName` and `email` come from the user account, `avatarUrl` from the core avatar route

#### Scenario: The same person in a second role adds a row, the same role updates it
- **GIVEN** `jan` linked to `case-1` as `handler`
- **WHEN** `jan` is linked as `advisor`, then again as `handler` with a note
- **THEN** the object lists two links for `user:jan`, and the `handler` link carries the note

#### Scenario: Validity marks a link inactive without hiding it
- **GIVEN** a link with `validUntil` yesterday
- **WHEN** the object's links are listed
- **THEN** the link is in `results` with `active: false`

#### Scenario: An unknown user is refused
- **WHEN** a link is posted with a `userId` no account has
- **THEN** the response is 404 and no row is written

### Requirement: A schema declares the roles its objects carry

`configuration.linkRoles` on a schema SHALL list the roles as `{key, label, description?}` entries (a bare string reads as `{key, label}` with the same value). The key is unique, non-empty and at most 64 characters. A link or update whose role is not in a declared vocabulary SHALL be refused with 400 naming the allowed keys. A schema without `linkRoles` accepts any role. The list endpoint SHALL return the vocabulary as `roles` beside the links.

#### Scenario: A role outside the vocabulary is refused
- **GIVEN** schema `case` with `linkRoles: [{"key": "initiator", "label": "Initiator"}, {"key": "handler", "label": "Handler"}]`
- **WHEN** a link is posted with `role: "observer"`
- **THEN** the response is 400 and the message names `initiator` and `handler`

#### Scenario: The vocabulary round-trips through the schema configuration
- **WHEN** a schema is saved with `configuration.linkRoles`
- **THEN** reading the schema back returns the same list, and an entry without a key or with a duplicate key fails validation of that key only

#### Scenario: The list carries the vocabulary
- **GIVEN** the schema above and a linked handler
- **WHEN** `GET .../contacts` is called
- **THEN** the body has `results`, `total`, `byRole` (`{"handler": [link]}`) and `roles` (the two entries)

### Requirement: A link can be updated and removed per role

`PUT /api/objects/{register}/{schema}/{id}/contacts/{contactUid}` SHALL update `role`, `validFrom`, `validUntil` and `note` of the person's link (with `?role=` naming which of several). `DELETE .../contacts/{contactUid}` SHALL remove every link of that person on the object, or only the one named by `?role=`. For a contact link the vCard's `X-OPENREGISTER-ROLE` follows the role.

#### Scenario: The end date is set
- **GIVEN** `jan` linked as `handler`
- **WHEN** `PUT .../contacts/user:jan` carries `{"validUntil": "2026-12-31"}`
- **THEN** the link answers `validUntil: "2026-12-31"` and its role is unchanged

#### Scenario: One role goes, the other stays
- **GIVEN** `jan` linked as `handler` and `advisor`
- **WHEN** `DELETE .../contacts/user:jan?role=advisor`
- **THEN** the `handler` link remains

### Requirement: Link changes are events

The service SHALL dispatch `PersonLinkedEvent`, `PersonLinkUpdatedEvent` and `PersonUnlinkedEvent`, each carrying the `ContactLink`, after the write. The list path dispatches nothing.

#### Scenario: A projection hears the link
- **GIVEN** a listener on `PersonLinkedEvent`
- **WHEN** a user is linked to an object
- **THEN** the listener receives the link with its object uuid, kind, role and validity

### Requirement: The CardDAV side ignores user links

`ContactService` SHALL skip vCard reads and writes for a user link in its list, unlink and object-cleanup paths, and SHALL include user links in the reverse lookup for any signed-in caller.

#### Scenario: Listing an object with a user link reads no vCard
- **GIVEN** an object with one user link and one contact link
- **WHEN** the links are listed
- **THEN** the CardDAV backend is read once, for the contact link

#### Scenario: Deleting the object removes both kinds
- **WHEN** `deleteLinksForObject` runs on that object
- **THEN** both rows are gone and the contact's vCard lost its object marker
