---
status: done
---

# integration-maps-overview Specification

## Purpose
Lets an authenticated user register page-level map overview widgets scoped to a register and schema, then query an RBAC-scoped marker point set drawn from each object's geometry. A default Dutch PDOK WMTS base layer is applied as declarative metadata unless one is supplied, and the register/schema scope is caller-immutable so it cannot be spoofed through filters. Point queries run the canonical OR read path with RBAC enforced for non-admins, returning a uniform point list that never acts as an enumeration oracle.
## Requirements
### Requirement: Register a page-level map overview widget

The system SHALL let an authenticated user declare (upsert by stable key) a
page-level map overview widget scoped by a register and a schema, with an
optional title, an optional geo-property hint, an optional default filter set
and an optional declarative base-layer config. Registering MUST declare a `map`
page widget on the `IntegrationRegistry` so the render layer can discover it.
When no base layer is supplied a default (Dutch PDOK WMTS) MUST be applied as
declarative metadata; a supplied base layer MUST replace it. The maps maths /
point selection is owned by the leaf; OpenRegister owns the render contract.

#### Scenario: Register an overview and declare its widget
- **GIVEN** an authenticated user
- **WHEN** `MapsOverviewService::registerOverview('cases-on-map', register, schema, ...)` is called
- **THEN** a page widget `maps-overview:cases-on-map` of type `map` MUST be registered
- **AND** its config MUST carry the register / schema scope and a declarative base layer
- `@e2e exclude` Backend render-surface contract consumed by the procest "cases on map" leaf; covered by `MapsOverviewServiceTest`. No OR-side UI flow.

#### Scenario: Empty key or missing scope is rejected
- **GIVEN** an authenticated user
- **WHEN** an overview is registered with an empty key, register or schema
- **THEN** the system MUST throw an `InvalidArgumentException`
- `@e2e exclude` Backend validation; covered by `MapsOverviewServiceTest`.

#### Scenario: Anonymous user cannot register
- **GIVEN** no logged-in user
- **WHEN** `MapsOverviewService::ensureCanRegister()` is called
- **THEN** the system MUST throw an `InvalidArgumentException` (fail-closed write)
- `@e2e exclude` Backend fail-closed guard; covered by `MapsOverviewServiceTest`.

### Requirement: Query the map marker point set RBAC-scoped

The system SHALL expose `GET /api/integrations/maps/overviews/{register}/{schema}/points`
to query the marker point set for a register/schema (with optional narrowing
filters), returning a list of `{id,label,lat,lng,register,schema,geometry}`
markers. The query MUST run the canonical OpenRegister read path with
`_rbac: true` for non-admins so that only objects the current user (anonymous
included) may read are returned; it MUST NOT bypass RBAC. The register/schema
scope keys MUST be caller-immutable (not overridable through the filter bag).
Objects without a recognisable geometry MUST be skipped. The response MUST be a
uniform point list (empty when nothing is readable), never an enumeration oracle.

#### Scenario: Markers extracted from object geometry
- **GIVEN** readable objects, some with Point/Polygon geometry and some without
- **WHEN** `MapsOverviewService::queryPoints(register, schema)` is called
- **THEN** each geometry-bearing object MUST yield a `{id,label,lat,lng,...}` marker
- **AND** geometry-less objects MUST be skipped
- `@e2e exclude` Backend marker extraction; covered by `MapsOverviewServiceTest`.

#### Scenario: Non-admin / anonymous read is RBAC-scoped
- **GIVEN** a non-admin or anonymous caller
- **WHEN** `queryPoints()` runs
- **THEN** the read MUST delegate to the OR read path with `_rbac: true`
- **AND** only objects the caller may read can appear as markers (fail-closed)
- `@e2e exclude` Backend RBAC read; covered by `MapsOverviewServiceTest`.

#### Scenario: Scope keys cannot be spoofed
- **GIVEN** a caller that supplies `register` / `schema` inside the filter bag
- **WHEN** `queryPoints(register, schema, filters)` runs
- **THEN** the service-supplied register/schema scope MUST win over the filter values
- `@e2e exclude` Backend IDOR guard; covered by `MapsOverviewServiceTest`.

