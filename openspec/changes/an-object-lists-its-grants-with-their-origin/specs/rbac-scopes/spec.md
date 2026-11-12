# rbac-scopes

## ADDED Requirements

### Requirement: An object lists its grants with their origin

OpenRegister SHALL answer `GET /api/objects/{register}/{schema}/{id}/grants` with one row
per principal and origin. Each row MUST carry the principal (type, id, display name), the
verbs it holds on this record, the origin (`owner`, `shared`, `inherited` or `rule`), and
the level the grant is written at. A `shared` or `inherited` row MUST carry who created the
share (`sharedBy`, with a display name). An `inherited` row MUST name the ancestor record.
A `rule` row MUST carry the role it came through, if any, and the properties its condition
matched on (`matchedOn`).

A rule with a condition MUST appear only when the condition fits this record, evaluated with
the same matcher the single-object decision uses. A condition that reads the asking user
MUST be reported with `relative: true` instead of being evaluated for the caller. Link
shares MUST NOT be listed. A verb an enforcing deny removes MUST NOT be in the row; a staged
deny MUST leave the verb and be reported in `denyEnforcement`.

A source that cannot be read MUST answer 500, never an empty list.

#### Scenario: A group reads a record through a conditional schema rule
- **GIVEN** a schema `case` whose `read` rule holds `{"group": "woo-team", "match": {"caseType": {"$in": ["ct-1"]}}}`
- **AND** a record of `case` with `caseType` `ct-1`
- **WHEN** a caller who may read its grants asks for the record's grants
- **THEN** a row has principal type `group`, id `woo-team`, origin `rule`, level `schema` and `matchedOn` `["caseType"]`
- **AND** its `displayName` is the Nextcloud group's display name

#### Scenario: A conditional rule that does not fit the record is left out
- **GIVEN** the same rule
- **AND** a record of `case` with `caseType` `ct-2`
- **WHEN** a caller who may read its grants asks for the record's grants
- **THEN** no row names `woo-team`

#### Scenario: A share names who made it
- **GIVEN** user `pjansen` shared a record with group `juridische-zaken`
- **WHEN** a caller who may read its grants asks for the record's grants
- **THEN** a row has principal `juridische-zaken`, origin `shared` and `sharedBy` `{"id": "pjansen", "displayName": "Pieter Jansen"}`

#### Scenario: A share on the parent shows on the child
- **GIVEN** a parent record shared with user `tvisser` by `pjansen`
- **AND** a child record that inherits its parent's grants
- **WHEN** a caller who may read the child's grants asks for them
- **THEN** a row has principal `tvisser`, origin `inherited`, `inheritedFrom.uuid` the parent's uuid and `sharedBy.id` `pjansen`

#### Scenario: A condition on the asking user is relative
- **GIVEN** a schema `read` rule `{"group": "authenticated", "match": {"assignee": "$userId"}}`
- **WHEN** a caller who may read the grants asks for them
- **THEN** a row has principal type `authenticated`, origin `rule`, `relative` true and `matchedOn` `["assignee"]`

#### Scenario: A principal type filter
- **WHEN** the caller asks with `principalTypes=user,group`
- **THEN** no row has type `authenticated`, `public`, `remote` or `email`

#### Scenario: A link share is not a grant row
- **GIVEN** a record with a public link share
- **WHEN** a caller who may read its grants asks for them
- **THEN** no row has origin `shared` for that link

#### Scenario: An enforcing deny takes the verb out of the row
- **GIVEN** a schema rule that grants `woo-team` read and update on a record
- **AND** an enforcing deny on update for `woo-team`
- **WHEN** a caller who may read the grants asks for them
- **THEN** the `woo-team` row's verbs are `["read"]`

### Requirement: Reading an object's grants needs its own right, checked before the read

The grants read MUST resolve the record through the RBAC boundary first. A caller who
cannot read the record MUST get 404. A caller who can read it MUST then be admitted as the
owner, a member of the owning group, an administrator, a holder of `manage` through a
written rule, a holder of the organisation's `grant_manage` special right on the record, or
a caller the record's `readGrants` rule admits. Anyone else MUST get 403. No share, ancestor
or rule MUST be read for a refused caller.

`readGrants` SHALL be a canonical verb in the permission catalogue. It MUST NOT resolve
default-open: a schema and register that do not write it admit nobody through it. A
`readGrants` entry with a `match` clause MUST be evaluated against the record.

#### Scenario: A reader of the record without the right is refused
- **GIVEN** a caller who reads a record through the schema's `read` rule
- **AND** the schema writes no `readGrants`, and the caller holds no other admitting right
- **WHEN** the caller asks for the record's grants
- **THEN** the answer is 403 and no share is read

#### Scenario: A caller who cannot read the record learns nothing
- **GIVEN** a caller the record's RBAC does not admit
- **WHEN** the caller asks for the record's grants
- **THEN** the answer is 404

#### Scenario: The assignee reads the grants through a conditional readGrants rule
- **GIVEN** a schema whose `readGrants` rule holds `{"group": "authenticated", "match": {"assignee": "$userId"}}`
- **AND** a record whose `assignee` is the caller
- **WHEN** the caller asks for the record's grants
- **THEN** the answer is 200 with the rows

#### Scenario: A grant_manage holder reads the grants
- **GIVEN** a group that holds `grant_manage` on the record's organisation
- **WHEN** a member of that group, who can read the record, asks for its grants
- **THEN** the answer is 200

#### Scenario: A schema that writes nothing is not open
- **GIVEN** a schema with no `authorization` block
- **WHEN** a signed-in caller who is not the owner or an administrator asks for a record's grants
- **THEN** the answer is 403
