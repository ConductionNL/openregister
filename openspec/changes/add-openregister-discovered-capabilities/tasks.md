# Tasks — OpenRegister Discovered Capabilities

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the five spec deltas — they
> are recorded now so the spec-review gate, dependency planning, and
> downstream-app impact are all visible at proposal time. No source
> files are edited by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm each new capability extends, not duplicates, an existing OR spec

- **spec_ref**: all five specs (folder scan)
- **files**:
  - `openspec/specs/referential-integrity/spec.md`
  - `openspec/specs/reference-existence-validation/spec.md`
  - `openspec/specs/linked-entity-types/spec.md`
  - `openspec/specs/schema-driven-read-coercion/spec.md`
  - `openspec/specs/graphql-api/spec.md`
  - `openspec/specs/oas-generation/spec.md`
  - `openspec/specs/faceting-configuration/spec.md`
  - `openspec/specs/row-field-level-security/spec.md`
  - `openspec/specs/audit-trail-immutable/spec.md`
  - `openspec/specs/audit-hash-chain/spec.md`
  - `openspec/changes/aggregations-backend-native/specs/zoeken-filteren/spec.md`
- **acceptance_criteria**:
  - GIVEN the OR spec library WHEN cross-referenced against this
    change's five spec deltas THEN every new requirement either
    extends an existing spec via `## MODIFIED Requirements` OR
    sits in a new sibling spec with explicit `Depends on:` /
    `Cross-references:` lines naming the OR spec it siblings.
  - GIVEN `proposal.md` Per-capability overlap pass table WHEN
    inspected THEN each row maps to the actual spec disposition in
    `specs/`.
  - GIVEN ADR-022's anti-pattern list WHEN scanned against this
    change's specs THEN no new requirement creates a "parallel link
    table", "app-local schema validator", or "home-grown audit
    trail" — all are consumed from existing OR services.
- [ ] Implement
- [ ] Test

## 1. Spec foundation (this change)

### Task 1.1: Author data-integrity-relations spec (MODIFIED delta)

- **spec_ref**: `openspec/changes/add-openregister-discovered-capabilities/specs/data-integrity-relations/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: openregister` /
    `Tier: or-core-extensions` /
    `Depends on: referential-integrity, reference-existence-validation, linked-entity-types`
    header.
  - GIVEN the spec WHEN scanned THEN it uses `## MODIFIED Requirements`
    on the existing `referential-integrity` spec for cross-register
    `$ref` resolution, and `## ADDED Requirements` blocks for the
    net-new unique-constraint and upsert capabilities.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header per conduction-schema rule).
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-DIR-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
- [x] Implement
- [ ] Test (spec validation — `openspec validate` clean)

### Task 1.2: Author extended-field-types spec (NEW)

- **spec_ref**: `openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: schema-driven-read-coercion`.
  - GIVEN the spec WHEN scanned THEN it defines the six new
    property types (calendar-range, recurrence, uuid, color,
    gallery-cover-url, image-url, geo-point) with storage shape,
    validator contract, indexing semantics, OAS rendering, and
    GraphQL scalar mapping for each.
  - GIVEN each type requirement WHEN inspected THEN it references
    `SchemaTypeConverter` as the single dispatch point per
    `schema-driven-read-coercion`.
  - GIVEN ADR-031 WHEN cross-checked THEN no per-type service class
    is introduced; each type is a single converter entry +
    validator block.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author graphql-api spec delta (MODIFIED)

- **spec_ref**: `openspec/changes/add-openregister-discovered-capabilities/specs/graphql-api/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: graphql-api (existing), oas-generation (existing)`.
  - GIVEN the spec WHEN scanned THEN every requirement is under a
    `## MODIFIED Requirements` heading on the existing graphql-api
    spec.
  - GIVEN the unified-OAS requirement WHEN inspected THEN it
    declares D5's single-source-generation contract and the CI
    parity test.
  - GIVEN the parity requirement WHEN inspected THEN it covers
    field name + type + nullability + cardinality + RLS
    consistency between REST and GraphQL.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author nested-aggregations spec (NEW sibling)

- **spec_ref**: `openspec/changes/add-openregister-discovered-capabilities/specs/nested-aggregations/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: faceting-configuration (sibling), aggregations-backend-native (sibling)`.
  - GIVEN the spec WHEN scanned THEN the first requirement
    explicitly delineates the three responsibilities (faceting vs
    aggregation vs nested aggregation) per design.md D6.
  - GIVEN the nested-groupBy requirement WHEN inspected THEN it
    declares the depth limit (default 3, configurable per query)
    and the backend dispatch table (Postgres → Solr → ES → PHP
    fallback).
  - GIVEN the HAVING-clause requirement WHEN inspected THEN it
    reuses OR's operator vocabulary (`$gte`, `$lte`, `$eq`),
    NOT a SQL-style grammar.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.5: Author row-level-security-audit spec delta (MODIFIED)

