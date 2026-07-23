# objects-crud Specification (delta)

---
status: proposed
---

## Purpose delta

The object create/update contract preserves the insertion order of keys in
JSON object-typed properties end-to-end, so that order-significant maps (e.g.
drag-reorderable rows) survive a save round-trip.

## ADDED Requirements

### Requirement: Preserve JSON object key order on write and read (REQ-OBJ-KO-01)

The object create/update path MUST preserve the insertion order of keys within
any JSON object-typed property exactly as submitted, through validation,
storage, and read-back. The write path MUST NOT reorder,
alphabetise, or canonicalise object keys; default values for absent keys MUST be
appended after the submitted keys without reordering existing ones. The read
serializer MUST return object keys in stored order. Preserving key order MUST
NOT alter the PUT-semantic carry-forward of unchanged fields.

#### Scenario: Drag-reorder persists across save

- **GIVEN** an object with an object-keyed property `{ "a": 1, "b": 2, "c": 3 }`
- **WHEN** the client reorders it to `{ "c": 3, "b": 2, "a": 1 }` and PUTs the
  object
- **THEN** reading the object back returns the keys in the order
  `c`, `b`, `a`
- **AND** a sibling property that was not changed retains its value.

#### Scenario: No implicit reordering

- **WHEN** an object with an object-keyed property is saved unchanged
- **THEN** the stored and returned key order is identical to the submitted
  order, with no alphabetisation or canonicalisation applied.
