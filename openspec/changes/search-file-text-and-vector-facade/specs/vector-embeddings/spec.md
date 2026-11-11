# vector-embeddings

## ADDED Requirements

### Requirement: Other apps search vectors through a published facade, as the caller

OpenRegister SHALL publish `VectorSearchFacade` with `isAvailable()`,
`searchSemantic(query, limit, views)`, `searchHybrid(query, limit, views)` and
`embedTexts(texts)`, returning rows with `entity_id`, `entity_type`,
`chunk_text`, `similarity` and `metadata`. Every result the facade or the file
search routes return SHALL be one the caller can open: a file through the
caller's own folder, an object through the object read check.

#### Scenario: hermiq retrieves passages for an agent

- **GIVEN** an agent user who can read the register `beleid` and two of three files in a knowledge folder
- **WHEN** hermiq calls `searchSemantic("parkeervergunning", 10, [<beleid view>])` as that user
- **THEN** the rows come from `beleid` objects and the two readable files only, ranked by similarity
- @e2e exclude {specified only; covered by VectorSearchFacadeTest in task 3.1}

#### Scenario: the file search route no longer leaks other users' files

- **GIVEN** a file of another user that was vectorised
- **WHEN** a user calls `POST /api/search/files/semantic` with a query that matches it
- **THEN** that file is not in the results
- @e2e exclude {API contract; covered by FileSearchControllerTest in task 2.2}

#### Scenario: no embedding provider

- **GIVEN** an instance without an embedding provider
- **WHEN** hermiq calls `isAvailable()`
- **THEN** it is false, and hermiq keeps its keyword search
- @e2e exclude {specified only; covered by VectorSearchFacadeTest in task 3.1}
