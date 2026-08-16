# OpenAPI Generation

## Purpose
Auto-generate OpenAPI specs from register/schema definitions AND expose the underlying registry
meta-entities (registers, schemas, sources, mappings, applications, agents, endpoints, consumers)
through a uniform REST CRUD surface that the generated OAS documents.

## ADDED Requirements

### Requirement: Registry meta-entity controllers MUST expose a uniform resource-CRUD HTTP contract
Each registry meta-entity controller MUST expose the same five-verb REST resource contract,
registered via the `resources` block (plus an explicit `PATCH` route) in `appinfo/routes.php`:
`index` (GET collection), `show` (GET item), `create` (POST), `update` (PUT), `patch` (PATCH),
and `destroy` (DELETE). This applies to `RegistersController`, `SchemasController`,
`SourcesController`, `MappingsController`, `ApplicationsController`, `AgentsController`,
`EndpointsController`, and `ConsumersController`. The controllers MUST delegate persistence to
their respective `*Mapper` (`findAll`, `find`, `createFromArray`, `updateFromArray`, `delete`).
This requirement covers ONLY the resource-CRUD verbs; resource-specific operational methods
(export, import, publish, stats, test, schemas, objects, logs) are governed by their own
capability specs.

#### Scenario: List a meta-entity collection
- **GIVEN** any of the eight registry meta-entity resources (e.g. `sources`)
- **WHEN** `GET /api/sources` is called
- **THEN** the response MUST return HTTP 200 with a JSON body containing a `results` array of the matching entities
- **AND** the controller MUST strip the internal query params (`_limit`, `_offset`, `_page`, `_search`, `_route`) from the filter set before querying
- **AND** when `_page` and `_limit` are both provided, the offset MUST be computed as `(_page - 1) * _limit`

#### Scenario: Collection responses MAY include a total count
- **GIVEN** a resource whose controller exposes a count (e.g. `consumers`, `webhooks`-style controllers, `views`)
- **WHEN** the collection endpoint is called
- **THEN** the response MAY include a `total` field alongside `results`
- **AND** controllers that do not compute a count (e.g. `sources`) MUST still return a `results` array

#### Scenario: Fetch a single meta-entity by ID
- **GIVEN** a source with ID `5` exists
- **WHEN** `GET /api/sources/5` is called
- **THEN** the response MUST return HTTP 200 with the entity's JSON serialization
- **AND** if no entity matches the ID, the response MUST return HTTP 404 with an `{ "error": ... }` body (mapped from `DoesNotExistException`)

#### Scenario: Create a new meta-entity
- **GIVEN** a POST body of entity properties
- **WHEN** `POST /api/{resource}` is called
- **THEN** the controller MUST strip framework-injected and internal params (keys starting with `_`, and any supplied `id`) before persisting
- **AND** the entity MUST be created via the mapper's `createFromArray()`
- **AND** the response MUST return the persisted entity (HTTP 201 where the controller sets it explicitly, e.g. `ConsumersController`, otherwise HTTP 200)

#### Scenario: Update a meta-entity (PUT)
- **GIVEN** a source with ID `5` exists
- **WHEN** `PUT /api/sources/5` is called with updated properties
- **THEN** the controller MUST strip internal `_`-prefixed params and immutable fields (`id`, and where applicable `organisation`, `owner`, `created`) from the body
- **AND** the entity MUST be updated via `updateFromArray()` and returned
- **AND** updating a non-existent ID MUST return HTTP 404 for controllers that catch `DoesNotExistException`

#### Scenario: Patch delegates to update
- **GIVEN** any registry meta-entity controller
- **WHEN** `PATCH /api/{resource}/{id}` is called
- **THEN** the `patch()` method MUST delegate to `update($id)` (partial update via the same mapper write path)

#### Scenario: Delete a meta-entity
- **GIVEN** a source with ID `5` exists
- **WHEN** `DELETE /api/sources/5` is called
- **THEN** the entity MUST be deleted via the mapper and the response MUST return an empty JSON body
- **AND** deleting a non-existent ID MUST return HTTP 404 for controllers that catch `DoesNotExistException`
