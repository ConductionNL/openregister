# authorization-rbac

## ADDED Requirements

### Requirement: The owner sentinel limits an action to the record's owner

An `authorization.<action>` list MAY contain `@creator`. It SHALL NOT be read
as a group id. When it is the only entry, the action SHALL be admitted for the
object's owner only, in list queries, single reads and writes alike. The
stored authorization block SHALL NOT be rewritten.

#### Scenario: each user sees only the records they created

- **GIVEN** a schema `verzoek` whose authorization block has `read: ["@creator"]`, and users Anna and Bram who each created one `verzoek`
- **WHEN** Anna lists `GET /api/objects/{register}/verzoek`
- **THEN** the list holds Anna's record and not Bram's
- **AND** `GET` on Bram's record answers 403 or 404 for Anna, as any unreadable object does
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/access-own-records.spec.ts}

### Requirement: A condition scope admits rows whose field matches

An authorization block MAY carry `conditions.<action>` with
`{ field, operator: "equals", value }`. OpenRegister SHALL admit signed-in
users for that action on rows whose `field` equals `value`, where the value
`@user.uid` means the caller's user id.

#### Scenario: a team lead sees the records of their own department

- **GIVEN** a schema `melding` with `conditions.read: { "field": "behandelaar", "operator": "equals", "value": "@user.uid" }`
- **WHEN** user Carla lists `GET /api/objects/{register}/melding`
- **THEN** the list holds exactly the meldingen whose `behandelaar` is `carla`
- @e2e exclude {specified only; covered by MagicRbacHandlerTest in task 1.2}

### Requirement: The capabilities document states the enforced scope kinds

OpenRegister SHALL publish `openregister.authorization.scopes` in the
Nextcloud capabilities document, listing `group`, `creator` and `condition`,
only in a build that enforces all three.

#### Scenario: buildiq's designer offers the own-records option

- **GIVEN** an instance with this change
- **WHEN** buildiq reads `GET /ocs/v2.php/cloud/capabilities`
- **THEN** `openregister.authorization.scopes` lists `group`, `creator` and `condition`
- **AND** buildiq's access editor offers "only their own records" and a condition
- @e2e exclude {specified only; the capability is asserted in Newman in task 2.1}
