## ADDED Requirements

### Requirement: Seed-data `$ref` properties MUST resolve to the target UUID, not the raw seed slug
`ImportHandler::importSeedDataObjects()` MUST resolve a seed object's `$ref`-typed property value
(scalar or array-of-`$ref`) to the target object's UUID before persisting the seed object, when the
authored value is a slug rather than an already-resolved UUID. Resolution MUST first check the
schemas/objects already imported in the current run (the same slug-keyed maps used elsewhere in
`importFromJson()`), falling back to a database lookup scoped to the target schema when not found
in-run. A `$ref` value that cannot be resolved MUST be left as-authored and MUST log a warning
naming the property and the unresolved slug — it MUST NOT abort the seed object's import (per-entity
resilience, unchanged).

#### Scenario: A seed object's $ref slug resolves to a UUID
- **GIVEN** seed data for schema `motie` includes an object with property `amends: "motie-duurzaamheid-2025"`
  (a slug, not a UUID), and a seed object with that slug was already imported earlier in the same run
- **WHEN** the referencing seed object is imported
- **THEN** the persisted `amends` value MUST be the earlier-imported object's UUID, not the raw slug

#### Scenario: An unresolvable $ref slug is left as-authored with a warning
- **GIVEN** a seed object's `$ref` property names a slug that does not resolve to any object imported
  in this run or found in the database
- **WHEN** the seed object is imported
- **THEN** the import MUST continue with the raw slug value persisted as-authored
- **AND** a warning MUST be logged naming the property and the unresolved slug

#### Scenario: An already-resolved UUID value is left unchanged
- **GIVEN** a seed object's `$ref` property value is already a valid UUID (not a slug)
- **WHEN** the seed object is imported
- **THEN** the value MUST be persisted unchanged, with no resolution lookup performed
