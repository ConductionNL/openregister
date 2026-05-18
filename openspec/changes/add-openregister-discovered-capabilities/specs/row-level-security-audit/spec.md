# Spec: row-level-security-audit — RLS↔Audit integration delta

**Status:** proposed
**Scope:** openregister
**Tier:** or-core-extensions
**Depends on:** row-field-level-security (extended), audit-trail-immutable (extended), audit-hash-chain (referenced)

## Motivation (context for the delta)

The existing `row-field-level-security` spec covers row-level
security + field-level security comprehensively: group + match
conditions, `$userId` / `$organisation` / `$now` dynamic variables,
consistent enforcement across REST / GraphQL / search / export /
MCP, fail-closed when variables don't resolve. Separately,
`audit-trail-immutable` + `audit-hash-chain` cover the immutable,
SHA-256 hash-chained audit log.

Specter's intelligence pipeline surfaced row-level security as a
top tender requirement (20 mentions + 6 explicit tender citations).
Cross-checking against the existing OR specs: **the row-level
security mechanism is implemented; what is missing is the
cross-cutting integration with audit.**

Concretely, three gaps:

1. There is no explicit contract that **every RLS-filtered read MUST
   be audited**. `audit-trail-immutable` says "every mutation" plus
   "reads of sensitive data" — but "sensitive data" is informally
   defined. An RLS rule fires precisely because the data is
   role-restricted; that is the canonical "sensitive read" trigger,
   and it should be wired explicitly.
2. There is no contract for **what the audit entry MUST record about
   the RLS rule that applied**. Without rule attribution, an
   auditor sees "alice read 47 melding objects" but cannot answer
   "which rule allowed her to see those 47, and which 53 were
   filtered out?".
3. There is no **audit granularity contract** for list queries.
   Without one, a list of 1000 RLS-filtered rows could emit either
   1 audit entry or 1000 — vastly different storage / verifiability
   profiles, neither documented.

This delta fixes all three. The RLS evaluation engine and the audit
hash-chain mechanism themselves are unchanged — this is a thin
integration spec that locks the contract between the two existing
abstractions.

## MODIFIED Requirements

(Two separate delta blocks below — one on `row-field-level-security`,
one on `audit-trail-immutable`. Both blocks ship as part of this
single spec for review coherence.)

---

## ## MODIFIED Requirements — extends `row-field-level-security`

### Requirement: Every RLS-filtered read SHALL emit an audit-trail entry recording the rule that applied

This requirement extends `row-field-level-security` Requirement
"RLS rules MUST apply consistently to all access methods". The
existing requirement enforces consistent RLS filtering across REST,
GraphQL, search, export, and MCP. This delta adds: **for every
read path that invokes `MagicRbacHandler::applyRbacFilters()` or
`PermissionHandler::hasPermission()` with a non-trivial RLS rule
(any rule beyond the trivial "public" or "no rule"), the audit
trail MUST record an entry naming the rule that was evaluated and
the outcome.** The audit entry shape extends the existing
`audit-trail-immutable` schema with three new fields per the
companion modified requirement below.

#### Scenario: List endpoint with RLS rule emits exactly one audit entry

- **GIVEN** schema `Melding` has the RLS rule
  `{ "read": [{ "group": "behandelaars", "match": { "afdeling": "$activeDepartment" } }] }`
- **AND** user `alice` (department `sociale-zaken`) is in group
  `behandelaars`
- **AND** 100 `Melding` rows exist; the RLS filter narrows to 47
- **WHEN** `alice` calls `GET /api/objects/{register}/melding`
- **THEN** EXACTLY ONE audit entry MUST be appended
- **AND** the entry's `action` MUST be `read-list`
- **AND** the entry's `ruleDigest` MUST be the SHA-256 of the
  applied rule's canonical JSON
- **AND** the entry's `rowCount` MUST be `47`
- **AND** the entry MUST NOT contain the row UUIDs (per scale
  guarantee — see REQ-RLS-005)

#### Scenario: Single-object GET with RLS rule emits one audit entry

- **GIVEN** the same RLS rule
- **WHEN** `alice` calls `GET /api/objects/{register}/melding/<uuid>`
- **AND** the object passes the RLS filter
- **THEN** ONE audit entry MUST be appended
- **AND** the entry's `action` MUST be `read` (existing convention)
- **AND** the entry's `ruleDigest` MUST identify the applied rule
- **AND** the entry's `objectUuid` MUST be the requested UUID (per
  existing audit shape)

