# Retrofit annotations: integration-registry capability (ADR-019)

## Why

The reverse-spec sub-cluster `rspec-newcap-pluggable-integration-registry-and-providers` scanned 24 files under `lib/Service/Integration/` (the registry, the abstract base, the external router, the property-reference validator, the query-time contract, the 5 builtin providers, and the 19 leaf providers + 1 trait).

**Existing-cap check (mandatory step 1 of the reverse-spec workflow):**

- `openspec/specs/integration-registry/` — does **not** exist on `development`.
- `openspec/changes/pluggable-integration-registry/` — **exists** and is the in-flight umbrella that MINTS the `integration-registry` capability. Its spec delta at `openspec/changes/pluggable-integration-registry/specs/integration-registry/spec.md` already lists 9 ADDED requirements covering: `IntegrationProvider` interface contract, `IntegrationRegistry` service, 8 built-in NC provider migration, schema `linkedTypes` validation, external-strategy routing through OpenConnector, OCS capabilities exposure, CI parity gate, scaffold script, and an OCC list command.
- Every per-integration leaf has its own in-flight change under `openspec/changes/integration-{slug}/` (activity, analytics, bookmarks, calendar, collectives, contacts, cospend, deck, email, forms, maps, openproject, photos, polls, shares, talk, time-tracker, xwiki — 18 leaves total; flow shares space with the workflow umbrella).

**Decision: EXTEND, not mint.** Minting a parallel `integration-registry` cap in this retrofit change would create two competing spec deltas for the same capability. Instead this retrofit change:

1. Adopts the in-flight `pluggable-integration-registry` change's spec delta verbatim (no parallel REQs authored here).
2. Adds `@spec` PHPDoc tags to the **9 provider files in this sub-cluster that still lack one**, each pointing at its matching leaf change. The other 22 files in the sub-cluster (interface, registry, abstract base, external router, property reference validator, query-time contract, 5 builtins, and 10 already-annotated leaves) already carry `@spec` tags pointing at `pluggable-integration-registry/tasks.md#task-N` or the matching leaf change.
3. Documents the extend-vs-mint decision so the next reverse-spec pass on this directory does not re-attempt the mint.

## What changes

- 9 `lib/Service/Integration/Providers/*.php` files gain `@spec` annotations pointing at their leaf change in `openspec/changes/integration-{slug}/tasks.md`.
- No spec delta is authored under this change — see [pluggable-integration-registry](../pluggable-integration-registry/specs/integration-registry/spec.md) for the canonical capability spec.

## Impact

- **Affected specs**: none (annotation-only).
- **Affected code**: 9 files, docblock-only edits.
- **Affected capability**: `integration-registry` — owned by `pluggable-integration-registry` (in-flight); this change merely improves coverage annotations against it.

## Observations on the sub-cluster

- The sub-cluster JSON's `notes` suggested a 2-way split into `integration-registry-core` + `integration-builtin-providers`. The in-flight umbrella does NOT split that way — it keeps the contract and the 5 builtins in one cap (`integration-registry`) and lets each NC-app provider live in its own leaf cap (`integration-{slug}` mirrored to `generic-integrations`). The split-suggestion is therefore moot; the natural code-vs-spec boundary already lives where the umbrella drew it.
- All 19 leaf providers in `lib/Service/Integration/Providers/` (NC-native + external) collapse to `getStorageStrategy() ∈ {'link-table', 'query-time', 'external'}` plus a small `MarkerLookupTrait` for the `[or:{uuid}]` text-marker pattern shared by 9 of them (activity, analytics, collectives, cospend, maps, photos, time, forms-fallback, photos). That trait is the only piece of cross-leaf shared code worth flagging — the umbrella spec covers it via REQ-1 (interface) but the trait itself is implementation detail.
- `QueryTimeContract.php` (HTTP-501 envelope helper + 2 s render budget for `query-time` providers) is annotated against the umbrella, but its semantics (timeout + envelope shape) are not formally captured in the umbrella spec delta. Flagging for the next pass — if the cap is split post-archival, this becomes a candidate REQ-10.
