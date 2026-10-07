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

### Requirement: Organisation edits write a sealed audit row (REQ-HSA-010)

Every create, update and delete of an OpenRegister `Organisation` SHALL write one audit trail row through the sealing insert path, with action `organisation.created`, `organisation.updated` or `organisation.deleted`, the organisation's uuid, the acting user or `system`, the cause and run, and for an update each changed field as a path with old and new value. The same masking and size cap as schema rows SHALL apply. A save that changes nothing SHALL write no row.

#### Scenario: a renamed organisation is recorded with who and when
- **GIVEN** organisation `Gemeente Voorbeeld` and a functional administrator
- **WHEN** the administrator renames it to `Gemeente Voorbeeld-Noord` on the organisation page
- **THEN** the audit trail holds a row with action `organisation.updated`, the administrator as user, the moment, and a field entry with path `name`, the old and the new name
- **AND** the administrative view on the audit log page lists that row

#### Scenario: an organisation created by a repair step names its cause
<!-- @e2e exclude Covered by PHPUnit EntityEditAuditListenerTest::testAnOrganisationCreatedByRepairNamesItsCause with the real OrganisationCreatedEvent. -->

- **GIVEN** a repair step that creates the default organisation
- **WHEN** it runs
- **THEN** a row with action `organisation.created`, user `system` and cause `migration` is written

### Requirement: The one trail separates administrative from domain history (REQ-HSA-011)

Every audit row SHALL carry `category` `domain` or `administrative`. Rows for schema, register, organisation and settings actions SHALL be `administrative`; object writes SHALL be `domain`. The migration SHALL backfill the category of existing rows by action. The category SHALL NOT be an input of the row hash, so the chain verifies before and after the backfill. `GET /api/audit-trails` SHALL accept `category` and SHALL default to `domain`. Administrative rows SHALL be readable only with the administrator right, also through any per-object trail endpoint. Administrative rows SHALL get `retentionPeriod` from the setting `audit.administrativeRetention` (default `P10Y`), and domain rows keep the existing retention.

#### Scenario: an auditor reads the administrative change log apart from record history
- **GIVEN** one schema edit, one organisation rename and five object updates
- **WHEN** an administrator opens the audit log page and chooses "Administrative changes"
- **THEN** exactly the schema and organisation rows are listed
- **AND** the default view lists the five object updates and neither administrative row

#### Scenario: an object reader does not see administrative rows
<!-- @e2e exclude Covered by PHPUnit AuditCategoryReadTest::testANonAdminNeverReceivesAdministrativeRows. -->

- **GIVEN** a caseworker who may read objects of schema `melding`, and an administrative row for that schema
- **WHEN** the caseworker calls `GET /api/audit-trails?category=administrative` or reads the schema's object trail
- **THEN** the first returns 403 and the second contains no administrative row

#### Scenario: the backfill does not break the seal
<!-- @e2e exclude Migration; covered by PHPUnit AuditCategoryBackfillTest::testTheChainVerifiesAfterBackfill. -->

- **GIVEN** a sealed chain of mixed rows written before the migration
- **WHEN** the migration backfills `category`
- **THEN** `GET /api/audit-trails/verify` reports the chain valid

#### Scenario: administrative rows keep their own retention
<!-- @e2e exclude Covered by PHPUnit AuditCategoryRetentionTest::testAdministrativeRowsTakeTheirOwnRetention. -->

- **GIVEN** `audit.administrativeRetention` set to `P20Y` and the domain retention at `P7Y`
- **WHEN** a schema edit and an object update are written
- **THEN** the schema row expires twenty years on and the object row seven
