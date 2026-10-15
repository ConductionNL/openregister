## MODIFIED Requirements

### Requirement: Search results MUST respect OR RBAC, tenant isolation, and the published predicate

The provider MUST execute cross-schema unified search over the
OpenRegister magic tables themselves (no secondary/denormalised index,
and no Solr/Elasticsearch), and MUST delegate access control to the
existing OR search pipeline by querying with `_rbac: true` and
`_multitenancy: true`. It MUST NOT apply a weaker (or duplicate) access
filter of its own. The result set MUST contain only objects the searching
user may read: objects granted via RBAC scopes, plus objects readable
through the published predicate (`@self.published` set and in the past,
`@self.depublished` unset or in the future), scoped to the user's active
organisation. Soft-deleted objects MUST never be returned. The
batched-fan-out, merge, sort, and pagination layer MUST only re-merge
rows the pipeline already authorised — it MUST NOT widen the result set.

#### Scenario: User only sees objects they may read
- GIVEN user `alice` has an RBAC read grant on schema `client` but not on schema `salary`
- AND a `salary` object and a `client` object both match the term `Jansen`
- WHEN `alice` searches for `Jansen`
- THEN the `client` object is in the results
- AND the `salary` object is NOT in the results

#### Scenario: Published objects are findable without an explicit grant
- GIVEN user `bob` has no RBAC grant on schema `publication`
- AND a `publication` object matching `subsidieregeling` has `@self.published` in the past and no `@self.depublished`
- WHEN `bob` searches for `subsidieregeling`
- THEN the published object IS in the results

#### Scenario: Unpublished and depublished objects are hidden from ungranted users
- GIVEN user `bob` has no RBAC grant on schema `publication`
- AND one matching object has `@self.published` unset and another has `@self.depublished` in the past
- WHEN `bob` searches for the matching term
- THEN neither object is in the results

#### Scenario: Tenant isolation in search results
- GIVEN organisations `gemeente-a` and `gemeente-b` each have objects matching `kerkstraat`
- WHEN a user whose active organisation is `gemeente-a` searches for `kerkstraat`
- THEN only `gemeente-a` objects are returned

#### Scenario: Soft-deleted objects never appear
- GIVEN an object matching the term has been soft-deleted
- WHEN any user searches for the term
- THEN the deleted object is NOT in the results

## ADDED Requirements

### Requirement: Cross-schema search MUST resolve each schema's owning register correctly

For a cross-schema search the provider/mapper MUST pair every searchable
schema with its OWN owning register — the register whose `getSchemas()`
contains that schema id — by building a `schema_id → register` map across
all candidate registers. It MUST NOT fall back to an arbitrary register
(e.g. `reset($registers)`) when no register filter is supplied. The query
for each schema MUST target that schema's real magic table
`oc_openregister_table_{register}_{schema}`. A schema whose owning
register cannot be resolved, or whose magic table does not exist, MUST be
skipped (logged), not fail the whole search. The cross-schema path MUST be
reached even when the query carries only a searchable-schema set and no
register filter.

#### Scenario: Object links to its real register, not a fallback
- GIVEN schema `case` belongs to register `case-management` (not the first-loaded register)
- AND a `case` object matches the term
- WHEN a user with the right to read it searches for that term
- THEN the result is found in `case-management`'s magic table
- AND the result links to the `case-management` register/route, not a fallback register

#### Scenario: Schema-only query (no register filter) still searches cross-schema
- GIVEN the provider passes the searchable-schema set with no register filter
- WHEN a user searches for a matching term
- THEN the cross-schema path executes and returns matches across schemas

#### Scenario: Schema with a missing table is skipped, not fatal
- GIVEN one searchable schema has no corresponding magic table
- WHEN a cross-schema search runs
- THEN that schema is skipped and logged
- AND results from the other searchable schemas are still returned

### Requirement: Cross-schema search MUST use bounded, batched fan-out that stays under database limits

The cross-schema search MUST split the resolved (register, schema) pairs
into batches small enough that each batch's `UNION` statement stays under
the database's limits (target-list column count, `IN`-list size, and
statement-size / UNION-arm count). Each batch MUST be executed, then the
per-batch result sets MUST be merged, sorted by relevance/score with a
stable tiebreaker, and paginated (offset/limit) in PHP across all batches.
Only schemas flagged `searchable = true` whose magic table exists MUST be
included. The search MUST NOT issue a single `UNION` over all searchable
tables when that would exceed a database limit.

#### Scenario: Very high searchable-schema count does not trip DB limits
- GIVEN an instance with more than 1000 searchable schemas
- AND an object matching `bestemmingsplan` exists in one of them
- WHEN a user with the right to read it searches for `bestemmingsplan`
- THEN the object is returned
- AND the query does not raise the 1664-column (`54011`) or >1000-expression `IN` errors

#### Scenario: Results are ordered and paginated across batches
- GIVEN matching objects exist across schemas spread over multiple batches
- WHEN the first page of results is requested
- THEN the page contains the top-ranked matches across all batches in relevance order
- AND requesting the next page continues without duplicating earlier entries

#### Scenario: Only searchable schemas with an existing table are queried
- GIVEN schema `internal-note` has `searchable = false`
- AND schema `ghost` is searchable but has no magic table
- WHEN a cross-schema search runs
- THEN neither `internal-note` nor `ghost` contributes a UNION arm
