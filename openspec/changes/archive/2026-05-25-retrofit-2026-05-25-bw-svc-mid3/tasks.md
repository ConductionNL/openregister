# Tasks

All tasks are `[x]` — the code already exists. This is a retrofit: tasks describe retroactive annotation, not new implementation.

## Reverse-spec (new REQs, annotated against this ghost change)

- [x] task-1: vector-embeddings#REQ-006 — Vector query execution: semantic KNN/cosine similarity search (PHP/database or Solr backend) and hybrid Reciprocal-Rank-Fusion search combining vector + Solr result sets, with per-result source breakdown and graceful degradation when the vector leg fails (retroactive annotation: `VectorEmbeddings::semanticSearch`, `VectorEmbeddings::hybridSearch`, `VectorSearchHandler::semanticSearch`, `VectorSearchHandler::hybridSearch`)

- [x] task-2: aggregations-backend-native#REQ-ABN-006 — Aggregation API request and widget-annotation validation: the REST timeseries request validator (field allow-listing against schema properties, closed interval vocabulary, sub-day-gap date-time field requirement, from/to bounds) and the `x-openregister-widgets` schema-annotation validator (widget type / title / dataSource mode rules) (retroactive annotation: `TimeseriesRequestValidator::validate`, `WidgetAnnotationValidator::validate`)

- [x] task-3: aggregations-backend-native#REQ-ABN-007 — Filter placeholder DSL: resolution of `$now` / `$startOfDay|Week|Month|Year` / `$currentUser` placeholders with signed offset arithmetic (`d`/`w`/`m`/`y` suffixes, unit defaulting per placeholder) and recursive array resolution, consumed by `AggregationRunner` before native/PHP dispatch (retroactive annotation: `PlaceholderResolver::resolve`, `PlaceholderResolver::resolveArray`)

- [x] task-4: data-import-export#REQ-CFG-TRACK-001 — Import-tracking Configuration entity: `createOrUpdateConfiguration` finds-or-creates a `Configuration` per app, extracts title/description/type via an OAS-`info` → `x-openregister` → top-level fallback chain, and merges newly imported register/schema/object IDs with any previously tracked IDs (de-duplicated) so repeated imports accumulate rather than overwrite (idempotent re-import bookkeeping) (retroactive annotation: `ImportHandler::createOrUpdateConfiguration`)

## Cross-reference (no new REQs — methods map to existing tasks in prior changes)

The following methods are twins / read-write counterparts / public entry points of methods already annotated by earlier changes. They are annotated against those existing change tasks, not this ghost change.

- [x] xref-1: `AggregationCache::set`, `::setAdhoc`, `::evictForSchema` → `retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-1` (REQ-ABN-001 — cache key scoping + eviction; write/evict twins of the already-annotated `get`/`getAdhoc`)
- [x] xref-2: `AggregationQuery::toArray` → `retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-1` (canonical ksort-stable wire shape that is the ad-hoc cache key)
- [x] xref-3: `AggregationRunner::runAdhocByRef` → `retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-2` (ref-based convenience wrapper over `runAdhoc` — the same external → native → PHP dispatch)
- [x] xref-4: `ElasticsearchAggregationQueryBuilder::build`, `SolrAggregationQueryBuilder::build` → `retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-2` (translate the portable `AggregationQuery` into native ES/Solr params on the external-backend dispatch leg)
- [x] xref-5: `GeoFilterParser::fromQueryParams` → `retrofit-2026-05-24-annotate-openregister/tasks.md#task-15` (geo-metadata-kaart REQ-GEO-004 — wire-format twin of the already-annotated `fromGeoSearchBody`)
- [x] xref-6: `GeoSpatialEvaluator::matches` → `retrofit-2026-05-24-annotate-openregister/tasks.md#task-15` (geo-metadata-kaart REQ-GEO-004 — public dispatcher over the already-annotated `matchesBbox`/`matchesNear`/`matchesWithin`/`matchesIntersects` helpers)
- [x] xref-7: `GraphQLResolver::resolveRelation` → `retrofit-2026-05-24-b-svc-i18n-endpoint-gql-wh/tasks.md#task-20` (graphql-api REQ-GQL-EXTRAS — public deferred half of the already-annotated DataLoader `flushRelationBuffer`)
- [x] xref-8: `TransitionEngine::availableActions` → `retrofit-2026-05-24-object-lifecycle/tasks.md#task-2` (object-lifecycle REQ-007 — read-only enumeration over the same transition annotation surface; honors the explicit DROP triage recorded by that change that it is "adequately implied by REQ-007's transition lookup")
- [x] xref-9: `CloudEventFormatter::formatAsCloudEvent`, `::formatRequestAsCloudEvent` → `retrofit-2026-04-30-annotate-openregister/tasks.md#task-84` (webhook-payload-mapping Req:CloudEventsFormat — the two public CloudEvents 1.0 formatters)
- [x] xref-10: `EmbeddingGeneratorHandler` Fireworks adapter `embedText`, `embedDocument`, `embedDocuments`, `getEmbeddingLength` → `retrofit-2026-05-24-newcap-vector-embeddings/tasks.md#task-1` (vector-embeddings REQ-001 — the anonymous Fireworks `EmbeddingGeneratorInterface` adapter whose behavior REQ-001 already specifies)
- [x] xref-11: `FileVectorizationStrategy::fetchEntities`, `::extractVectorizationItems`, `::prepareVectorMetadata` → `retrofit-2026-05-24-newcap-vector-embeddings/tasks.md#task-3` (vector-embeddings REQ-003 — concrete `VectorizationStrategyInterface` implementation for file chunks; the interface contract REQ-003 specifies. The earlier change's "file strategy" deferral lands here.)

## Excluded (`@spec exclude` — boilerplate / plumbing)

- [x] excl-1: `StreamYieldChannel::onToken`, `::onToolCall`, `::onToolResult`, `::onHeartbeat`, `::emitToken`, `::emitHeartbeat` — pure pub-sub event forwarder (register-callback / loop-and-invoke); the class is self-documented as "pure forwarding: it does not buffer, format, or filter events"
- [x] excl-2: `GitHubGuards::runGuards` — generic guard-runner combinator (loop guards, short-circuit on first non-null response); carries no policy itself, the actual guards are separately annotated
- [x] excl-3: `AggregationRunner::findSchema` — thin public convenience wrapper over the private `loadSchema` mapper lookup (exposed only so the REST controller can validate field allow-lists before constructing an `AggregationQuery`)

## Deferred (`future-pass:next`)

- Vector query execution (task-1 REQ-006) is captured under `vector-embeddings` because no `search` capability spec exists on this branch. If PR #1791 lands a `search` spec, re-home REQ-006 there in a follow-up.
