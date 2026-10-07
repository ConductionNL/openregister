# data-import-export

## MODIFIED Requirements

### Requirement: Seed metadata keys are not stored as data

A seed object under `x-openregister.seedData`, and an object listed under a
configuration's `components.objects`, carries its `uuid` and `slug` at the top
level. The importer SHALL use them for the idempotency lookup and set them as
the object's metadata, and SHALL remove them from the object's data before it
is written, unless the target schema declares a property of that name. For a
listed object an `@self.uuid` SHALL win over the top-level `uuid`. An import
MUST NOT make the storage layer report `uuid` or `slug` as discarded
undeclared properties.

#### Scenario: A schema that declares neither key
- **GIVEN** a seed object with top-level `uuid` and `slug` for a schema that
  declares neither property
- **WHEN** the seed data is imported
- **THEN** the stored object's uuid and slug metadata equal the seed's values
- **AND** the object's data holds neither `uuid` nor `slug`
- **AND** no "Discarding" warning names them

#### Scenario: A schema that declares slug
- **GIVEN** the same seed object for a schema that declares a `slug` property
- **WHEN** the seed data is imported
- **THEN** the object's data keeps `slug` and drops `uuid`

#### Scenario: An object listed under components.objects
- **GIVEN** a configuration listing an object under `components.objects` with
  `@self.slug`, a top-level `uuid` and a top-level `slug`, for a schema that
  declares neither
- **WHEN** the configuration is imported and the object does not exist yet
- **THEN** the object is created under the listed uuid
- **AND** its data holds neither `uuid` nor `slug`
- @e2e exclude {import path, covered by ImportHandlerComponentsObjectsIdentityTest}
