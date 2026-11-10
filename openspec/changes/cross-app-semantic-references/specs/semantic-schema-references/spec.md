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
The set of implemented type URIs MUST be `configuration.implements` when
present (authoritative — the defaults below are then NOT merged), otherwise the
union of `configuration.jsonld.type` and a schema-level `x-schema-org` marker.
The `x-schema-org` marker MAY be supplied as a **top-level** field (a sibling of
`properties`, matching the fleet's ADR-011 annotation convention) or inside
`configuration`; a top-level marker MUST be folded into
`configuration['x-schema-org']` on schema write so it survives save/import and
is visible on the live schema. A marker MAY be a single value or an array, and
each entry MAY be a compact schema.org CURIE (`schema:Organization`, expanded to
`https://schema.org/Organization`) or an absolute IRI. Only absolute-IRI entries
(after CURIE expansion) count; a non-IRI `implements` value is dropped.

#### Scenario: Implements defaults from jsonld.type

- **GIVEN** a schema with `configuration.jsonld.type = "https://schema.org/Organization"` and no `configuration.implements`
- **WHEN** its implemented types are computed
- **THEN** they are `["https://schema.org/Organization"]`

#### Scenario: Top-level x-schema-org survives save and resolves

- **GIVEN** a schema written with a top-level `"x-schema-org": "schema:Organization"` (a sibling of `properties`, not inside `configuration`) and no `configuration.implements`
- **WHEN** the schema is saved or imported and then re-read
- **THEN** the live schema carries `configuration['x-schema-org'] = "schema:Organization"` and its implemented types include `https://schema.org/Organization`

#### Scenario: Multiple advertised capability URIs

- **GIVEN** a schema with `configuration.implements = ["https://schema.org/Organization", "https://openregister.app/ns#Vendor"]`
- **WHEN** its implemented types are computed
- **THEN** both URIs are returned, while the object's `@type` stays the single `jsonld.type`

#### Scenario: Non-IRI implements value dropped

- **WHEN** a schema is written with `configuration.implements = ["Organization", "https://schema.org/Organization"]`
- **THEN** only `https://schema.org/Organization` is advertised (the non-IRI entry is dropped)

### Requirement: A property can reference a semantic type

The system SHALL let a schema property reference cross-app objects by canonical
semantic type. A property MAY declare `referenceSemanticType` (an absolute IRI)
and an optional `referenceSemanticApp` hint; the stored value is the referenced
object's UUID. `referenceSemanticType` MUST be validated as a well-formed IRI on
schema write, and is independent of `referenceType` (an integration id) and of
`$ref` (a concrete schema).

#### Scenario: Valid semantic reference accepted

- **WHEN** a `product` schema declares `vendor` with `referenceSemanticType = "https://schema.org/Organization"` and `format = uuid`
- **THEN** the schema is accepted and the property surfaces `referenceSemanticType` to the render layer

#### Scenario: Malformed semantic reference rejected

- **WHEN** a property declares `referenceSemanticType = "Organization"` (not an absolute IRI)
- **THEN** the schema write is rejected with a validation error

### Requirement: Resolution is null-safe across installed schemas

The system SHALL resolve a semantic-type URI to a schema that implements it,
enumerating all registers (org/RBAC-scoped), and SHALL return `null` — never
raise — when no available schema implements the URI. Any schema adhering to the
requested URI is an acceptable provider; the resolver MUST NOT require
sophisticated selection. When more than one schema adheres, the resolver SHALL
pick deterministically (first by slug, optionally biased to the consuming
register when that hint is supplied) and MUST emit a WARN log naming the pick so
ambiguous vocabulary stays observable.

#### Scenario: No provider installed → null

- **GIVEN** no installed schema implements `https://schema.org/Organization`
- **WHEN** `resolveSchemaByImplements("https://schema.org/Organization")` is called
- **THEN** it returns `null` and no exception is raised

#### Scenario: Single provider resolves

- **GIVEN** exactly one installed schema (shillinq `Payee`) implements `https://schema.org/Organization`
- **WHEN** the URI is resolved
- **THEN** the `Payee` schema (its register + slug) is returned

#### Scenario: Multiple providers → deterministic pick

- **GIVEN** two installed schemas implement the same URI
- **WHEN** the URI is resolved
- **THEN** the pick is deterministic — the first candidate by slug, or the provider in the consuming schema's own register when that hint is supplied
- **AND** a WARN log records the ambiguity and the chosen provider

### Requirement: A disabled provider app degrades to no provider

Resolution SHALL treat a schema as available only when its owning app is
installed AND enabled. The owning app id MUST be resolved from the schema's own
`application` field first (the reliable per-schema signal), then the owning
register's `application`. When that owning app names a concrete app that is not
enabled, the resolver MUST skip that schema as if it were not installed, and the
`resolve-by-implements` endpoint MUST return `{ "resolved": false }` (HTTP 200,
never 500). Schemas with no named owning app (core `openregister`, or an
undeclared owner) MUST NOT be filtered out by this check, and the check MUST
remain null-safe when the app manager is unavailable.

#### Scenario: Provider app enabled → resolves

- **GIVEN** shillinq is enabled and its `Payee` schema implements `https://schema.org/Organization`
- **WHEN** the URI is resolved
- **THEN** the `Payee` schema is returned

#### Scenario: Provider app disabled → resolved:false

- **GIVEN** shillinq's `Payee` schema still exists in OR but the shillinq app is disabled
- **WHEN** `GET /api/schemas/resolve-by-implements?uri=https://schema.org/Organization` is called
- **THEN** the response is `{ "resolved": false }` with HTTP 200 and no exception is raised

### Requirement: Absent provider degrades to a disabled, explained form field

The generated form SHALL render a `referenceSemanticType` field as **disabled**,
with a mouse-over tooltip explaining the supporting app is not installed, when no
installed schema provides that semantic type — reusing the integration-availability
reason copy. When a provider IS resolved, the field SHALL render as a searchable
object picker over the provider schema, storing the referenced UUID. A property
without `referenceSemanticType` MUST be unaffected.

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
