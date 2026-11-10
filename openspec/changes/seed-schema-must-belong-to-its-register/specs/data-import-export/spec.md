## ADDED Requirements

### Requirement: A seed is never written into another app's schema its register does not list

When an import resolves a seed object's `@self.schema` slug, it SHALL skip the
seed with a warning when the register lists schemas, the resolved schema is not
among them, the schema names an owning application, and that application is
neither the importing app nor the register's own application. The same rule SHALL
apply to `components.objects`, to `@ref:` pre-resolution and to `seedData`.

#### Scenario: A stale seed resolves to another app's schema

- **GIVEN** stackiq is installed with its schema `organization` (application `stackiq`)
- **AND** opencatalogi's register `publication` lists `catalog` but not `organization`
- **WHEN** opencatalogi imports a seed `{register: publication, schema: organization, slug: default-org}`
- **THEN** the seed is skipped and counted in `skipped.objects`
- **AND** the sibling seed of schema `catalog` is saved

#### Scenario: The app's own schema not yet linked

- **WHEN** opencatalogi imports a seed whose schema has application `opencatalogi` but is not yet in the register's list
- **THEN** the seed is saved

#### Scenario: A cross-app seed into a register that lists the schema

- **WHEN** an app seeds `{register: stackiq, schema: organization}` and register `stackiq` lists that schema
- **THEN** the seed is saved
