# Tasks — Retrofit aggregations-backend-native

All tasks are retroactive annotation work — code already exists.

- [x] task-1: aggregations-backend-native#REQ-ABN-001 — AggregationCache MUST scope cache keys by register, schema, name, canonicalised filter, and RBAC scope (retroactive annotation)
- [x] task-2: aggregations-backend-native#REQ-ABN-002 — AggregationRunner MUST dispatch external → Postgres-native → PHP fallback, with non-fatal external errors (retroactive annotation)
- [x] task-3: aggregations-backend-native#REQ-ABN-003 — AggregationRunner MUST gate aggregate execution behind RBAC list-permission on the target schema (retroactive annotation)
- [x] task-4: aggregations-backend-native#REQ-ABN-004 — tryNativeAggregation MUST enforce soft-delete + multi-tenant predicates and reject any filter shape outside the closed operator allow-list (retroactive annotation)
- [x] task-5: aggregations-backend-native#REQ-ABN-005 — AggregationThresholdListener MUST fire notifications only on rising-edge transitions and persist state in a 30-day cache (retroactive annotation)
