# deep-link-registry Specification

## ADDED Requirements

### Requirement: A search hit's deep link can name the object's own properties

When the unified search formats a hit, the object data handed to the deep-link registry MUST include the object's own declared scalar properties, URL-encoded, so a template can name one (for example `/apps/dossiq/cases/{case}`). `@self` metadata and the resolved `uuid`, `register` and `schema` MUST win over an own property of the same name. Keys starting with `_` (attached by the pipeline, not declared by the schema) and non-scalar values MUST NOT be passed. When the resolved link still contains an unfilled `{placeholder}`, the hit MUST link to OpenRegister's own object page instead.

#### Scenario: A case-owned record opens its case
- **GIVEN** an app registered `/apps/dossiq/cases/{case}` for schema `caseDocument`
- **WHEN** a search hit is a `caseDocument` whose `case` is `c-1`
- **THEN** the hit's URL is `/apps/dossiq/cases/c-1`

#### Scenario: An own property cannot spoof the uuid
- **GIVEN** an object with an own property `uuid: "spoofed"` and `@self.id: "u1"`
- **WHEN** the hit is formatted
- **THEN** `{uuid}` resolves to `u1`

#### Scenario: A value cannot walk out of the path
- **GIVEN** an object whose `case` is `../settings`
- **WHEN** the hit is formatted
- **THEN** `{case}` resolves to `..%2Fsettings`

#### Scenario: An unfilled placeholder falls back
- **GIVEN** the `{case}` template and an object with no `case`
- **WHEN** the hit is formatted
- **THEN** the hit's URL is OpenRegister's `openregister.objects.show` route for the object
