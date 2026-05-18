# Proposal: add-openregister-discovered-capabilities

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + register annotations + OAS surface. No PHP
service classes are authored by this proposal; the implementing
`opsx-apply` cycle adds thin handlers where engine gaps require it.

## Summary

Add five Specter-discovered capability enhancements to OpenRegister
as **`or-core-extensions`** — each spec extends an existing canonical
OR capability rather than introducing a parallel surface. Three are
extension deltas (`### MODIFIED Requirements` on `referential-integrity`,
on `graphql-api`, and on `row-field-level-security` + `audit-trail-immutable`);
one is a new capability (`extended-field-types`) that bolts onto the
JSON-Schema property type vocabulary; one is a new sibling capability
(`nested-aggregations`) that explicitly delineates from faceting and
from the in-flight `aggregations-backend-native` change.

This change conforms to the OR `openspec/config.yaml` rules: every
spec references the OR services it extends (per ADR-022's
"apps consume OR abstractions" — applied here at the OR layer itself
to its own internal abstractions), and migrations are called out
where schema changes are introduced.

## Motivation

Specter's intelligence pipeline surfaced five clusters of market-demanded
capabilities that OR's existing spec backlog does not fully cover. The
five clusters were initially scoped as net-new specs; cross-checking
against OR's existing capability library (the largest in the fleet —
77 specs in `openspec/specs/`) shows that three of the five overlap
heavily with established specs and should land as **delta modifications**
rather than parallel duplicates. The remaining two are genuinely new
surfaces that nonetheless reference an existing OR capability as their
foundation.

The duplicate-avoidance pass below documents each judgment call so the
reviewer can verify the spec author did not create a parallel
abstraction in violation of ADR-022's first anti-pattern.

### Per-capability overlap pass

| Specter capability | Overlap finding | Disposition |
|---|---|---|
| `data-integrity-relations` (unique-constraints, link-existing-columns, upsert, cross-project-relations) | Existing `referential-integrity` covers CASCADE / SET_NULL / RESTRICT semantics on `$ref` deletes; `reference-existence-validation` covers save-time existence checks; `linked-entity-types` covers cross-app NC entity links. **None cover unique constraints, upsert semantics, or explicit cross-register `$ref` traversal.** | **MODIFIED delta on `referential-integrity`** — adds unique-constraint declaration, upsert-key declaration, and cross-register `$ref` resolution. Reuses `referential-integrity`'s onDelete / cascade machinery; does not duplicate. |
| `extended-field-types` (calendar-range, recurrence, uuid, gallery-cover-url, image-url, color) | Existing `schema-driven-read-coercion` coerces the base JSON-Schema types (string / boolean / number / integer / array / object) but does not define new property types. The `geo-metadata-kaart` change adds a map UI but not a `geo-point` primitive. **No existing OR spec defines a registry of extended property types.** | **NEW spec** — `extended-field-types`. Per ADR-031, each new type is schema-declarative (`type: <name>` + `format: <hint>`); no per-type PHP class. References `schema-driven-read-coercion` as the read-time canonical converter. |
| `graph-and-rest-api` (API access, API for automation, API generation, Graph API) | Existing `graphql-api` spec is comprehensive — covers SchemaGenerator, scalars, queries, mutations, subscriptions, RBAC integration, complexity analysis, error formatting, SSE. The REST API is the foundation OR is built on (every `openspec/specs/*` capability assumes REST as the baseline). **The gap is unification**: a single OAS 3.1 document that documents REST + Graph in one place + parity guarantees between the two. | **MODIFIED delta on `graphql-api`** — adds the unified OAS surface requirement and REST↔Graph parity guarantees. Does not duplicate scalar / type / resolver definitions. |
| `nested-aggregations` (rollup-of-rollup, multi-level group-by, role-scoped aggregations) | Existing `faceting-configuration` is rich on facets (terms / date_histogram / range buckets). The in-flight `aggregations-backend-native` change covers single-pass backend execution (Postgres / Solr / ES). **Neither covers multi-level group-by hierarchies, rollup-of-rollup composition, or HAVING-style post-aggregation filters.** | **NEW sibling spec** — `nested-aggregations`. Explicitly delineates: faceting = "how many objects per bucket"; aggregation = "compute metric over filtered set"; nested aggregation = "compute metric over groups of groups, optionally filtered by aggregate value." Hard-references `faceting-configuration` and `aggregations-backend-native` as siblings, not parents. |
| `row-level-security-audit` (RLS + per-action audit) | Existing `row-field-level-security` covers the RLS + FLS contract end-to-end (group + match conditions, `$userId`/`$organisation`/`$now` dynamic vars, consistent enforcement across REST/GraphQL/search/export). Existing `audit-trail-immutable` + `audit-hash-chain` cover the audit log. **What is NOT explicit: every RLS-filtered query result MUST be auditable, every audit entry MUST record the RLS rule that applied, and the cross-cutting contract that "no row read without an audit record of what rule allowed it."** | **MODIFIED delta on `row-field-level-security` (primary) + `audit-trail-immutable` (secondary)** — adds the cross-cutting integration contract. Thin glue spec; no new primitives. |