#### Scenario: GET that is blocked by RLS emits an audit entry of the block

- **GIVEN** the same RLS rule
- **WHEN** `alice` calls `GET /api/objects/{register}/melding/<uuid>`
- **AND** the object does NOT pass the RLS filter
- **THEN** the HTTP response MUST be 404 (per existing
  `row-field-level-security` "fail-closed" pattern — no leak of
  existence)
- **AND** ONE audit entry MUST be appended
- **AND** the entry's `action` MUST be `read-denied`
- **AND** the entry's `ruleDigest` MUST identify the rule that
  blocked access
- **AND** the entry's `objectUuid` MUST be the requested UUID
- **AND** the entry MUST NOT carry any object data

#### Scenario: Read without an RLS rule does NOT emit an audit entry

- **GIVEN** schema `PublicAnnouncement` has no RLS rule (or only
  the trivial `public` rule)
- **WHEN** any user calls `GET /api/objects/{register}/public-announcement`
- **THEN** NO audit entry MUST be appended for the read (per
  existing `audit-trail-immutable` convention that non-sensitive
  reads are not audited)

### Requirement: RLS rule attribution SHALL be consistent across all read paths

This requirement extends `row-field-level-security` Requirement
"RLS rules MUST apply consistently to all access methods". For every
read path (REST list, REST get, GraphQL list, GraphQL get, search,
export, MCP), the audit entry's `ruleDigest` for the SAME rule
applied to the SAME schema MUST be byte-identical regardless of
the surface. This makes auditor verification mechanical: a single
rule digest matches every read of that schema's data across every
surface.

#### Scenario: ruleDigest is identical across REST, GraphQL, and search for the same rule

- **GIVEN** schema `Melding` with the RLS rule above
- **WHEN** `alice` reads via REST list / REST get / GraphQL list /
  GraphQL get / search / export
- **THEN** every emitted audit entry MUST carry the SAME
  `ruleDigest`
- **AND** the digest MUST be the SHA-256 of the rule's canonical
  JSON serialisation per the existing `audit-hash-chain` canonical
  JSON convention

#### Scenario: Different rules on different schemas produce different digests

- **GIVEN** schemas `Melding` and `Dossier` each have distinct RLS
  rules
- **WHEN** the same user reads from both
- **THEN** the two audit entries MUST carry distinct `ruleDigest`
  values
- **AND** the digest for each rule MUST be stable across multiple
  reads of the same schema by any user

---

## ## MODIFIED Requirements — extends `audit-trail-immutable`

### Requirement: AuditTrail entity SHALL carry ruleDigest, rowCount, and ruleVersion fields

This requirement extends `audit-trail-immutable` Requirement "The
AuditTrail entity MUST include hash and previousHash fields". The
audit entity MUST be extended with three additional fields:

- `ruleDigest` — string, 64-char hex SHA-256 of the RLS rule's
  canonical JSON. NULL for entries not produced by an RLS-filtered
  read path. INCLUDED in the hash-chain digest per
  `audit-hash-chain` REQ "Canonical JSON includes all entry fields
  except hash/previousHash".
- `rowCount` — integer ≥ 0, the count of rows returned (or
  inspected) by the operation. For single-object reads, `1`. For
  list reads, the post-RLS-filter count. NULL for mutations
  (existing entries continue to track `data` / `changed`).
- `ruleVersion` — string, optional, identifying the schema version
  the rule belonged to at audit time. If the rule mutates after
  the audit entry is written, the digest still verifies against
  the historical rule shape via this version reference.

All three fields MUST be appended to the canonical JSON used by
`audit-hash-chain`'s SHA-256 computation, EXCEPT they MUST appear
in the alphabetical key ordering required by the existing canonical
JSON convention — they MUST NOT break the hash chain for entries
written before this delta lands (legacy entries simply omit the
fields, which the canonical JSON treats as `null`).

#### Scenario: Hash chain integrity holds across pre- and post-delta entries

- **GIVEN** a hash chain with entries written before this delta
  (no ruleDigest / rowCount / ruleVersion) followed by entries
  written after
- **WHEN** the verification endpoint `GET /api/audit-trails/verify`
  is called
- **THEN** the chain MUST verify as valid end-to-end
- **AND** the canonical JSON for pre-delta entries MUST be computed
  treating the new fields as absent (consistent with how legacy
  `hash`/`previousHash` were handled in the original migration)