- **spec_ref**: `openspec/changes/add-openregister-discovered-capabilities/specs/row-level-security-audit/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: row-field-level-security (extended), audit-trail-immutable (extended), audit-hash-chain (referenced)`.
  - GIVEN the spec WHEN scanned THEN it contains two distinct
    `## MODIFIED Requirements` blocks: one on
    `row-field-level-security`, one on `audit-trail-immutable`.
  - GIVEN the per-query granularity requirement WHEN inspected
    THEN it declares "ONE audit entry per query with
    `{queryDigest, ruleDigest, rowCount, schemaUuid, registerUuid}`",
    NOT "one per row" (per design.md D7).
  - GIVEN the integration contract WHEN inspected THEN every
    RLS-filtered read path (REST list, REST get, GraphQL list,
    GraphQL get, search, export) maps to the canonical audit
    granularity.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.6: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it includes the
    Per-capability overlap pass table mapping each Specter cluster
    to its disposition (MODIFIED-existing vs NEW sibling).
  - GIVEN `design.md` WHEN inspected THEN it includes a Reuse
    Analysis table per hydra `rules.design` and Seed Data per OR
    config.yaml.
  - GIVEN both files WHEN scanned THEN they reference ADR-022,
    ADR-031, ADR-032 by number with the specific rule each
    invokes.
- [x] Implement
- [ ] Test (peer review — architect confirms duplicate-avoidance
  pass is honest)

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Schema-converter dispatch table extensions — `lib/Service/Object/SchemaTypeConverter.php`

### Task 2.1: Register six new property types in the converter

- **spec_ref**: `specs/extended-field-types/spec.md` REQ-EFT-001..006
- **files**:
  - `lib/Service/Object/SchemaTypeConverter.php`
  - `lib/Handler/PropertyValidatorHandler.php` (extend `$validTypes`)
- **acceptance_criteria**: per-type read-coercion + validation per
  the spec; each registers as a single dispatch-table entry.
- [ ] Implement
- [ ] Test (unit per type + integration via REST API roundtrip)

### Task 2.2: GraphQL scalar registration for the six new types

- **spec_ref**: `specs/extended-field-types/spec.md` REQ-EFT-007
- **files**: `lib/Service/GraphQL/Scalar/` (six new scalar classes,
  thin wrappers per `graphql-api` pattern)
- **acceptance_criteria**: each new type round-trips through
  GraphQL with correct serialization + deserialization.
- [ ] Implement
- [ ] Test (GraphQL integration tests)

## 3. Unique constraint + upsert + cross-register $ref — `MagicMapper`

### Task 3.1: Emit UNIQUE INDEX clauses from schema metadata

- **spec_ref**: `specs/data-integrity-relations/spec.md` REQ-DIR-001..002
- **files**: `lib/Handler/MagicMapper.php`
  (`buildTableColumnsFromSchema()`)
- **acceptance_criteria**: schemas declaring `unique: true` on a
  property or `uniqueIndex: [...]` at schema level produce
  `UNIQUE INDEX` DDL on the corresponding magic table; existing
  schemas without these annotations get no extra DDL.
- [ ] Implement
- [ ] Test (migration roundtrip + duplicate-write rejection)

### Task 3.2: Upsert endpoint + upsertKey resolution

- **spec_ref**: `specs/data-integrity-relations/spec.md` REQ-DIR-003..005
- **files**: `lib/Controller/ObjectsController.php`,
  `lib/Service/ObjectService.php` (extend `saveObject()` to
  accept upsert semantics)
- **acceptance_criteria**: `POST /api/objects/{register}/{schema}?upsert=true`
  with body containing the upsertKey fields matches existing or
  creates new; merge / replace semantics per the spec.
- [ ] Implement
- [ ] Test (concurrent upsert race + merge semantics)

### Task 3.3: Cross-register $ref grammar extension

- **spec_ref**: `specs/data-integrity-relations/spec.md` REQ-DIR-006..008
- **files**: `lib/Service/Object/SchemaReferenceResolver.php`
  (extend `resolveSchemaReference()` to recognise the
  `register-slug/schema-slug` form)
- **acceptance_criteria**: existing `$ref` grammar continues to
  resolve; new slash-form resolves to a schema in the named
  register; existence + cascade semantics inherit from
  `referential-integrity`.
- [ ] Implement
- [ ] Test (cross-register existence + cascade + parity with
  intra-register behaviour)

## 4. Unified OAS 3.1 generator — `lib/Service/OpenApi/`