### Net result

- **5 specs proposed** = 3 MODIFIED-existing deltas + 2 NEW capabilities.
- **Zero parallel surfaces** to existing OR specs.
- Each new capability references the OR spec(s) it extends, per the
  ADR-022 principle applied recursively to OR's own spec backlog.

## Affected Projects

- [x] Project: openregister — adds 2 new capability specs
  (`extended-field-types`, `nested-aggregations`) and 3 MODIFIED
  deltas on `referential-integrity`, `graphql-api`,
  `row-field-level-security` (+ audit-trail-immutable). All under
  `openspec/changes/add-openregister-discovered-capabilities/specs/`.
- [ ] No source code changes in this proposal — spec-only. Downstream
  app repos (opencatalogi, softwarecatalog, decidesk, …) consume the
  new surfaces transparently through the existing ObjectService /
  CnIndexPage / GraphQL endpoints.
- [ ] Project: hydra / `openspec/architecture/adr-031-schema-declarative-business-logic.md`
  — extended-field-types reinforces ADR-031's declarative-first stance
  but does NOT modify the ADR.

## Scope

### In Scope

- Five spec.md files under
  `openspec/changes/add-openregister-discovered-capabilities/specs/`
  (one per capability cluster above).
- Cross-references in each spec to the OR spec(s) it extends, with
  explicit `Depends on:` lines naming the canonical capability.
- Tier classification uniform: `or-core-extensions`.
- REQ-prefixes:
  - `data-integrity-relations` → `DIR-*`
  - `extended-field-types` → `EFT-*`
  - `graphql-api` (delta) → `GRA-*` (additional REQs on the existing
    spec; uses GRA prefix to keep new requirements distinct from the
    existing graphql-api REQ numbering)
  - `nested-aggregations` → `NAG-*`
  - `row-level-security-audit` (delta) → `RLS-*` (additional REQs)

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes land via a
  separate `opsx-apply` cycle. Per ADR-032, that cycle will chain
  off this `kind: config` spec.
- **Net-new capability surfaces beyond the five above** — the
  intelligence pipeline surfaced additional clusters (multi-tenant
  quota dashboards, schema visual editor, real-time presence) that
  are handled by separate Specter-generated proposals, not folded
  here.
- **Implementation of the cross-register `$ref` resolution
  performance optimisations** (e.g. DataLoader batching across
  registers) — Tier-2 work after the basic contract is in place.

## Approach

Five spec deltas, each landing in its own subfolder of `specs/`:

1. **`specs/data-integrity-relations/spec.md`** — `## MODIFIED Requirements`
   delta on the existing `referential-integrity` spec. Adds unique
   constraints, upsert key, cross-register `$ref` resolution.
2. **`specs/extended-field-types/spec.md`** — `## ADDED Requirements`
   on a brand-new spec. Registers six new property types and the
   shape contract each must satisfy. Per ADR-031, each type is
   declarative; no per-type PHP class.
3. **`specs/graphql-api/spec.md`** — `## MODIFIED Requirements` delta
   on the existing `graphql-api` spec. Adds unified OAS-3.1 surface
   + REST↔Graph parity guarantees. Existing GraphQL machinery is
   untouched.
4. **`specs/nested-aggregations/spec.md`** — `## ADDED Requirements`
   on a brand-new sibling spec. Explicitly delineates from faceting
   and from `aggregations-backend-native`; defines the nested
   group-by + rollup-of-rollup + HAVING-style contract.
5. **`specs/row-level-security-audit/spec.md`** — `## MODIFIED Requirements`
   delta on `row-field-level-security`, plus a small `## ADDED Requirements`
   block on `audit-trail-immutable` (recorded under the same spec.md;
   the deltas are clearly separated by capability heading). Thin
   glue; defines the integration contract.

All specs follow the conduction-schema format (RFC 2119,
`### Requirement: <name>` (existing OR convention) and `### REQ-{PREFIX}-NNN:`
for net-new requirements where the prefix avoids collision with the
existing spec's own numbering, `#### Scenario:` with exactly 4
hashtags, GIVEN/WHEN/THEN). The shillinq pilot
(`add-shillinq-bookkeeping-foundation`) is the style reference.