#### Scenario: New entry includes ruleDigest in hash computation

- **GIVEN** an RLS-filtered read by `alice`
- **WHEN** the audit entry is written
- **THEN** the entry's `hash` MUST be the SHA-256 of
  `previousHash + canonical_json(entry_data_including_ruleDigest_rowCount_ruleVersion)`
- **AND** tampering with `ruleDigest` after the fact MUST cause the
  chain to break at that entry (per existing tamper-detection
  guarantee)

#### Scenario: Mutation entry carries rowCount: 1, ruleDigest: null

- **GIVEN** an `update` mutation by `alice` (not RLS-mediated read)
- **WHEN** the audit entry is written
- **THEN** the entry MUST carry `rowCount: 1` and `ruleDigest: null`
  (no RLS rule mediated the action; the mutation went through
  schema-level RBAC instead)

### Requirement: List-read audit entries SHALL aggregate per-query, NOT per-row

This is a NEW requirement under `audit-trail-immutable` introduced
by this delta. A LIST endpoint that returns N RLS-filtered rows
MUST emit EXACTLY ONE audit entry, regardless of N. The entry's
`rowCount` field records N; the entry MUST NOT carry the UUIDs of
the N rows. This is the audit-granularity contract per design.md
D7 — without it, a busy list-heavy API explodes audit volume by
×N.

Per-object reads (GET on a single UUID) MUST continue to emit one
audit entry per object as today (existing behaviour).

#### Scenario: Listing 1000 RLS-filtered rows produces 1 audit entry

- **GIVEN** a `Melding` schema with RLS rule and 1000 rows pass the
  filter
- **WHEN** the list endpoint is called
- **THEN** EXACTLY ONE audit entry MUST be appended
- **AND** `rowCount` MUST be `1000`
- **AND** the entry MUST NOT contain the 1000 UUIDs
- **AND** the audit table MUST NOT grow by 1000 rows

#### Scenario: Paginated list — each page is one audit entry

- **GIVEN** a `Melding` list with 250 RLS-filtered rows paginated
  at 50 per page (5 pages)
- **WHEN** the client fetches all 5 pages sequentially
- **THEN** EXACTLY 5 audit entries MUST be appended (one per page
  request)
- **AND** each entry's `rowCount` MUST equal the rows on that page
  (50, 50, 50, 50, 50 — or 50/50/50/50/<remainder> if total ≠
  multiple of 50)

#### Scenario: Search returning 73 RLS-filtered hits emits 1 entry

- **GIVEN** a search query against an RLS-protected schema returning
  73 hits
- **WHEN** the search endpoint is called
- **THEN** ONE audit entry MUST be appended with `rowCount: 73`
- **AND** the entry's `action` MUST be `search` (extends existing
  audit action enum)

#### Scenario: Export honouring RLS emits 1 entry recording the export size

- **GIVEN** `ExportService` produces a CSV of 5000 RLS-filtered rows
- **WHEN** the export endpoint is called
- **THEN** ONE audit entry MUST be appended with
  `action: "export", rowCount: 5000`
- **AND** the entry MUST also record the export format
  (`metadata: {format: "csv"}`) so an auditor can reconstruct what
  left the system

---

## Cross-cutting integration scenarios (apply to both deltas)

The following scenarios verify the combined contract holds end-to-end
across every read path that consumes both `row-field-level-security`
and `audit-trail-immutable`.

#### Scenario: Read via GraphQL produces the same audit shape as REST

- **GIVEN** schema `Melding` with an RLS rule
- **AND** `alice` reads 12 RLS-filtered rows via REST list
- **AND** the same `alice` reads the same 12 rows via GraphQL
  `query { meldingen { … } }`
- **THEN** each read MUST emit ONE audit entry
- **AND** both entries MUST carry the SAME `ruleDigest`
- **AND** both entries MUST carry `rowCount: 12`
- **AND** the entries MUST differ ONLY in their `action` field
  (`read-list` vs `graphql-query`) and per-request metadata
  (timestamp, correlation id)

#### Scenario: Export with RLS records the digest for auditor verification

- **GIVEN** schema `Melding` with RLS rule R
- **WHEN** `alice` exports the filtered set
- **THEN** the audit entry MUST carry `ruleDigest = digest(R)`
- **WHEN** an auditor later inspects the audit log
- **AND** recomputes `digest(R)` from the schema as it stood at
  `ruleVersion`
- **THEN** the digests MUST match, proving the rule attribution is
  correct
