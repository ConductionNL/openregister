## ADDED Requirements

### Requirement: Relation detection MUST only record genuine references in @self.relations

A string value MUST be recorded in `@self.relations` ONLY when it is a genuine reference to another object, as the save/import pipeline scans each string property.
A string value qualifies as a genuine reference when EITHER of the following holds: (a) the value
matches a reference pattern — a canonical UUID (8-4-4-4-12 hex), a prefixed-UUID (e.g. `id-<uuid>`,
`ref-<uuid>`), or a value accepted by URL validation; OR (b) the schema property that holds the value
explicitly declares it a reference — i.e. the property has `type: object`, a `format` of `uuid`,
`uri`, or `url`, or carries a `$ref` / `inversedBy` declaration. Schema-declared detection is
authoritative and MUST take precedence over pattern matching.

The pipeline MUST NOT record a string as a relation purely because it is long and contains a hyphen
or underscore. Ordinary scalar values — dates, enum/code values, and business identifiers — that do
not match a reference pattern and are not held by a schema-declared reference property MUST NOT be
recorded in `@self.relations`. This rule MUST be applied identically wherever relation scanning
occurs in the save/import pipeline, so behavior cannot diverge between the single-save, bulk, and
cascade code paths.

This requirement governs only the derived `@self.relations` map. It does NOT change which schema
properties exist, the save pipeline ordering, aggregation, lifecycle transitions, or notification
declarations.

#### Scenario: Canonical UUID value is recorded as a relation
- **WHEN** an object property holds the string `00000000-0000-0000-0000-000000000000`
- **THEN** the pipeline MUST record that property path in `@self.relations` with the UUID value

#### Scenario: Prefixed-UUID value is recorded as a relation
- **WHEN** an object property holds the string `id-00000000-0000-0000-0000-000000000000`
- **THEN** the pipeline MUST record that property path in `@self.relations`

#### Scenario: URL value is recorded as a relation
- **WHEN** an object property holds the string `https://example.com/api/objects/00000000-0000-0000-0000-000000000000`
- **THEN** the pipeline MUST record that property path in `@self.relations`

#### Scenario: Schema-declared reference property records its value even without a UUID pattern
- **GIVEN** a schema property declared with `type: object` (or `format: uuid`/`uri`/`url`, or a `$ref`/`inversedBy`)
- **WHEN** an object holds a string value for that property
- **THEN** the pipeline MUST record that property path in `@self.relations`

#### Scenario: Date scalar is NOT recorded as a relation
- **GIVEN** a schema property NOT declared as a reference
- **WHEN** the property holds the string `2026-05-20`
- **THEN** the pipeline MUST NOT record that property path in `@self.relations`

#### Scenario: Enum/code value is NOT recorded as a relation
- **GIVEN** a schema property NOT declared as a reference
- **WHEN** the property holds the string `bank_transfer`
- **THEN** the pipeline MUST NOT record that property path in `@self.relations`

#### Scenario: Business identifier is NOT recorded as a relation
- **GIVEN** a schema property NOT declared as a reference
- **WHEN** the property holds a hyphenated business identifier such as `DEMO-F-2026-04-02` or `demo-administration`
- **THEN** the pipeline MUST NOT record that property path in `@self.relations`

#### Scenario: Detection rule is consistent across single, bulk, and cascade paths
- **GIVEN** the same object payload saved through the single-object path, the bulk path, and the relation-cascade path
- **WHEN** relation scanning runs in each path
- **THEN** every path MUST produce the same `@self.relations` set for that payload
