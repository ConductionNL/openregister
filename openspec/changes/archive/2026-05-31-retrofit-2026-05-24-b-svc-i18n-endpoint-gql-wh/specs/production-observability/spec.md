## ADDED Requirements

### Requirement: Metrics aggregation helpers MUST summarize operational metrics from the metrics table

`MetricsService` MUST aggregate rows from the `openregister_metrics` table into the per-day, per-type, and summary shapes consumed by the dashboard and operational reporting, plus the supporting numeric helpers those aggregations rely on.

#### Scenario: Files processed per day

- **GIVEN** file-processed metrics recorded over the last N days
- **WHEN** `getFilesProcessedPerDay(days)` runs
- **THEN** it MUST return a `[date => count]` map, grouped by `%Y-%m-%d` and ordered ascending, restricted to rows within the window

#### Scenario: Embedding statistics with success rate and cost

- **GIVEN** embedding-generated metrics with a `status` of `success` or otherwise
- **WHEN** `getEmbeddingStats(days)` runs
- **THEN** it MUST return `{total, successful, failed, success_rate, estimated_cost_usd, period_days}`
- **AND** `failed` MUST be `total - successful`, `success_rate` MUST come from `calculateSuccessRate()`, and `estimated_cost_usd` MUST be derived from the successful count

#### Scenario: Search latency per search type

- **GIVEN** keyword, semantic, and hybrid search metrics within the window
- **WHEN** `getSearchLatencyStats(days)` runs
- **THEN** it MUST return `[type => {count, avg_ms, min_ms, max_ms}]` computed per search type

#### Scenario: Storage growth and dashboard composition

- **GIVEN** recorded vector/storage metrics
- **WHEN** `getStorageGrowth(days)` runs
- **THEN** it MUST return per-day vector additions plus current storage and an average derived from `calculateAverageVectorsPerDay()`
- **AND** `getDashboardMetrics()` MUST compose `files_processed` (30d), `embedding_stats` (30d), `search_latency` (7d), and `storage_growth` (30d) into a single bundle

#### Scenario: Numeric helper behavior

- **GIVEN** the supporting helpers
- **WHEN** they are called
- **THEN** `calculateSuccessRate(total, successful)` MUST return `0.0` when total is zero, otherwise `(successful / total) * 100` rounded to two decimals
- **AND** `roundAverageMs(value)` MUST coerce a numeric (possibly string) value to a float rounded to two decimals, returning `0.0` for non-numeric input
- **AND** `calculateAverageVectorsPerDay(growthData)` MUST return `0.0` for empty data, otherwise the total divided by the number of days rounded to two decimals

### Requirement: Realtime change records MUST be emitted as CloudEvent-shaped envelopes

`RealtimeService` MUST record a CloudEvents 1.0 shaped change record for a register object on create, update, delete, and transition, persisting it to the realtime event store. A write failure MUST NOT break the originating save pipeline.

#### Scenario: Record a change event

- **GIVEN** an object change of a known type (`or.object.created`, `or.object.updated`, `or.object.deleted`, `or.object.transitioned`)
- **WHEN** `record(eventType, object, extra)` runs
- **THEN** it MUST build a CloudEvents 1.0 envelope with `specversion: "1.0"`, the event type, a `source` of `<baseUrl>/apps/openregister`, a subject of the object URN (or its uuid), a unique id, an ISO 8601 `time`, and a `data` block carrying register, schema, uuid, urn, organisation, owner, actor (session UID), and trigger-specific `extra`
- **AND** it MUST persist a `RealtimeEvent` with the matching fields and the JSON-encoded payload, returning the inserted entity

#### Scenario: Failure is non-fatal

- **GIVEN** the underlying realtime store write throws
- **WHEN** `record()` executes
- **THEN** it MUST catch the error, log a warning, and return `null` so a missed realtime event never breaks the actual save
