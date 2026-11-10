---
status: draft
---
# Capability: `deletion-audit-trail`

## Purpose

Extend the single-INSERT cascade-context audit contract to referential-integrity
CASCADE enforcement: batch-handled cascade targets are audited with one
multi-row, hash-chain-sealed INSERT whose rows carry the canonical
cascade-context fold.

## ADDED Requirements

### Requirement: Batched integrity-cascade deletions MUST be audited with one multi-row INSERT

`ReferentialIntegrityService::applyDeletionActions()` SHALL persist the audit
rows of CASCADE targets soft-deleted through its batched path with ONE
multi-row INSERT (`AuditTrailMapper::insertAuditTrails()`), each row pre-built
through the shared `buildAuditTrail()` row builder with action
`referential_integrity.cascade_delete` and a cascade context folded into the
`changed` column in the exact shape `createAuditTrail()` uses:
`changed.triggeredBy` = `referential_integrity` and `changed.cascadeContext`
with keys `triggerObject` (root object UUID), `triggerSchema` (root schema
slug), `action_type` (`referential_integrity.cascade_delete`) and `property`
(the referencing property from the analysis target).

Audit persistence SHALL remain fail-soft: a failed row build or a failed
multi-row INSERT logs a warning and never aborts the cascade. Targets handled
by the per-object fallback pipeline keep their existing single-row audit
insert and legacy `changed` shape unchanged.

#### Scenario: One multi-row INSERT for N batch-handled cascade targets
- **GIVEN** N CASCADE targets soft-deleted through the batched integrity path
- **WHEN** the audit rows are written
- **THEN** exactly one multi-row INSERT persists N rows
- **AND** each row has action `referential_integrity.cascade_delete` and a
  `changed.cascadeContext` with `triggerObject`, `triggerSchema`,
  `action_type` and `property`

#### Scenario: Audit failure never aborts the cascade
- **GIVEN** the multi-row audit INSERT throws
- **WHEN** the batched integrity cascade continues
- **THEN** a warning is logged and the soft-deleted targets stay deleted
- **AND** no exception escapes `applyDeletionActions()`
