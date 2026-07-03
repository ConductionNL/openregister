# Spec delta: Semantic schema references — cross-app references by canonical type

**Status:** proposed
**Scope:** openregister (+ nextcloud-vue render layer)
**Depends on:** integration-registry (existing — extended), schema JSON-LD config (existing — reused)

## Motivation (context for the delta)

OR references are keyed to a specific schema identity (`$ref`) or a specific
integration id (`referenceType`). Neither lets a property reference "an object of
KIND X provided by whatever app supplies it, or null if none is installed." This
delta adds a semantic-type reference resolved across all installed schemas, with
a null-safe resolver and an explicit disabled-field affordance when no provider
is present. It reuses the schema `configuration.jsonld.type` semantic marker
(ADR-011) and the ADR-019 availability engine — no existing reference behaviour
changes. See ADR-048.

## ADDED Requirements

### Requirement: Schemas advertise the canonical types they implement

A schema SHALL be resolvable by the canonical semantic type(s) it implements.
The set of implemented type URIs is `configuration.implements` when present,
otherwise `[configuration.jsonld.type]`. Every entry MUST be an absolute IRI;
a non-IRI value is rejected on schema write.

#### Scenario: Implements defaults from jsonld.type

- **GIVEN** a schema with `configuration.jsonld.type = "https://schema.org/Organization"` and no `configuration.implements`
- **WHEN** its implemented types are computed
- **THEN** they are `["https://schema.org/Organization"]`

#### Scenario: Multiple advertised capability URIs

- **GIVEN** a schema with `configuration.implements = ["https://schema.org/Organization", "https://openregister.app/ns#Vendor"]`
- **WHEN** its implemented types are computed
- **THEN** both URIs are returned, while the object's `@type` stays the single `jsonld.type`

#### Scenario: Non-IRI implements value rejected

- **WHEN** a schema is written with `configuration.implements = ["Organization"]` (not an absolute IRI)
- **THEN** the write is rejected with a validation error

### Requirement: A property can reference a semantic type

A schema property MAY declare `referenceSemanticType` (an absolute IRI) and an
optional `referenceSemanticApp` hint. The stored value is the referenced
object's UUID. `referenceSemanticType` is validated as a well-formed IRI on
schema write; it is independent of `referenceType` (an integration id) and of
`$ref` (a concrete schema).

#### Scenario: Valid semantic reference accepted

- **WHEN** a `product` schema declares `vendor` with `referenceSemanticType = "https://schema.org/Organization"` and `format = uuid`
- **THEN** the schema is accepted and the property surfaces `referenceSemanticType` to the render layer

#### Scenario: Malformed semantic reference rejected

- **WHEN** a property declares `referenceSemanticType = "Organization"` (not an absolute IRI)
- **THEN** the schema write is rejected with a validation error

### Requirement: Resolution is null-safe across installed schemas

The system SHALL resolve a semantic-type URI to the installed schema that
implements it, enumerating all registers (org/RBAC-scoped), and SHALL return
`null` — never raise — when no installed schema implements the URI.

#### Scenario: No provider installed → null

- **GIVEN** no installed schema implements `https://schema.org/Organization`
- **WHEN** `resolveSchemaByImplements("https://schema.org/Organization")` is called
- **THEN** it returns `null` and no exception is raised

#### Scenario: Single provider resolves

- **GIVEN** exactly one installed schema (shillinq `Payee`) implements `https://schema.org/Organization`
- **WHEN** the URI is resolved
- **THEN** the `Payee` schema (its register + slug) is returned

#### Scenario: Multiple providers → deterministic tie-break

- **GIVEN** two installed schemas implement the same URI
- **WHEN** the URI is resolved for a consuming schema
- **THEN** the pick is deterministic — the provider in the consuming schema's own register if any, else the one whose app matches `referenceSemanticApp`, else the first by slug
- **AND** a WARN log records the ambiguity and the chosen provider

### Requirement: Absent provider degrades to a disabled, explained form field

When a property declares `referenceSemanticType` and no installed schema
provides it, the generated form SHALL render the field **disabled** with a
mouse-over tooltip explaining the supporting app is not installed, reusing the
integration-availability reason copy. When a provider IS resolved, the field
SHALL render as a searchable object picker over the provider schema, storing the
referenced UUID. A property without `referenceSemanticType` is unaffected.

#### Scenario: Provider present → object picker

- **GIVEN** shillinq is installed and provides `https://schema.org/Organization`
- **WHEN** the `product` form renders the `vendor` field
- **THEN** it shows a searchable dropdown of `Payee` objects and stores the chosen UUID

#### Scenario: Provider absent → disabled + tooltip

- **GIVEN** shillinq is not installed
- **WHEN** the `product` form renders the `vendor` field
- **THEN** the field is disabled and a mouse-over tooltip states the supporting app (shillinq) is not installed
- **AND** the rest of the `product` form remains fully editable and saveable

#### Scenario: Backward compatibility

- **WHEN** a form renders a property that declares neither `referenceType` nor `referenceSemanticType`
- **THEN** it renders exactly as before this change (the normal auto-generated field)
