# Design — OpenRegister Discovered Capabilities

## Context

OpenRegister is the foundation app for the entire Conduction fleet
(13+ apps consume it). ADR-022 makes consuming OR abstractions
mandatory; ADR-031 makes declarative-schema metadata the default
shape of those abstractions; ADR-024 makes the manifest renderer the
default frontend shell.

Specter's intelligence pipeline analyses tender requirements + 30K
competitor repositories + 6,843 features and surfaces capability
clusters that OR's existing spec backlog does not yet cover. The
five clusters in this change came out of that scan with the
following counts:

| Cluster | Specter signal |
|---|---|
| data-integrity-relations | 363 feature-mentions (unique-constraints 189 + link-existing 117 + upsert 29 + cross-project 28) |
| extended-field-types | 222 mentions across six type clusters |
| graph-and-rest-api | 114 mentions (API access / automation / generation + Graph) |
| nested-aggregations | 661 mentions (role-based EX metrics 593 + rollup-of-rollup 34 + nested-aggregation 34) |
| row-level-security-audit | 107 mentions + 6 tender citations |

These are real market signals — every cluster has competitor
implementations (NocoDB unique constraints, Directus row-level
security, Strapi GraphQL, Twenty CRM rollup-of-rollup). The shape
question for OR is *not whether to ship them*, it is *how to ship
them without duplicating the existing abstractions*.

This design doc records the cross-cutting decisions that the five
spec deltas inherit, so the deltas themselves stay short and
shape-focused.

## Goals

- Land each Specter cluster as **either a delta on an existing OR
  spec** or **a clearly-delineated new sibling spec** — never as a
  parallel surface.
- Keep the entire change `kind: config` per ADR-032 — declarative
  metadata + OAS surface only; no PHP service classes in this
  proposal.
- Express every new property type, every new constraint declaration,
  every new aggregation shape as **JSON-Schema-compatible** so the
  validator / OAS generator / GraphQL SchemaGenerator / read coercer
  pick them up automatically.
- Maintain the **competent-architect-readable contract** property —
  an OR consumer reading the five specs should immediately see (a)
  which existing OR capability each extends, (b) what net-new shape
  they expose, (c) which downstream apps are expected to consume them.

## Non-Goals

- No bespoke PHP service classes. Every spec follows ADR-031's
  declarative-first rule; any PHP that ships in the implementing
  cycle is a thin guard / single-method validator / type handler
  registered through the existing dispatch tables.
- No frontend Vue components beyond what `CnIndexPage` /
  `CnDetailPage` / `CnFormDialog` already render generically from
  schema metadata.
- No new database tables. Unique-constraint indices land on the
  existing magic tables via `MagicMapper::buildTableColumnsFromSchema()`.
- No breaking changes to existing OR public APIs. Every delta is
  additive.

## Reuse Analysis

This change explicitly consumes the following existing OR services
and abstractions — no new equivalents are introduced.

| Existing capability | How the new spec consumes it |
|---|---|
| `referential-integrity` (CASCADE / SET_NULL / RESTRICT on `$ref` deletes) | `data-integrity-relations` MODIFIED delta — extends this spec; reuses the `ReferentialIntegrityService` machinery for the cross-register case. |
| `reference-existence-validation` (`validateReference` flag on save) | `data-integrity-relations` MODIFIED delta — extends the existence-check pipeline; cross-register `$ref` reuses `resolveSchemaReference()`. |
| `linked-entity-types` (cross-app NC entity link infrastructure) | `data-integrity-relations` references it as the precedent for cross-namespace linking; does NOT touch its surface (NC entities ≠ OR cross-register refs). |
| `schema-driven-read-coercion` (`SchemaTypeConverter` service) | `extended-field-types` registers six new type handlers through this single converter; no parallel coercion path. |
| `graphql-api` (SchemaGenerator + scalars + resolvers + RBAC) | `graphql-api` MODIFIED delta adds the unified OAS layer ON TOP of this spec; does not touch generator / scalar / resolver internals. |
| `oas-generation` (existing REST OAS pipeline) | `graphql-api` MODIFIED delta unifies the OAS document — REST surface comes from this spec, Graph surface comes from `graphql-api`'s SchemaGenerator, both fold into one OAS 3.1 file. |
| `faceting-configuration` (per-property facets, terms/date/range) | `nested-aggregations` siblings it; explicitly says faceting answers "how many objects per bucket", nested-aggregation answers "compute metric over groups of groups." No overlap on responsibility. |
| `aggregations-backend-native` (Postgres/Solr/ES single-pass execution) | `nested-aggregations` MUST flow through the same `AggregationRunner::run()` entry point and inherit the same backend dispatch + 60s cache. |
| `row-field-level-security` (RLS + FLS via group + match + dynamic vars) | `row-level-security-audit` MODIFIED delta adds the cross-cutting integration with audit; does not touch the rule evaluation engine. |
| `audit-trail-immutable` + `audit-hash-chain` (immutable, SHA-256 chained log) | `row-level-security-audit` MODIFIED delta declares that every RLS-mediated query result MUST be audited with the rule digest; the audit machinery itself is unchanged. |
| `MagicMapper` (single read/write entry point for all magic tables) | All five specs flow through MagicMapper — no parallel data path. |
| `RelationHandler` (DataLoader-style batched resolution) | `data-integrity-relations` cross-register $ref resolution reuses this; no parallel batcher. |
| `RegisterService`, `SchemaService` (per OR project rules) | Schema registrations for any new types go through these services; no `\OC::$server` access, no direct DB. |

