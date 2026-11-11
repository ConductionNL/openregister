# aggregations-backend-native

## ADDED Requirements

### Requirement: An object write MUST evict the aggregations of its schema

When an object is created, updated, deleted or transitioned, the cached
aggregations of its register and schema SHALL be evicted under the same key
the aggregation cache writes. The cache keys entries by register and schema
slug; the object carries ids, so the eviction SHALL resolve the slugs first.
A reference that is already a slug SHALL be used as it is. Aggregations of
other schemas SHALL keep their entries.

#### Scenario: a new line item shows in the next count

- **GIVEN** pipelinq's `leadProduct` count was cached as 8
- **WHEN** a person adds a line item (an object in register 20, schema 34)
- **THEN** the next count reads 9 and is not served from the cache
- @e2e exclude {cache eviction; covered by tests/Unit/Listener/AggregationCacheEvictionBySlugTest.php and a live check on :8099}
