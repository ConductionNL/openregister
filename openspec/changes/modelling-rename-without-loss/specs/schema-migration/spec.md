# schema-migration

## ADDED Requirements

### Requirement: Migrations are reachable from the schema page

The schema page SHALL offer a Migrations tab that lists earlier runs, previews an
operation with the number of objects it touches, runs it, and rolls back a run.

#### Scenario: A functional administrator renames a field from the schema page

- **GIVEN** the schema `meldingen` with 1,200 objects carrying `omschrijving`
- **WHEN** a functional administrator opens the Migrations tab, builds a rename from `omschrijving` to `toelichting`, and previews it
- **THEN** the preview says 1,200 objects will change
- **AND** after running it, each object carries its old value under `toelichting`
- @e2e exclude {specified only; task 3.1 adds tests/e2e/schema-rename.spec.ts}

### Requirement: A record type can be renamed without breaking its links

The system SHALL offer a `renameSchema` operation that changes a schema's slug,
records the old slug in `formerSlugs`, and rewrites every `$ref` and `items.$ref` in
other schemas that named the old slug, as one run that rollback undoes.

#### Scenario: Links from other schemas follow the rename

- **GIVEN** the schema `document` with a property `$ref: melding`
- **WHEN** an administrator renames the schema `melding` to `signaal`
- **THEN** `document`'s property carries `$ref: signaal`
- **AND** rolling the run back restores `$ref: melding` and the slug `melding`
- @e2e exclude {specified only; task 1.1 adds the planner test}

### Requirement: A former slug keeps answering and says where to go

A request that names a former slug in an object path SHALL be served as the renamed
schema, with a `Deprecation` header and a `Link` header with `rel="successor-version"`
naming the current path. A current slug MUST win over a former one.

#### Scenario: An integration still calls the old path

- **GIVEN** the schema renamed from `melding` to `signaal`
- **WHEN** an integration requests `GET /api/objects/meldingen-register/melding`
- **THEN** the response is 200 with the objects of `signaal`
- **AND** it carries `Deprecation` and a `Link` to `/api/objects/meldingen-register/signaal`
- @e2e exclude {specified only; task 3.2 adds the old-path check to tests/e2e/schema-rename.spec.ts}

#### Scenario: An app update does not recreate a renamed schema

- **GIVEN** a schema shipped by an app as `melding` and renamed to `signaal` by the administrator
- **WHEN** the app's register descriptor is imported again with the slug `melding`
- **THEN** the import updates `signaal` and creates no schema named `melding`
- @e2e exclude {specified only; task 2.3 adds the import test}
