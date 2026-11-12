## Context

OpenRegister stores every object in a per-(register, schema) "magic" table
(`oc_openregister_table_{reg}_{schema}`). The Nextcloud unified-search
provider `OCA\OpenRegister\Search\ObjectsProvider` must search across ALL
searchable schemas at once. It delegates to
`ObjectService::searchObjectsPaginated`, which for the cross-schema case routes
into `MagicMapper::searchObjectsPaginatedMultiSchema` and unions the magic
tables.

PR #233 already fixed three crash modes: a metadata-only UNION projection above
a column budget (avoiding Postgres's 1664-column `54011`), and per-schema
scoping of the `@self.schema` `IN`-list in both the count loop and each UNION
arm (avoiding the >1000-expression `IN` cap). What remains:

- **Failure #4 (correctness):** when no register filter is supplied, each
  schema is paired with `reset($registers)` instead of its real owning
  register, so the query targets a non-existent table → empty results.
- **Scale:** one UNION over ALL searchable tables is non-viable at very high
  searchable-schema counts (statement size, arm count).

This change finishes cross-schema unified search **over the magic tables
only** — no secondary index, no Solr. It supersedes the earlier denormalised
`oc_openregister_search_index` proposal per the no-secondary-store directive:
there is no index table, no lifecycle listener, and no backfill.

### Declarative-vs-imperative decision

This change is **imperative**, not declarative. It is query-execution logic:
resolving each schema's owning register, batching (register, schema) pairs into
DB-limit-safe UNION groups, executing them, and merging/sorting/paginating in
PHP. None of that is expressible as schema-level JSON (the way lifecycle state
machines, aggregations, or `x-openregister-flows` are). It lives in PHP inside
`MagicMapper` and the provider.

### Org ADR-001 (data layer)

Org-wide ADR-001 holds that all primary data lives in OpenRegister. This change
introduces **no new store at all** — it reads only the canonical magic tables.
There is nothing to keep in sync and nothing that could drift from the source
of truth.

## Goals / Non-Goals

**Goals:**
- OpenRegister objects appear in Nextcloud unified search, correctly linked to
  their real owning register/table.
- Cross-schema search runs without tripping any DB limit (column count, `IN`
  list, UNION arm count / statement size), at realistic searchable-schema
  counts and degrading gracefully beyond them.
- Preserve the exact security contract: RBAC, tenant isolation (active
  organisation), the `searchable` flag, and the published predicate — all
  enforced inside the existing OR search pipeline the magic query delegates to.
- Magic tables are the sole data source; Solr/Elasticsearch are not used.

**Non-Goals:**
- No secondary/denormalised index table, no lifecycle listener, no backfill.
- Do NOT modify or remove the existing external `search-index` (Solr)
  capability/code — ripping out Solr is a separate cleanup. This change only
  stops depending on it for unified search and notes the deprecation.
- No new caching / early-exit / score-precomputation layer (a future optional
  optimisation, explicitly out of scope).
- No change to single-schema or register-scoped search paths beyond what the
  shared fan-out helper requires.

## Decisions

### Decision 1 — Resolve each schema's owning register via a schema→register map

Build a `schema_id → register` map once per cross-schema search by scanning the
candidate registers and reading each register's `getSchemas()`. For every
searchable schema, pair it with the register whose `getSchemas()` contains that
schema id. This replaces the `reset($registers)` fallback in
`searchObjectsPaginatedMultiSchema` (failure #4). A schema with no resolvable
register, or whose resolved magic table does not exist, is **skipped** (logged,
not fatal). The map must be built from the full set of candidate registers —
not just registers named in a `_register`/`@self.register` filter — so a
schema-only query (the provider's normal call: `@self.schema` = searchable ids,
no register) resolves correctly.

The multi-schema trigger in `searchObjectsPaginated` must also fire on a
schema-only query: today `$isMultiSchemaSearch` additionally requires a
register id / register-id list. It must fire when a schema-id **array** is
present even with no register filter, deriving the register set from the
schema→register map.

**Alternative considered:** cartesian product of every register × every schema.
Rejected — quadratic, and most pairs map to non-existent tables.

### Decision 2 — Bounded, batched fan-out with PHP merge/sort/paginate

Rather than one UNION over ALL resolved pairs, split the resolved
(register, schema) pairs into **batches** of at most `N` arms (a tuned constant,
conservatively chosen to stay under Postgres statement-size / arm-count limits;
the column-count limit is already handled by PR #233's metadata-only
projection). For each batch:

1. Run that batch's UNION over its tables (each arm scoped to its own schema's
   `IN`/columns per PR #233), applying the same WHERE (term + `@self` filters)
   and ordering as a single-batch query.
2. Collect the rows with their relevance/score (and the metadata needed to
   sort: score, then a stable tiebreaker such as `updated` then `uuid`).

After all batches, **merge** the per-batch result sets in PHP, **sort** by
relevance/score then the stable tiebreaker, then apply **offset/limit**
pagination across the merged set. The total count is summed per-schema as PR
#233 already does. Because pagination happens after the merge, each batch is
fetched up to `offset + limit` rows (a bounded over-fetch) so the merged window
is correct; this is documented as the cost of cross-batch ordering.

Only schemas flagged `searchable = true` whose magic table actually exists are
included as arms.

**Alternative considered:** keep a single UNION but cap the schema count.
Rejected — silently drops schemas and still mis-orders; batching keeps all
searchable schemas in scope and produces a correctly ordered, paginated result.

### Decision 3 — Security delegation (unchanged contract)

RBAC, tenant isolation (active organisation), the `searchable` flag, and the
published predicate are all enforced inside the existing magic-table search
pipeline that each batch UNION delegates to (`_rbac: true`,
`_multitenancy: true`, the published predicate, soft-delete exclusion). The
batching/merge layer does not relax or duplicate any access filter — it only
partitions, then re-merges, rows the pipeline already authorised. The provider
constrains the query to `searchable = true` schemas (it already resolves the
non-searchable opt-out set) and never widens results.

### Decision 4 — Portability

Postgres is the primary target; the relevant limits (target-list columns,
`IN`-list size, statement size / UNION arm count) are what the batching is
tuned against. The mapper already detects the platform via
`$this->db->getDatabasePlatform()`. On MariaDB/MySQL the same batching applies;
the batch size constant accounts for that engine's analogous statement/`IN`
limits. No engine-specific feature (trigram, FULLTEXT) is required — matching
uses the existing pipeline's term matching.

### Seed Data

This change adds **no new OpenRegister schemas or registers** and **no new
database table of any kind**. There is therefore **no `_registers.json` seed
data** for this change and none should be created by the apply agent. Sample
identifiers in docs/tests should use the nil UUID
`00000000-0000-0000-0000-000000000000` and placeholders like `<uid>`.

## Risks / Trade-offs

- [Fan-out cost grows with searchable-schema count] → batching keeps each
  statement under DB limits; realistic instances have far fewer searchable
  schemas (the ~1100 here is test pollution). An optional cache / early-exit is
  noted as future work, out of scope.
- [Cross-batch ordering requires per-batch over-fetch of `offset + limit` rows]
  → bounded and documented; correct ordering is preferred over a cheaper but
  wrong page.
- [A schema's owning register cannot be resolved or its table is missing] →
  the schema is skipped and logged; it degrades recall for that schema only,
  never errors the whole search.
- [Deep pagination over many batches] → over-fetch grows with offset; unified
  search uses small page sizes (cap 25) and shallow paging, so this stays
  bounded in practice.
- [Two search code paths remain (batched magic UNION for unified search; other
  callers' existing paths)] → accepted; converging them is out of scope.

## Migration Plan

1. Land the register-resolution fix + batched fan-out in `MagicMapper` and
   confirm the provider reaches the fixed path.
2. No DB migration (no schema change).
3. Rollback: revert the `MagicMapper`/provider changes; behaviour returns to
   the prior (empty-result) state. No data is affected.

## Open Questions

- The batch size constant `N` (UNION arms per statement) — pick a conservative
  default and make it a named constant; tune against the observed
  statement-size limit.
- Relevance/score source for cross-batch sorting — reuse the pipeline's
  existing scoring if present, else fall back to a deterministic
  `updated`/`uuid` order.
- Whether to short-circuit the fan-out once `offset + limit` results are
  collected (early-exit) — deferred as a future optimisation.
