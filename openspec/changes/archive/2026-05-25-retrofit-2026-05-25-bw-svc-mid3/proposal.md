# Retrofit — backend Service mid-tier batch 3 (vector / aggregation / chat / config / geo / graphql / lifecycle / search / webhook)

## Why

The 2026-05-25 coverage scan (`/tmp/or-scan/bw-svc-mid3.json`) flagged 37 uncovered methods spread across nine `lib/Service/` sub-trees. Most are siblings or read/write twins of methods already annotated by earlier retrofit passes (the 2026-04-23 / 2026-04-30 / 2026-05-24 annotate changes and the `aggregations-backend-native`, `vector-embeddings`, `object-lifecycle`, `geo-metadata-kaart` reverse-spec changes). A handful describe genuine, observable behavior that no existing REQ covers. This change drains the batch with the ADR-003 two-tool approach: extend the matching capability where there is a real gap, cross-reference an existing task where the method is a twin/wrapper of an already-spec'd one, and `@spec exclude` the pure plumbing.

## What Changes

- **MODIFY** capability `vector-embeddings`: add REQ-006 — vector *query*
  execution (semantic KNN/cosine + hybrid Reciprocal-Rank-Fusion search).
- **MODIFY** capability `aggregations-backend-native`: add REQ-ABN-006 (REST
  timeseries-request + `x-openregister-widgets` annotation validation) and
  REQ-ABN-007 (the time/session placeholder DSL the runner resolves filters
  through).
- **MODIFY** capability `data-import-export`: add REQ-CFG-TRACK-001 — the
  import-tracking `Configuration` entity (`createOrUpdateConfiguration`):
  OAS→`x-openregister`→legacy metadata fallback and entity-ID merge for
  idempotent re-import.
- Cross-reference 11 method groups (no new REQs) to existing tasks in prior
  changes — cache write/evict, query-builder `build`, ad-hoc-by-ref, geo
  parser/evaluator, graphql relation resolver, lifecycle available-actions,
  webhook formatter, Fireworks adapter, file strategy.
- **EXCLUDE** as boilerplate (`@spec exclude`): the six `StreamYieldChannel`
  pub-sub forwarder methods, `GitHubGuards::runGuards` (generic guard runner),
  and `AggregationRunner::findSchema` (thin `loadSchema` wrapper).
- No production code behavior changes — annotations and documentation only.

## What the batch contains

| Sub-tree | Methods | Disposition |
| --- | --- | --- |
| `Vectorization/` | 13 | mix: 4 Fireworks-adapter methods + 3 FileVectorizationStrategy methods cross-ref the existing `vector-embeddings` REQs; 4 semantic/hybrid search facade+handler methods → 1 NEW REQ (vector query execution) |
| `Aggregation/` | 10 | mix: cache write/evict + query-builder `build` + ad-hoc-by-ref cross-ref existing `aggregations-backend-native` tasks; 2 validators + placeholder resolver → 2 NEW REQs; `findSchema` excluded |
| `Chat/` | 6 | all `@spec exclude` — `StreamYieldChannel` is a pure pub-sub event forwarder (self-documented "pure forwarding") |
| `Configuration/` | 2 | `createOrUpdateConfiguration` → 1 NEW REQ (import-tracking entity); `runGuards` excluded (generic guard-runner combinator) |
| `Geo/` | 2 | both cross-ref existing `geo-metadata-kaart` REQ-GEO-004 (twins of already-annotated parsers/evaluators) |
| `GraphQL/` | 1 | `resolveRelation` cross-refs `graphql-api` REQ-GQL-EXTRAS (public half of the already-annotated DataLoader `flushRelationBuffer`) |
| `Lifecycle/` | 1 | `availableActions` cross-refs `object-lifecycle` REQ-007 — honoring the explicit DROP triage recorded by `retrofit-2026-05-24-object-lifecycle` ("read-only enumeration adequately implied by REQ-007's transition lookup") |
| `Search/` | 2 | `PlaceholderResolver::resolve` / `::resolveArray` → folded into 1 NEW aggregations REQ (the filter placeholder DSL the runner depends on) |
| `Webhook/` | 2 | `CloudEventFormatter` — cross-ref existing `webhook-payload-mapping` |

