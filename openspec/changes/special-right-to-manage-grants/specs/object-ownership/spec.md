# object-ownership

## ADDED Requirements

### Requirement: A group can hold the special right to manage a record's grants without owning the record (REQ-OWN-007)

An organisation's authorization block SHALL carry a special right `grant_manage`
beside its other special rights. A caller in a group that holds it on a record
MUST be able to list the record's grants, add a grant, revoke a grant and name
or remove the record's owning group, on a record the caller does not own.

The right MUST be read from the organisation the record belongs to. A record
that names no organisation MUST be read against the instance's default
organisation.

An entry under the right is either a group id, which holds the right on every
record of the organisation, or an object naming a `group` and narrowing it with
a `register` and a list of `schemas`, each named by slug, uuid or id. A narrowed
entry MUST hold only on records of the register and schemas it names.

The right MUST NOT open anything else. A holder MUST NOT thereby read or change
the record's contents, change its scope, create a public link or send an e-mail
invitation. A holder admitted by the special right alone MUST NOT add a grant
for its own account or for a group it is in, and MUST NOT name a group it is in
as the owning group.

Reading the right MUST fail closed: an organisation, register or schema that
cannot be loaded, a value that is not a list, an entry of neither shape, and the
wildcard `*` admit nobody.

Every grant, every revoke and every change of the owning group on a record MUST
write an audit-trail entry on the hash-chained trail, of its own typed action
(`grant_added`, `grant_revoked`, `owner_group_changed`): who acted (the caller),
the record, the principal and permissions of the grant (or the owning group
before and after), and whether the caller was admitted as owner or as holder of
the right. A refused request writes none. Failing to write the entry MUST be
logged at error level and MUST NOT undo the change that was stored.

#### Scenario: A service account writes a grant on a record it does not own
- **GIVEN** a private record owned by a handler, of schema `private-note` in register `records`
- **AND** the record's organisation lists `{"group": "records-service", "register": "records", "schemas": ["private-note"]}` under `grant_manage`
- **WHEN** a caller in group `records-service` adds a read grant for user `reviewer`
- **THEN** the grant is stored, with the caller as the one who shared

#### Scenario: The right does not reach a schema it does not name
- **GIVEN** the same entry
- **WHEN** the caller adds a grant on a record of schema `case`
- **THEN** the request is refused

#### Scenario: A caller without the right is refused
- **GIVEN** a caller who is not the owner, not in the owning group, not an administrator and in no group that holds `grant_manage`
- **WHEN** the caller lists, adds or revokes a grant through the sharing API
- **THEN** each answer is HTTP 403 and nothing is written

#### Scenario: A grant and its revoke are on the trail
- **GIVEN** the holder of the right adds a read grant for user `reviewer` and then revokes it
- **THEN** a `grant_added` and a `grant_revoked` entry name the record, `reviewer` and `admittedAs: special-right`

#### Scenario: A holder cannot let itself in
- **WHEN** a holder adds a grant for its own account, or for a group it is in, or names a group it is in as the owning group
- **THEN** the request is refused and nothing is written

#### Scenario: A holder is not admitted to the record
- **GIVEN** a holder with no grant on the record and no rule that admits it
- **WHEN** the holder reads or changes the record
- **THEN** the record's own rules decide, and the special right is not consulted

#### Scenario: An owner and an administrator are unaffected
- **WHEN** the record's owner or an administrator manages its grants
- **THEN** it works as before, including a grant to themselves

### Requirement: Only an administrator or the organisation's owner decides who holds `grant_manage` (REQ-OWN-008)

A change to the list under `grant_manage` through the organisation API MUST be
refused with HTTP 403 unless the caller is an administrator or the owner of the
organisation. Replacing the authorization block with one that leaves the key
out counts as a change. A member MUST still be able to change the rest of the
block when the list under `grant_manage` is sent unchanged.

Code shipped with an app MAY give a group the right in process. One entry is
kept per group: giving it again replaces the group's entry.

#### Scenario: A member adds their own group
- **GIVEN** a member of the organisation who is neither its owner nor an administrator
- **WHEN** they send an authorization block that adds a group to `grant_manage`
- **THEN** the answer is HTTP 403 and the stored list is unchanged

#### Scenario: A member drops the key
- **WHEN** the same member sends an authorization block without `grant_manage` while the stored list is not empty
- **THEN** the answer is HTTP 403 and the stored list is unchanged

#### Scenario: A change of the list is on the trail
- **WHEN** an administrator or the owner changes the list under `grant_manage`
- **THEN** a `settings.updated` entry names the organisation, the list before and the list after, and the acting user
- **AND** a save that leaves the list as it was writes none

#### Scenario: An app narrows what its service group holds
- **GIVEN** an organisation where group `records-service` holds the right on schema `old`
- **WHEN** the app gives the group the right on schemas `private-note` and `review`
- **THEN** the group has one entry, naming those two schemas
