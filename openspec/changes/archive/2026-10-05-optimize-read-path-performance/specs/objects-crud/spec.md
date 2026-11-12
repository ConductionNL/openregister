## ADDED Requirements

### Requirement: A single-object read renders exactly once

The single-object read path (`GET .../objects/{register}/{schema}/{id}`) SHALL execute exactly one
render pass per response. The retrieval step (`ObjectService::find()`) MUST be able to return the
raw entity without rendering when the caller is itself the render site, while still performing
object retrieval, the cross-schema uuid fallback, the per-object read permission check, and AVG
read logging. Server-side writeOnly redaction and property read-authorization stripping MUST be
applied by that single render pass on the response path — never zero times, never twice.

#### Scenario: show() is the single render site

- **WHEN** a client requests a single object via the objects API
- **THEN** the controller obtains the raw entity without a render pass (`find(_render: false)`)
- **AND** renders it exactly once with the request's extend/filter/fields/unset parameters
- **AND** writeOnly properties are absent from the response body

#### Scenario: Internal callers keep rendered reads

- **WHEN** any other caller invokes `ObjectService::find()` without the `_render` argument
- **THEN** the returned entity is rendered exactly as before this change

### Requirement: Single reads resolve inverse properties through the batched machinery

When a single-entity render extends an `inversedBy` property, the system SHALL resolve the
referencing objects through the same schema-targeted batched lookup the list path uses
(`findByRelationBatchInSchema` against the target schema's magic table), populating the inverse
relation cache and serving the properties from it. The preload MUST cover ALL of the schema's
inverse properties — not only the extended ones — because a single read resolves every inverse
property once any one of them is extended; a partial preload would silently empty the others in
the response. The generic cross-table reverse-reference scan (`findByRelation`) SHALL only run as
a resilience fallback when the batched preload cannot populate the cache (e.g. an unresolvable
target schema reference). For the extended inverse property, a single read MUST produce the same
value as a list read of the same object with the same extend.

#### Scenario: Single read uses the schema-targeted batch lookup

- **GIVEN** a schema with an `inversedBy` property and one referencing object in the target schema
- **WHEN** the object is rendered individually with the inverse property extended
- **THEN** the referencing object is found via the schema-targeted batched lookup
- **AND** no cross-table reverse-reference scan is executed

#### Scenario: Single and list reads agree on the extended inverse property

- **WHEN** the same object is rendered once via the single-read path and once via the list path,
  both extending the same inverse property
- **THEN** both renders return an identical value for that inverse property

#### Scenario: Non-extended inverse properties keep their resolved values on single reads

- **GIVEN** a schema with two `inversedBy` properties, each with a referencing object
- **WHEN** the object is rendered individually extending only one of the two inverse properties
- **THEN** the extended inverse property contains its referencing object
- **AND** the other inverse property is also populated with its referencing object — it is not
  emptied by the batched preload

### Requirement: uuid scope resolution is cached per request

The system SHALL keep a request-scoped cache of uuid → resolved (register, schema) contexts.
After a uuid has been resolved once in a request — directly or via the cross-schema fallback —
subsequent `find()` calls for that uuid SHALL target the resolved register/schema directly instead
of re-missing the caller-supplied stale scope and re-running the cross-table search. The cache MUST
NOT change fallback semantics: the first stale-scope read still resolves via the cross-table
fallback, a cache entry that no longer resolves is invalidated and falls back, and permission
checks run on every call.

#### Scenario: Repeated stale-scope read skips the cross-table scan

- **GIVEN** a uuid already resolved once in this request via the cross-schema fallback
- **WHEN** the same uuid is read again under the same stale register/schema scope
- **THEN** exactly one scoped lookup runs, targeting the object's true register and schema
- **AND** no cross-table search is executed

#### Scenario: Stale cache entry falls back safely

- **GIVEN** a cached uuid scope whose object has since moved or been deleted
- **WHEN** the cached scoped lookup misses
- **THEN** the cache entry is invalidated and the existing cross-table fallback runs unchanged
