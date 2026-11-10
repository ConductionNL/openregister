# Design: put-preserve-key-order

## Context

OpenRegister stores object data as JSON in the magic table. On write, the
payload is JSON-decoded to a PHP array, validated, normalised and re-encoded.
PHP associative arrays preserve insertion order, so the loss reported in #1720
comes from a normalisation/merge step that rebuilds an object property's array
without preserving submitted key order (e.g. iterating schema property
definitions rather than submitted keys, or a `+`/`array_merge` that reorders,
or a `json_decode(..., true)` followed by a canonicalising re-key).

## Goals / Non-goals

**Goal:** submitted key order of JSON object-typed properties survives
verbatim through validate → store → read-back.

**Non-goals:** ordering of *array* items (already positional); sorting or
canonicalising keys for any reason; changing the storage column type.

## Decisions

### D1 — Locate and neutralise the reordering step

Trace the write path for object-typed properties and identify where an object
property's keys are rebuilt. The fix keeps the submitted associative array's
order: iterate submitted keys (not schema-declared property order) when merging
defaults, and avoid any `ksort`/canonicalisation on object-typed values.
Defaults for absent keys are appended after submitted keys, never interleaved
in a way that reorders existing ones.

### D2 — Order-preserving encode on read

The serializer MUST re-encode with keys in stored order. PHP `json_encode` of an
associative array already preserves order; the requirement is simply that no
read-side normalisation re-keys object properties.

### D3 — PUT-semantic interaction

OpenRegister `saveObject` is PUT-semantic (carries all fields forward). This
change does not alter which keys are present — only their order. The
round-trip test also asserts a non-changed sibling field survives (guards
against a regression in the PUT-semantic carry-forward while touching the write
path).

## Risks / Trade-offs

- **Hidden second reorder site** — mitigated by testing the full HTTP
  round-trip (not just the unit under test), so any downstream re-key is caught.
- **Performance** — negligible; order preservation is the natural PHP array
  behaviour, the fix removes work rather than adding it.

## Migration / Rollout

No migration. Stored objects keep their current order until next write, which
then preserves submitted order.
