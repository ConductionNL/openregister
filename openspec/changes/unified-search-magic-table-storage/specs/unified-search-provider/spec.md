# unified-search-provider

## ADDED Requirements

### Requirement: Unified search MUST return results on magic-table storage

The provider MUST return matching objects regardless of whether objects are
stored in the central `oc_openregister_objects` table or in per-(register,schema)
magic tables (`oc_openregister_table_{reg}_{schema}`). When the provider
constrains a search to the searchable-schema allow-list without a register
scope, OpenRegister MUST resolve the owning register(s) of those schemas and
search the corresponding magic tables, rather than returning an empty result
from an unpopulated central table.

#### Scenario: Search finds a magic-table object with no register scope
- GIVEN objects are stored only in magic tables and the central objects table is empty
- AND the `pet` schema (in register `openbuild-pet-store-production`) is `searchable = true`
- WHEN an admin searches "Rex" in Nextcloud unified search
- THEN the `openregister_objects` provider returns the pet named "Rex"

#### Scenario: Direct register-scoped search and unified search agree
- GIVEN `GET /api/objects/{register}/pet?_search=Rex` returns one object
- WHEN the same term is searched via the unified-search provider
- THEN the same object is returned

### Requirement: Multi-schema search MUST NOT exceed the database column limit

When searching across many schemas whose combined property-column superset would
exceed the database target-list limit (Postgres caps a SELECT target list at
1664 columns), the search MUST fall back to a per-table strategy that merges,
sorts, and paginates results in application code, and MUST log that it did so.
It MUST NOT raise a fatal `SQLSTATE[54011]` error or return an empty result.

#### Scenario: Wide cross-schema search falls back instead of failing
- GIVEN a unified search spans enough searchable schemas that the UNION superset would exceed the column cap
- WHEN the search runs
- THEN results are returned via the per-table (sequential) path
- AND a fallback is logged
- AND no `target lists can have at most 1664 entries` error surfaces

### Requirement: Unified search SHOULD use the search index for scale

To keep unified search responsive on installs with many searchable schemas, the
provider SHOULD consult the `search-index` capability (one FTS row per object)
as its primary source, applying RBAC, tenant isolation, and the published
predicate as query predicates. The magic-table path (with register resolution
and the column-cap fallback) MUST remain available as a fallback when the index
is absent or disabled, so search never silently returns nothing during index
rollout or backfill.

#### Scenario: Provider reads the index when available
- GIVEN the search index is populated for the searchable schemas
- WHEN a user searches a matching term
- THEN the provider returns results from a single index query (no per-table UNION/scan)

#### Scenario: Provider falls back when the index is empty
- GIVEN the search index is disabled or not yet backfilled
- WHEN a user searches a matching term
- THEN the provider returns results via the magic-table path