## Decisions

### D1 — Every spec is a delta or a sibling, never a parallel

The duplicate-avoidance pass in `proposal.md` is normative. If a
reviewer finds a new requirement that semantically overlaps with an
existing OR spec, the requirement MUST be re-expressed as a
`## MODIFIED Requirements` block on the existing spec, OR the
overlap must be documented in `proposal.md` with an explicit
reason. The default disposition is DELTA, not NEW.

**Alternative considered**: write five fully self-contained specs.
Rejected — OR's spec backlog is the largest in the fleet (77 specs);
parallel surfaces compound the maintenance burden and force
downstream apps to pick which abstraction to consume.

### D2 — Declarative-first, per ADR-031

Every new capability is declared in JSON Schema or in
`x-openregister-*` schema extensions. No `lib/Service/*Service.php`
class authored by this proposal. Concretely:

| Capability | Declarative shape |
|---|---|
| Unique constraints | `unique: true` on a property OR `uniqueIndex: [<property-list>]` at schema level |
| Upsert keys | `upsertKey: [<property-list>]` at schema level |
| Cross-register `$ref` | `$ref: "register-slug/schema-slug"` (extended grammar) |
| New property types | `type: <name>` in JSON Schema property block; `SchemaTypeConverter` dispatches by type |
| Unified OAS | Auto-generated from schema + GraphQL SchemaGenerator output; no hand-authored doc |
| Nested aggregations | `x-openregister-aggregations` extended grammar: nested `groupBy: [...]` + `having: {...}` |
| RLS audit integration | `x-openregister-rls.audit: true` flag on schema or per-rule |

**Alternative considered**: author a `CrossRegisterRefService`,
`UniqueConstraintService`, `UpsertService`, etc. Rejected per
ADR-031 — these are exactly the lifecycle / aggregation / calculation
classes the decidesk migration is moving away from. Declarative
metadata in the schema is the canonical shape.

### D3 — Unique constraint enforcement at the storage layer

Unique constraints land as **database indices on the magic tables**
(`UNIQUE INDEX` on the relevant columns). Enforcement happens at
write time via the database's own constraint mechanism — not via a
PHP pre-write check. The dual benefit: race-safe (the database is
the arbiter), and queryable (the index is reused by ORDER BY /
WHERE on the unique field).

`MagicMapper::buildTableColumnsFromSchema()` is the existing entry
point that translates schema metadata to DDL; extending it to emit
`UNIQUE INDEX` clauses is a single change in one method.

### D4 — Extended types are pure read-coercion + validation

Per `schema-driven-read-coercion`, `SchemaTypeConverter` is the
single point where a database row value is converted to a
PHP-typed object property. Extended types register here. The
storage shape (the JSON column) is unchanged — a `color` is stored
as `"#a4b8ff"`, a `geo-point` as `{"lat": 52.37, "lon": 4.89}`, a
`recurrence` as the RFC 5545 RRULE string. The type-specific logic
is the validator + the read coercer; no special storage path.

This is also how the existing `NcMail` / `NcContact` / `NcCalendar`
types in `linked-entity-types` work — declared in
`PropertyValidatorHandler::$validTypes`, stored as JSON envelopes,
no separate table.

### D5 — REST↔Graph parity via shared OAS source

The unified OAS 3.1 document is generated from the schema registry,
not hand-authored. REST operations come from `oas-generation`'s
existing pipeline; Graph operations come from a new walk over
`SchemaGenerator`'s output, emitted as
`x-graphql-{queries,mutations,subscriptions}` extensions on the
OAS document. A single CI test verifies that for every schema, the
REST `GET /api/objects/{register}/{schema}` and the GraphQL
`{schema}List` resolver produce identical response shapes.

**Alternative considered**: ship REST and Graph as separate OAS
documents and assert parity in CI. Rejected — separate docs drift,
asserting parity is a forensic exercise. Single-doc generation
makes parity a property of construction, not a property of
testing.

### D6 — Nested aggregation execution flows through AggregationRunner

`nested-aggregations` does NOT introduce a parallel runner. The
existing `AggregationRunner::run()` entry point is extended to
parse nested `groupBy` arrays (e.g. `groupBy: ["status", "assignee"]`)
and `having` clauses; the backend dispatcher (per
`aggregations-backend-native`) handles the SQL translation.
- Postgres: nested `GROUP BY status, assignee` + `HAVING <expr>`.
- Solr: pivot facets with `facet.pivot.mincount`.
- Elasticsearch: nested `terms` aggregations + `bucket_selector`
  pipeline.
