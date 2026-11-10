# Spec delta: Virtual schemas + inheritance-aware semantic providers

**Status:** proposed
**Scope:** openregister
**Depends on:** cross-app-semantic-references (extended), object-source-providers (reused), schema JSON-LD config (reused)

## Motivation (context for the delta)

ADR-048 resolves a semantic-type URI to any schema implementing it, but the
richest providers of common types are Nextcloud itself (User→Person, Group→
Organization, …) and are not OR schemas; OR ships no native organisation schema;
and `allOf` inheritance does not carry semantic markers. This delta exposes NC
entities as read-only virtual schemas via the existing object-source mechanism
(no new resolution machinery), ships an always-available Directory register, and
makes implemented-types inheritance-aware. See hydra ADR-049.

## ADDED Requirements

### Requirement: Implemented types include allOf ancestors

A schema's implemented semantic types SHALL be the union of its own markers and
the implemented types of every schema it extends via `allOf`, resolved
recursively with a circular-reference guard. A child MUST NOT lose an ancestor's
type and MAY declare additional types. A schema with no `allOf` MUST be
unaffected.

#### Scenario: Child extending a Person schema resolves as Person

- **GIVEN** schema `base-person` implements `https://schema.org/Person` and schema `citizen` extends it via `allOf` while declaring no marker of its own
- **WHEN** `citizen`'s implemented types are computed
- **THEN** they include `https://schema.org/Person`, and `resolveSchemaByImplements("https://schema.org/Person")` can resolve to `citizen`

#### Scenario: Own markers and ancestor markers union

- **GIVEN** a child that `allOf`-extends an `Organization` schema and itself declares `configuration.implements = ["https://openregister.app/ns#Vendor"]`
- **WHEN** its implemented types are computed
- **THEN** they include BOTH `https://openregister.app/ns#Vendor` and the inherited `https://schema.org/Organization`

#### Scenario: No allOf is unchanged

- **WHEN** a schema without any `allOf` computes its implemented types
- **THEN** the result is exactly its own markers (no regression)

### Requirement: Nextcloud entities are exposed as read-only virtual schemas

The system SHALL be able to expose a Nextcloud app's entity type as a virtual
schema — a normal schema row carrying `x-openregister-object-source.provider` and
a schema-level `x-schema-org` marker — whose objects are listed/read live from an
object-source provider and never persisted. Such a virtual schema MUST be
discoverable by the semantic resolver with no change to the resolver, and MUST be
subject to the ADR-048 app-enabled gate via its register/schema `application`.

#### Scenario: Virtual schema is resolvable like any schema

- **GIVEN** a virtual schema `nc-group` with `x-schema-org: schema:Organization` and an object-source provider, on a register whose `application` is enabled
- **WHEN** `resolveSchemaByImplements("https://schema.org/Organization")` runs
- **THEN** `nc-group` is a candidate and can be returned, with no resolver code change

#### Scenario: Objects are served live and read-only

- **WHEN** `GET /api/objects/{register}/nc-group` is called
- **THEN** the response lists live Nextcloud groups via the provider (not a magic table), and a write to a virtual object is rejected

### Requirement: An always-available Directory register ships with OpenRegister

OpenRegister SHALL seed a virtual `directory` register (`application:
openregister`, always enabled) with an `nc-user` schema implementing
`https://schema.org/Person` and an `nc-group` schema implementing
`https://schema.org/Organization`, each backed by a read-only object-source
provider over the Nextcloud user/group managers. As a result every instance MUST
have a Person provider and an Organization provider available with no third-party
app installed, and these MUST NOT be filtered out by the app-enabled gate.

#### Scenario: Organization resolves on a bare instance

- **GIVEN** a Nextcloud instance with OpenRegister and no leaf app declaring `schema:Organization`
- **WHEN** `resolveSchemaByImplements("https://schema.org/Organization")` runs
- **THEN** it resolves to the `nc-group` schema in the `directory` register

#### Scenario: Directory schemas list live users and groups

- **WHEN** `GET /api/objects/directory/nc-group` and `/api/objects/directory/nc-user` are called
- **THEN** they return live Nextcloud groups and users respectively, read-only, scoped by the caller's Nextcloud permissions
