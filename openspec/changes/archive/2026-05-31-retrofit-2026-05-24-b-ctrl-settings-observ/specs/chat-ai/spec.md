## ADDED Requirements

### Requirement: Semantic and hybrid vector search endpoints
The system MUST expose endpoints for semantic (vector-embedding) search and hybrid
(keyword + vector) search over registered objects. `SolrController::semanticSearch`
embeds the query and retrieves nearest-neighbour matches; `SolrController::hybridSearch`
combines Solr keyword scoring with vector similarity under caller-supplied weights.
`SettingsController::semanticSearch` and `SettingsController::hybridSearch` are facade
copies of these endpoints that delegate to `VectorizationService`. An empty or
whitespace-only query MUST return HTTP 400, and both endpoints MUST attach a `timestamp`
to the response.

#### Scenario: Semantic search rejects empty query
- **GIVEN** a caller invokes `semanticSearch` with a blank query
- **WHEN** the controller validates input
- **THEN** it MUST return HTTP 400 with a "Query parameter is required" message

#### Scenario: Hybrid search combines keyword and vector results
- **GIVEN** a caller invokes `hybridSearch` with `weights: {solr: 0.5, vector: 0.5}`
- **WHEN** `VectorizationService::hybridSearch` runs
- **THEN** the response MUST merge keyword and vector results and include `query` and `timestamp`

### Requirement: Vectorization and embedding operations endpoints
The system MUST expose admin/operations endpoints for managing object vectorization and
inspecting embedding state. `SolrController` provides `getVectorStats` and
`getVectorizationStats` (coverage/health metrics), `testVectorEmbedding` (probes the
configured embedding provider), `vectorizeObject` (embeds a single object, optional
provider override), and `bulkVectorizeObjects` (batch embedding).

#### Scenario: Test embedding provider connectivity
- **WHEN** `testVectorEmbedding` runs
- **THEN** it MUST probe the configured embedding provider and report whether embeddings can be generated

#### Scenario: Vectorize a single object
- **GIVEN** an object id and an optional provider override
- **WHEN** `vectorizeObject` runs
- **THEN** it MUST generate and store the object's embedding and report the outcome

#### Scenario: Report vectorization coverage
- **WHEN** `getVectorizationStats` runs
- **THEN** it MUST return coverage statistics describing how many objects currently carry embeddings
