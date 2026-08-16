---
retrofit: true
---

# Search Index

## Why

The existing search-index requirements cover backend routing, schema
mirroring, and bulk indexing, but not the post-import warmup hook that
ImportService fires after an import completes. That hook schedules a
background Solr warmup whose mode and object cap scale with the import
size so large imports do not block on synchronous indexing. This change
anchors that adaptive scheduling behavior.

## ADDED Requirements

### Requirement: Adaptive Post-Import Search-Index Warmup Scheduling
On import completion the service MUST schedule a one-time background Solr warmup job whose warmup mode and maximum-object cap are derived from the number of objects imported, MUST skip scheduling entirely when nothing was imported, and MUST treat a scheduling failure as non-fatal to the import.

`ImportService::scheduleSolrWarmup()` MUST compute the total objects imported across all sheets and MUST return `false` without scheduling when that total is zero. `ImportService::getRecommendedWarmupMode()` MUST select a warmup mode by import-size tier (large imports get the fastest mode, medium imports a balanced mode, small imports the safe mode). `ImportService::scheduleSmartSolrWarmup()` MUST use the recommended mode and a size-derived object cap (bounded by a hard maximum), MUST default to a delayed schedule with an immediate-run override, and MUST delegate to `scheduleSolrWarmup()`. A failure to enqueue the job MUST be logged and MUST NOT abort or roll back the completed import.

#### Scenario: Large import schedules a fast, capped warmup
- **GIVEN** an import that created a large number of objects
- **WHEN** the smart warmup is scheduled
- **THEN** the recommended mode MUST be the fastest tier
- **AND** the warmup object cap MUST be bounded by the hard maximum

#### Scenario: Empty import skips warmup
- **GIVEN** an import that created and updated zero objects
- **WHEN** warmup scheduling runs
- **THEN** no job MUST be enqueued and the call MUST return false

#### Scenario: Scheduling failure does not fail the import
- **GIVEN** the background job queue rejects the warmup job
- **WHEN** scheduling fails
- **THEN** the failure MUST be logged and the import MUST remain successful
