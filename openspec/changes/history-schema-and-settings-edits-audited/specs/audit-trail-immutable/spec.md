# audit-trail-immutable

## ADDED Requirements

### Requirement: Schema and register edits write a sealed audit row

Every create, update and delete of a schema or a register SHALL write one audit trail row through the sealing insert path, on the same hash chain as object changes. The row SHALL carry the action (`schema.created`, `schema.updated`, `schema.deleted`, `register.created`, `register.updated` or `register.deleted`), the entity's id, uuid and organisation, the acting user or `system`, and the cause and run of the write. An update row SHALL list each changed field as a path with its old and new value, diffing schema properties per property and keyword, and SHALL skip fields the server derives. A save that changes nothing SHALL write no row.

#### Scenario: an administrator sees a narrowed property

- **GIVEN** schema `melding` with property `omschrijving` of `maxLength` 200
- **WHEN** a functional administrator changes `maxLength` to 80 in the schema editor
- **THEN** the audit trail holds a row with action `schema.updated`, the administrator as user, and a field entry with path `properties.omschrijving.maxLength`, old 200 and new 80
- **AND** `GET /api/audit-trails/verify` still reports the chain as valid
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/schema-edit-audit.spec.ts}

#### Scenario: an import names itself as the cause

- **GIVEN** an app update that imports a new version of schema `zaak` through a configuration import
- **WHEN** the import changes the schema's `required` list
- **THEN** the row has action `schema.updated`, cause `import` and the import's run id
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/schema-edit-audit.spec.ts}

#### Scenario: deleting a register leaves a row

- **GIVEN** register `archief-oud` with three schemas
- **WHEN** a functional administrator deletes it
- **THEN** a row with action `register.deleted` names its slug, title and version and carries the sha256 of its definition
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/schema-edit-audit.spec.ts}

### Requirement: An edit audit row never carries a credential or a bulk copy

An entity edit row SHALL record a changed value under a key named `password`, `secret`, `token`, `apiKey`, `clientSecret` or `privateKey`, at any depth, as changed with both values masked. It SHALL store a value whose JSON exceeds 2,048 bytes as its sha256 and length. A failure to write the row SHALL be logged at error level and SHALL NOT fail the edit.

#### Scenario: a rotated hook secret is recorded without its value

- **GIVEN** schema `zaak` with a hook whose configuration has `clientSecret`
- **WHEN** a functional administrator replaces the secret
- **THEN** the row lists path `hooks.0.configuration.clientSecret` as changed
- **AND** neither the old nor the new value appears in the row
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/schema-edit-audit.spec.ts}

### Requirement: Administrators read an entity's change history

`GET /api/schemas/{id}/changes` and `GET /api/registers/{id}/changes` SHALL return that entity's edit rows, newest first, paginated with `_page` and `_limit` up to 100. Both SHALL be administrator-only. The schema detail page SHALL show them in a "Changes" tab and the register detail page in a "Changes" section, to administrators only.

#### Scenario: an administrator opens the changes tab

- **GIVEN** schema `melding` edited three times
- **WHEN** a functional administrator opens the schema detail page and chooses the "Changes" tab
- **THEN** the page lists three entries, newest first, each with who, when, cause and the changed paths with old and new values
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/schema-edit-audit.spec.ts}

#### Scenario: a caseworker cannot read the change history

- **GIVEN** a signed-in caseworker who is not an administrator
- **WHEN** they call `GET /api/schemas/12/changes`
- **THEN** the response is 403
- **AND** the schema detail page shows them no "Changes" tab
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/schema-edit-audit.spec.ts}
