# objects-crud (delta)

## ADDED Requirements

### Requirement: Every schema is served as a REST collection (REQ-RAT-001)

For every schema in a register, the system SHALL serve `/api/objects/{register}/{schema}` with `GET` to list and `POST` to create, and `/api/objects/{register}/{schema}/{id}` with `GET` to read, `PUT` to replace, `PATCH` to change named fields and `DELETE` to delete. `{register}` and `{schema}` SHALL accept an id or a slug, and `{id}` a uuid, an id or the object's slug. The routes SHALL exist as soon as the schema is saved, with no code or restart, and SHALL apply the schema's validation and the caller's rights on every verb.

#### Scenario: A new schema is usable over the API at once
- **GIVEN** a functional administrator saves a new schema `boom` in register `groen`
- **WHEN** a client posts `{"soort": "eik"}` to `/api/objects/groen/boom`
- **THEN** the response is 201 with the stored object and its uuid
- **AND** `GET /api/objects/groen/boom/{uuid}` returns it
- @e2e exclude {REST surface, covered by the Newman collection in task 2.1}

#### Scenario: A patch changes only the named field
- **GIVEN** an object with `soort: eik` and `hoogte: 12`
- **WHEN** a client sends `PATCH` with `{"hoogte": 14}`
- **THEN** `hoogte` is 14 and `soort` is still `eik`
- @e2e exclude {covered by the Newman collection in task 2.1}

#### Scenario: A caller without rights is refused
- **GIVEN** a schema with `delete: ["beheer"]` and a caller outside `beheer`
- **WHEN** the caller sends `DELETE` for one of its objects
- **THEN** the response is 403 and the object still exists
- @e2e exclude {covered by the Newman collection in task 2.1}
