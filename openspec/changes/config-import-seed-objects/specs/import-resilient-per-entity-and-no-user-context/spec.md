## ADDED Requirements

### Requirement: Top-level objects array is an accepted seed-object source
`importFromJson()` SHALL treat a configuration's top-level `objects` array as a valid seed-object source, folding its entries into the same object-import loop that processes `components.objects`, so seed objects authored at the top level import identically to those nested under `components.objects`. The merge SHALL preserve `components.objects` entries and append top-level entries, de-duplicating by `@self` identity (slug within (register, schema), with `@self.id`/uuid fallback) so an object declared in both keys is imported once. `components.objects` SHALL remain the canonical export location; this requirement only adds the top-level array as an accepted equivalent on the import (including the app-init/forced) path. No app-side change to `loadConfiguration`/`importFromApp` SHALL be required for top-level seed objects to import.

#### Scenario: Top-level seed objects materialise after a forced app import
- **GIVEN** a register configuration with schemas and registers, and a **top-level `objects`** array whose entries each carry `@self` with `register`, `schema`, and `slug`
- **AND** no `components.objects` key is present
- **WHEN** the app triggers `importFromApp()` with `force: true` (the `loadConfiguration` path)
- **THEN** each top-level seed object MUST be persisted via `saveObject()`
- **AND** the saved objects MUST appear in `result['objects']`
- **AND** the schemas and registers MUST still import unchanged

#### Scenario: Re-import of top-level seed objects is idempotent
- **GIVEN** a top-level `objects` seed array that has already been imported once
- **WHEN** the same configuration is imported a second time at the same object version
- **THEN** each seed object MUST be matched to its existing object by `@self` slug within (register, schema) (or `@self.id`/uuid)
- **AND** no duplicate object MUST be created
- **AND** an unchanged-version object MUST be skipped rather than re-created

#### Scenario: Both components.objects and a top-level objects array are merged once
- **GIVEN** a configuration that declares the SAME logical seed object in both `components.objects` and the top-level `objects` array
- **WHEN** `importFromJson()` runs
- **THEN** the object MUST be imported exactly once (the duplicate collapses by `@self` identity)
- **AND** objects unique to either key MUST each import

## MODIFIED Requirements

### Requirement: Per-object import resilience for the main object loop
The main object loop in `importFromJson()` (covering both `components.objects` and the merged top-level `objects` array) SHALL wrap each object's resolve/search/save sequence in its own try/catch. When an object fails validation or persistence (e.g. a missing required property such as `name`), the handler SHALL log a WARNING carrying the object slug and reason, increment a skipped-object counter, and CONTINUE with the remaining objects. A single failing object SHALL NOT abort sibling object imports nor the overall app import. Objects lacking an `@self.slug` SHALL continue to be skipped by the existing slug guard, identically for both the `components.objects` and top-level sources.

#### Scenario: A name-missing object is skipped, not fatal
- **GIVEN** a configuration whose object source (`components.objects` or top-level `objects`) includes one object missing a required property and one valid object
- **WHEN** `importFromJson()` runs
- **THEN** the valid object SHALL be saved and present in `result['objects']`
- **AND** the failing object SHALL be skipped with a logged WARNING (not re-thrown)
- **AND** `result['skipped']['objects']` SHALL be incremented by one
- **AND** the import SHALL complete and return a result rather than throwing

#### Scenario: A slug-less top-level seed object is skipped
- **GIVEN** a top-level `objects` array containing one entry whose `@self` has no `slug` and one valid slugged entry
- **WHEN** `importFromJson()` runs
- **THEN** the slug-less entry SHALL be skipped by the existing slug guard
- **AND** the valid slugged entry SHALL be imported