## New Dependencies

None. Every requirement consumes an existing OR abstraction; no new
PHP libraries, no new npm packages.

## Impact

- `openspec/specs/referential-integrity/spec.md` — gains a unique-constraint
  block + upsert block + cross-register resolution block (via the
  delta in this change).
- `openspec/specs/graphql-api/spec.md` — gains a unified-OAS block + a
  REST↔Graph parity block (via the delta in this change).
- `openspec/specs/row-field-level-security/spec.md` and
  `openspec/specs/audit-trail-immutable/spec.md` — both gain explicit
  cross-references to each other and an integration contract block
  (via the delta in this change).
- `openspec/specs/extended-field-types/spec.md` — net-new spec file
  created on archive.
- `openspec/specs/nested-aggregations/spec.md` — net-new spec file
  created on archive.
- `lib/Service/Object/SchemaTypeConverter.php` — gains type-handler
  registrations for the six new types (per ADR-031, declarative; the
  converter dispatches by `type` + `format`).
- `lib/Service/SchemaService.php` — registers the new types in the
  validator's `$validTypes` map.
- `lib/Settings/openregister_register.json` — seed data for any new
  schemas demonstrating the extended types (per OR config.yaml's
  Seed Data rule).
- No new database tables. Unique constraints land as indices on the
  magic tables via existing `MagicMapper::buildTableColumnsFromSchema()`.

## Cross-Project Dependencies

None — this is OR's own internal capability surface. Downstream apps
(decidesk, procest, opencatalogi, …) consume the new surfaces
transparently. The `extended-field-types` spec calls out that
downstream apps using a new type SHOULD reference this spec in their
register file's `description` field for traceability.

## Risks

### Risk 1: Cross-register $ref resolution performance

**Severity**: Medium
**Mitigation**: Cross-register $ref traversal must not introduce N+1
queries. The implementing cycle will reuse the existing
`RelationHandler` DataLoader batching (per `graphql-api` spec). For
REST endpoints, the `_extend` mechanism (per `linked-entity-types`)
already supports batched enrichment; the cross-register case extends
the same path. Document the performance budget in the implementing
spec's design.md.

### Risk 2: Extended field types and ADR-031 declarative-first

**Severity**: Low
**Mitigation**: Each new type MUST be declarative (`type: <name>` +
optional `format: <hint>` in JSON Schema). The implementing cycle
adds one entry per type in `SchemaTypeConverter`'s dispatch table;
no per-type service class. If a type genuinely needs PHP for
validation (e.g. `recurrence` RRULE parsing), the validator is a
single-method static utility called from the converter, per ADR-031's
"PHP guards remain a legitimate seam" exception.

### Risk 3: GraphQL ↔ REST parity drift

**Severity**: Low-Medium
**Mitigation**: The unified-OAS requirement is the forcing function:
both REST and GraphQL types/operations are generated from the same
schema source-of-truth, so they cannot diverge by construction. The
parity test (per the spec's REQ-GRA-* scenarios) is mechanical and
runs in CI.

### Risk 4: Audit trail volume from RLS-filtered list queries

**Severity**: Medium
**Mitigation**: A "list with 1000 RLS-filtered rows" SHOULD NOT
produce 1000 audit entries. The integration contract per
`row-level-security-audit` defines the audit granularity as
"one entry per query with the rule digest + result count", not "one
entry per row." Documented in the spec; implementing cycle adds the
aggregation guard.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` runs on the spec. After implementation (separate cycle),
rollback follows the OR migration pattern: drop the new unique
indices (additive only — no data loss), revert the
SchemaTypeConverter dispatch entries (extended types fall back to
the base JSON type they specialise), and disable the unified-OAS
endpoint (REST + GraphQL each continue to serve their own
documents).

## Open Questions

1. **Upsert semantics on conflict** — should upsert update only the
   declared upsert-key fields, or merge all incoming properties?
   `REQ-DIR-005` defaults to merge; confirm with downstream app
   authors (decidesk uses upsert for participant import;
   opencatalogi for source-of-truth sync).
2. **Recurrence type RRULE library** — RFC 5545 RRULE parsing is
   complex; should OR ship its own parser, or wire in
   `sabre/vobject` (already in the Nextcloud stack)? Lean toward
   sabre/vobject reuse; confirmed in implementing cycle.
3. **Nested aggregation depth limit** — group-by-of-group-by is
   sensible at 2-3 levels; beyond that the result becomes
   incomprehensible. `REQ-NAG-007` proposes a default max depth of
   3, configurable per query; confirm with the BI / dashboard
   consumers (mydash, softwarecatalog).