- PHP fallback: nested grouping in memory; document the perf cliff
  beyond 100K rows.

The nested-aggregation contract is therefore a *declarative shape
extension* of an existing runner, not a new runner.

### D7 — RLS-audit integration is per-query, not per-row

A LIST endpoint returning 1000 RLS-filtered rows emits ONE audit
entry with `{queryDigest, ruleDigest, rowCount, schemaUuid,
registerUuid}`, not 1000. A GET endpoint returning a single object
emits ONE audit entry as usual. The granularity contract matters:
without it, audit volume on a busy API explodes by ×N where N is
average list size.

The existing `audit-trail-immutable` machinery already records
non-mutation reads selectively (per its REQ-2 ("of sensitive
data")); this delta makes RLS-filtered reads the canonical
"sensitive read" trigger and locks the per-query granularity into
the contract.

### D8 — Spec sizing per ADR-032 — this change is `kind: config`

Every spec in this change is declarative — schema metadata, OAS
generation, dispatch-table registrations. No `lib/Service/*Service.php`
authored. Per ADR-032, this means:
- 200-turn Sonnet builder budget is sufficient for the implementing
  cycle.
- Reviewer scope is schema-validation + ADR-031 declarative-fit
  check + integration test coverage. ~3-4 gates instead of 18.
- If the implementing cycle discovers a piece that genuinely needs
  bespoke PHP (e.g. a non-trivial RRULE expansion for `recurrence`
  type queries), it splits into a chain per ADR-032: this config
  spec lands first; the small PHP-glue spec depends on it.

## Risks (cross-cutting — per-spec risks live in each spec.md)

### Risk A: Implementing cycle slips into "code" centre-of-mass

**Severity**: Medium
**Mitigation**: The proposal is `kind: config` per ADR-032. The
implementing cycle's reviewer MUST flag any spec.md task that
authors a new `lib/Service/*Service.php` class — that is the signal
the spec slipped from config to code and needs the ADR-032 chain
treatment. The reviewer's check is mechanical (file extension scan).

### Risk B: Backwards compatibility on extended `$ref` grammar

**Severity**: Low
**Mitigation**: The new cross-register `$ref` grammar
(`"register-slug/schema-slug"` with a slash) does NOT collide with
the existing grammar (numeric ID, UUID, slug, JSON Schema path,
URL). `resolveSchemaReference()` already supports all those forms;
the new form is recognised by the presence of a `/` separator.
Existing `$ref` values continue to resolve unchanged.

### Risk C: GraphQL parity asserted incorrectly

**Severity**: Low
**Mitigation**: D5's CI parity test is the forcing function. If the
test is too lax (e.g. only checks field names, not types), parity
drifts silently. The spec's REQ-GRA-* scenarios MUST include
field-name + type + nullability + cardinality checks at minimum.

## Seed Data

Per OR's `openspec/config.yaml` rules.proposal: "Reference shared
nextcloud-app spec for app structure requirements" — this change
introduces no new register schemas in production; it extends the
spec library only. The implementing cycle adds **demonstration
schemas** in `lib/Settings/openregister_register.json` (or a
dedicated demo register) showing each extended type with 3-5 seed
objects per schema, per the OR seed-data rule. Concretely:

| Demo schema | Demonstrates | Seed count |
|---|---|---|
| `DemoEvent` | `calendar-range`, `recurrence` | 5 events (single, weekly, monthly, exception, ended) |
| `DemoLocation` | `geo-point` | 5 locations (NL municipalities) |
| `DemoProduct` | `color`, `gallery-cover-url`, `image-url` | 5 products (varied colors + sample images) |
| `DemoMember` | `uuid` (typed) + cross-register `$ref` to `DemoLocation` | 5 members each pointing at a Location |

Seed data is loaded via the standard `ConfigurationService::importFromApp()`
repair-step pattern; idempotent via slug matching.

## Open Questions (cross-cutting)

1. **Demo schemas in OR vs. separate demo app** — does it make
   sense to ship demonstration schemas inside the openregister
   register file, or should they live in a separate
   `openregister-demos` app? The implementing cycle resolves;
   default is inline (per the seed-data rule applying to OR
   itself).
2. **Unified OAS 3.1 endpoint location** — `/api/oas` (new
   canonical) vs. evolving `/api/openapi` (existing). Cross-check
   with `oas-generation` spec on archive; the implementing cycle
   may need a small redirect.
3. **HAVING clause grammar** — should the nested-aggregation
   `having` clause reuse the existing query operator vocabulary
   (`$gte`, `$lte`, `$eq`) or introduce a SQL-style grammar? Lean
   toward operator vocabulary (consistent with the rest of OR's
   query API); locked in implementing cycle.
