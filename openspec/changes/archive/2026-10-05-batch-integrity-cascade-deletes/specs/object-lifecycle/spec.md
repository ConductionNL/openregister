---
status: draft
---
# Capability: `object-lifecycle`

## Purpose

Extend the batched delete-path contract to referential-integrity CASCADE
enforcement: the deletion actions applied from a `DeletionAnalysis` resolve and
soft-delete their CASCADE targets with batched statements instead of running
the per-object pipeline per target — while preserving RESTRICT / SET_NULL /
SET_DEFAULT semantics, execution order and the per-object fallback.

## ADDED Requirements

### Requirement: Referential-integrity CASCADE deletions MUST be batched

`ReferentialIntegrityService::applyDeletionActions()` SHALL apply the CASCADE
targets of a pre-computed `DeletionAnalysis` with batched statements: ONE
cross-magic-table lookup resolving all target UUIDs, one
`UPDATE ... SET _deleted = CASE _uuid ... END WHERE _uuid IN (...)` per magic
table (per-target `deletedBy`/`deletedAt`/`objectId`/`organisation` attribution
metadata bound per row), and ONE multi-row audit INSERT — instead of a
cross-table scan plus a single-row audit INSERT per target.

Per-target semantics SHALL be preserved: an object-updating event is dispatched
per target before the write (a hook stopping propagation skips that target; a
payload-modifying hook routes that target through the full-row save), an
object-updated event is dispatched per target after the write, and one audit
row is written per analysis target (a target referenced through two properties
yields two rows). Targets the uuid-based batch lookup cannot resolve — and all
targets when the batched resolve or write fails — SHALL fall back to the
legacy per-object pipeline unchanged. RESTRICT, SET_NULL and SET_DEFAULT
handling and the SET_NULL → SET_DEFAULT → CASCADE (deepest first) execution
order are unchanged.

#### Scenario: CASCADE targets are soft-deleted with batched statements
- **GIVEN** a deletion analysis with N CASCADE targets stored in one magic table
- **WHEN** `applyDeletionActions()` runs
- **THEN** the targets are resolved with one batched cross-table lookup
- **AND** soft-deleted with one UPDATE statement carrying per-target attribution
  metadata including the acting user and active organisation
- **AND** N per-object updating and updated events are dispatched
- **AND** N audit rows are persisted with one multi-row INSERT

#### Scenario: Batch misses keep the per-object pipeline
- **GIVEN** a deletion analysis where one CASCADE target UUID is not found by the
  batched lookup
- **WHEN** `applyDeletionActions()` runs
- **THEN** that target is deleted and audited through the unchanged per-object
  pipeline
- **AND** the resolved targets are still handled by the batched statements

#### Scenario: Batch failure falls back to the per-object pipeline
- **GIVEN** the batched lookup or the batched soft-delete write fails
- **WHEN** `applyDeletionActions()` continues
- **THEN** every CASCADE target is retried through the legacy per-object
  pipeline and no exception escapes

#### Scenario: Non-CASCADE actions are untouched
- **GIVEN** a deletion analysis with SET_NULL and SET_DEFAULT targets
- **WHEN** `applyDeletionActions()` runs
- **THEN** those targets are processed per object exactly as before, before any
  CASCADE deletion
