---
status: draft
---
# Capability: `object-lifecycle`

## Purpose

State the batched-resolution contract for the delete path: bulk deletes and legacy
`cascade: true` deletions resolve their targets with batched lookups and batched
writes instead of running the full per-object pipeline per id — while preserving
the per-object referential-integrity, event, audit and fallback semantics.

## ADDED Requirements

### Requirement: Bulk delete MUST batch-resolve object scopes with a single cross-table lookup

`ObjectService::deleteObjects()` SHALL resolve the entity (and thereby the
register/schema scope) of every permission-filtered UUID with ONE batched
cross-magic-table lookup (soft-deleted rows included) before deleting, and SHALL
pass each pre-resolved entity together with its concrete Register and Schema
entities into the delete handler so no per-object cross-table re-scan runs.

Identifiers the uuid-based batch lookup cannot resolve (numeric ids, slugs, URIs,
rows deleted concurrently) SHALL fall back to the legacy per-uuid resolution and
delete-handler call, preserving prior behaviour including per-pair cache
invalidation and skip-on-error semantics. Referential-integrity enforcement
(RESTRICT, CASCADE, SET_NULL, SET_DEFAULT) remains per object.

#### Scenario: Batch-resolved UUIDs skip the per-object lookup
- **GIVEN** a bulk delete of N objects whose UUIDs all resolve in the batched lookup
- **WHEN** `deleteObjects()` runs
- **THEN** exactly one cross-magic-table lookup is issued for all N UUIDs
- **AND** the delete handler receives each pre-resolved entity with concrete
  register/schema entities and performs no additional lookup for it
- **AND** each distinct (register, schema) pair is materialised as entities at most once

#### Scenario: Batch misses keep the legacy pipeline
- **GIVEN** a bulk delete where one identifier is a slug the uuid-based batch cannot match
- **WHEN** `deleteObjects()` runs
- **THEN** that identifier is resolved and deleted through the unchanged legacy
  per-uuid path
- **AND** a RESTRICT block on any object skips only that object and the bulk
  operation continues

### Requirement: Legacy cascade deletion MUST batch each level's targets

`DeleteObject::cascadeDeleteObjects()` SHALL collect all ids referenced by
`cascade: true` schema properties first, resolve them with ONE batched
cross-table lookup, and soft-delete the resolved targets with one
`UPDATE ... WHERE uuid IN (...)` statement per magic table (per-row deletion
metadata bound via a parameterised CASE expression) and ONE multi-row audit
INSERT — instead of feeding each id through the full per-object delete pipeline.

Per-object semantics SHALL be preserved: an object-updating event is dispatched
per target before the write (a hook stopping propagation skips that target; a
hook modifying the payload routes that target through the full-row save), an
object-updated event is dispatched per target after the write, caches are
invalidated per object, and cascade children remain sub-deletions that never
cascade further. Unresolved ids and total batch-write failures fall back to the
legacy per-id pipeline. Soft delete remains the only cascade disposition.

#### Scenario: Cascade children are soft-deleted with batched statements
- **GIVEN** a root object whose schema has a `cascade: true` array property
  referencing M children stored in one magic table
- **WHEN** the root object is deleted
- **THEN** the children are resolved with one batched lookup and soft-deleted with
  one UPDATE statement carrying per-child deletion metadata
- **AND** M per-object updating and updated events are dispatched
- **AND** M audit rows are persisted with one multi-row INSERT

#### Scenario: Hook rejection skips only the rejected child
- **GIVEN** a cascade where a pre-update hook stops propagation for one child
- **WHEN** the batched soft delete runs
- **THEN** that child is not soft-deleted and receives no updated event
- **AND** the remaining children are soft-deleted normally

#### Scenario: Batch failure falls back to the per-id pipeline
- **GIVEN** the batched soft-delete write fails entirely
- **WHEN** the cascade continues
- **THEN** every collected id is retried through the legacy per-id delete pipeline