### Task 4.1: Fold GraphQL operations into the OAS document

- **spec_ref**: `specs/graphql-api/spec.md` REQ-GRA-NNN (unified
  OAS) + `oas-generation` (existing, unchanged)
- **files**: `lib/Service/OpenApi/OpenApiGenerator.php` (extend
  to walk the GraphQL SchemaGenerator output and emit
  `x-graphql-*` extensions)
- **acceptance_criteria**: a single `/api/oas` endpoint returns
  the merged OAS 3.1 document; REST operations under
  `paths`, GraphQL queries / mutations / subscriptions under
  `x-graphql-queries`, `x-graphql-mutations`, `x-graphql-subscriptions`.
- [ ] Implement
- [ ] Test (parity test: every schema's REST list endpoint
  shape == its GraphQL list resolver shape)

## 5. Nested aggregations — `AggregationRunner`

### Task 5.1: Parse nested groupBy + HAVING grammar

- **spec_ref**: `specs/nested-aggregations/spec.md` REQ-NAG-001..004
- **files**:
  - `lib/Service/Aggregation/AggregationRunner.php`
  - `lib/Service/Aggregation/QueryParser.php` (extend grammar)
- **acceptance_criteria**: aggregation requests with nested
  `groupBy: [...]` arrays and `having: {...}` clauses parse and
  dispatch to the right backend; depth limit enforced; result
  shape matches the spec.
- [ ] Implement
- [ ] Test (Postgres + PHP-fallback parity)

### Task 5.2: Backend implementations for nested aggregation

- **spec_ref**: `specs/nested-aggregations/spec.md` REQ-NAG-005..006
- **files**:
  - `lib/Service/Aggregation/PostgresBackend.php` (extend
    `aggregate()` for nested GROUP BY + HAVING)
  - `lib/Service/Aggregation/SolrBackend.php` (pivot facets)
  - `lib/Service/Aggregation/ElasticsearchBackend.php` (nested
    terms + `bucket_selector`)
- **acceptance_criteria**: per-backend test asserts identical
  output shape across all three backends and the PHP fallback for
  a 3-level nested aggregation with a HAVING filter.
- [ ] Implement
- [ ] Test (cross-backend parity)

## 6. RLS audit integration

### Task 6.1: Emit audit entry per RLS-filtered query

- **spec_ref**: `specs/row-level-security-audit/spec.md` REQ-RLS-001..003
- **files**:
  - `lib/Handler/MagicRbacHandler.php` (hook the audit emit)
  - `lib/Service/AuditTrailService.php` (accept the new entry
    shape with `ruleDigest` + `rowCount`)
- **acceptance_criteria**: a LIST endpoint returning N RLS-filtered
  rows produces ONE audit entry with the rule digest + row count,
  not N entries; the audit entry hash chains correctly per
  `audit-hash-chain`.
- [ ] Implement
- [ ] Test (audit volume + hash chain integrity under load)

### Task 6.2: Verify all read paths emit at the documented granularity

- **spec_ref**: `specs/row-level-security-audit/spec.md` REQ-RLS-004..005
- **files**: every read entry point — REST `ObjectsController`,
  `GraphQLController`, `SearchController`, `ExportService`
- **acceptance_criteria**: integration test asserts that an
  RLS-filtered list / get / search / export each emits the
  correct number of audit entries per the granularity contract.
- [ ] Implement
- [ ] Test (end-to-end audit-emission test across all read paths)

## 7. Demo schemas + seed data

### Task 7.1: Add four demo schemas to openregister_register.json

- **spec_ref**: `proposal.md` + `design.md` Seed Data section
- **files**: `lib/Settings/openregister_register.json`
- **acceptance_criteria**: `DemoEvent`, `DemoLocation`,
  `DemoProduct`, `DemoMember` schemas declared, each with 3-5
  seed objects demonstrating the new property types and cross-register
  `$ref`; idempotent via slug matching per OR's importFromApp pattern.
- [ ] Implement
- [ ] Test (repair-step roundtrip + idempotency)

## 8. Docs

### Task 8.1: Document the new shapes in `docs/annotations/`

- **spec_ref**: all five specs
- **files**:
  - `docs/annotations/extended-field-types.md` (new)
  - `docs/annotations/cross-register-refs.md` (new)
  - `docs/annotations/nested-aggregations.md` (new)
  - update `docs/annotations/x-openregister-aggregations.md` to
    reference nested grammar
  - update `docs/api/graphql.md` to reference unified OAS
- **acceptance_criteria**: each new shape has an annotation
  doc with a minimal working example pulled from the demo
  schemas in Task 7.1.
- [ ] Implement
- [ ] Test (manual review)