## Approach (ADR-003 two-tool)

- **Reverse-spec (4 new REQs):**
  - `vector-embeddings` +1 REQ — vector *query* execution (semantic KNN/cosine + hybrid RRF). The earlier `vector-embeddings` change deferred this to a `search` capability gated on PR #1791; that PR has not landed on this branch and no `search` spec exists, so the observed behavior is captured here against `vector-embeddings` rather than left uncovered.
  - `aggregations-backend-native` +2 REQs — (a) REST timeseries-request + widget-annotation validation; (b) the time/session placeholder DSL (`PlaceholderResolver`) that the runner resolves filters through.
  - `data-import-export` +1 REQ — the import-tracking `Configuration` entity (`createOrUpdateConfiguration`): OAS→`x-openregister`→legacy metadata fallback and entity-ID merge for idempotent re-import.
- **Cross-reference (no new REQs):** cache write/evict, query-builder `build`, ad-hoc-by-ref, geo parser/evaluator, graphql relation resolver, lifecycle available-actions, webhook formatter, Fireworks adapter, file strategy — all map cleanly to an existing task in a prior change.
- **Exclude (boilerplate):** the six `StreamYieldChannel` pub-sub methods, `GitHubGuards::runGuards` (generic short-circuit guard runner), `AggregationRunner::findSchema` (thin `loadSchema` convenience wrapper).

**4 new REQs total** (under the ≤5 cap).

## Counts

- Methods in batch: 37
- Spec'd against a new requirement: 9 (4 new REQs across 3 capabilities)
- Cross-referenced to existing requirements: 19
- Excluded as boilerplate: 9
- New requirements: 4 (`vector-embeddings` REQ-006; `aggregations-backend-native`
  REQ-ABN-006, REQ-ABN-007; `data-import-export` REQ-CFG-TRACK-001)

## Impact

- Affected specs (new requirements): `vector-embeddings`,
  `aggregations-backend-native`, `data-import-export`.
- Capabilities referenced for cross-annotation (no spec change):
  `geo-metadata-kaart`, `graphql-api`, `object-lifecycle`,
  `webhook-payload-mapping`.
- Affected code (annotations only): `lib/Service/Vectorization/` (VectorEmbeddings,
  VectorSearchHandler, EmbeddingGeneratorHandler, FileVectorizationStrategy),
  `lib/Service/Aggregation/` (TimeseriesRequestValidator, WidgetAnnotationValidator,
  PlaceholderResolver, AggregationCache, AggregationQuery, AggregationRunner,
  Elasticsearch/SolrAggregationQueryBuilder), `lib/Service/Configuration/ImportHandler.php`,
  `lib/Service/Chat/StreamYieldChannel.php` (excludes), `lib/Service/Geo/`,
  `lib/Service/GraphQL/GraphQLResolver.php`, `lib/Service/Lifecycle/TransitionEngine.php`,
  `lib/Service/Search/PlaceholderResolver.php`, `lib/Service/Webhook/CloudEventFormatter.php`.
- No migrations, no API changes, no behavioral change.

## Out of scope

- Any reshaping or "fixing" of observed behavior — drift (asymmetric Solr/DB vector storage, serialized-blob KNN, ad-hoc-by-ref re-validation) is flagged in prior change Notes, not corrected here.
- The deferred `search`-capability home for vector query execution. If/when PR #1791 lands a `search` spec, the new `vector-embeddings` query REQ can be re-homed in a follow-up.
- `StreamYieldChannel` SSE framing / heartbeat interleaving — owned by `ChatStreamController` (`ai-chat-companion-streaming`), not the forwarder.

Source: `/tmp/or-scan/bw-svc-mid3.json` (37 methods, 9 sub-trees). Playbook: `.github/docs/claude/retrofit.md`.
