# data-import-export

## ADDED Requirements

### Requirement: A schema keeps and serves its exportable flag

OpenRegister SHALL keep a schema's `exportable` flag when it arrives as
`configuration.exportable` or as a top-level `exportable`, on save and on
import, storing it once as `configuration.exportable`. Every schema read SHALL
return `configuration.exportable` and a top-level `exportable` with the same
value, false when unset.

#### Scenario: an administrator flags a schema exportable

- **GIVEN** an administrator editing schema `contract`
- **WHEN** the administrator saves it through `PUT /api/schemas/{id}` with `configuration: { "exportable": true }`
- **THEN** `GET /api/schemas/{id}` returns `configuration.exportable` true and top-level `exportable` true
- **AND** an index page with `allowExport` shows the Export menu for `contract`
- @e2e exclude {specified only; task 2.2 adds tests/e2e/ci/schema-exportable.spec.ts}

#### Scenario: an app's register import keeps the flag

- **GIVEN** stackiq's register fragment that sets top-level `exportable: true` on schema `catalogContract`
- **WHEN** the register is imported
- **THEN** the stored schema has `configuration.exportable` true, and a read returns both places true
- @e2e exclude {specified only; covered by the import test in task 1.2}
