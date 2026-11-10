---
status: draft
---
# Capability: `deletion-audit-trail`

## Purpose

Tighten the write discipline of deletion audit rows: cascade trigger context is
part of the initial INSERT (never a post-insert mutation, which broke the
ADR-003 hash-chain seal), and bulk deletion paths may persist their per-object
rows with a single multi-row INSERT that remains hash-chained.

## ADDED Requirements

### Requirement: Cascade trigger context MUST be written in the initial audit INSERT

Audit entries for referential-integrity-triggered deletions SHALL carry their
trigger context (`changed.triggeredBy`, `changed.cascadeContext` with
triggerObject, triggerSchema, action_type and property) in the row as initially
inserted. A post-insert UPDATE to attach context SHALL NOT be used: the ADR-003
hash-chain seal is computed over the inserted row, and any later mutation of
`changed` (or the size recomputation it entailed) breaks chain verification for
that row.

#### Scenario: Cascade-tagged delete writes exactly one audit statement
- **GIVEN** a delete carrying referential-integrity cascade context
- **WHEN** its audit entry is created
- **THEN** one INSERT persists the row including `changed.triggeredBy:
  referential_integrity` and the full `cascadeContext` key set
- **AND** no subsequent UPDATE mutates the row
- **AND** hash-chain verification over the row succeeds

### Requirement: Bulk deletion paths MAY persist per-object audit rows with one multi-row INSERT

Bulk deletion paths (batched legacy cascade, bulk operations) SHALL still produce
one audit entry per deleted object, but MAY persist those entries with a single
multi-row INSERT. Rows persisted this way MUST have the same column shape as
individually-inserted rows and MUST be sealed into the ADR-003 hash chain in
ascending id order so every row's `previousHash` references an already-sealed
predecessor. Sealing remains fail-soft: a hashing failure leaves the row
persisted but unhashed.

#### Scenario: Batched cascade audit rows are hash-chained
- **GIVEN** a legacy cascade that soft-deletes M children in one batch
- **WHEN** their audit entries are persisted
- **THEN** M rows land via one INSERT statement, each with action `delete` and the
  full pre-removal object snapshot semantics of the per-object path
- **AND** the rows are sealed in ascending id order and verify against the chain
