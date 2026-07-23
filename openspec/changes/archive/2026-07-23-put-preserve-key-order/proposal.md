# put-preserve-key-order

## Why

**#1720** — the object write path (PUT/update) does not preserve the key
ordering of JSON object-typed properties. When a schema stores an ordered map
(an object whose keys are meaningful, e.g. a drag-reorderable list rendered as
`{ "step-1": …, "step-2": … }` or a manifest whose property order drives UI
row order), a round-trip through PUT re-serialises the object with keys in a
different (hash/alphabetical) order, so the drag-reorder the user just performed
is silently lost on save.

This is a correctness/UX defect: the user drags a row, saves, and the order
snaps back. It affects any object-keyed schema that relies on insertion order —
a pattern OpenBuild manifests and several leaf apps use.

## What Changes

- The object save/update path MUST preserve the insertion order of keys in
  JSON object-typed properties exactly as submitted, through validation,
  storage and read-back.
- JSON decode/encode on the write path MUST NOT reorder object keys (no
  associative-array reshuffling, no `ksort`, no key-canonicalisation that
  reorders).
- The stored representation and the serialized read response MUST return object
  keys in the submitted order.

**BREAKING:** none. This only makes preserved order match submitted order;
callers that did not depend on order are unaffected.

## Capabilities

### Modified Capabilities

- `objects-crud`: the update/PUT (and create) contract gains a requirement that
  key ordering of JSON object-typed properties is preserved end-to-end
  (submit → validate → store → read).

## Impact

**Affected code:** the object write path in `lib/Service/Object*` /
`ObjectEntityService` save/update (JSON decode/encode and property
normalisation), and the serializer used on read-back. The fix is to ensure
JSON is decoded and re-encoded order-preservingly and that no normalisation step
reorders object keys.

**Tests:** a round-trip test that PUTs an object with a known key order into an
object-keyed property, reads it back, and asserts the exact key sequence
survives; a drag-reorder simulation (reverse the keys, save, re-read, assert
reversed). Runnable in the `nextcloud:34` container.

**Dependencies:** none; no migration. Existing stored objects are unaffected;
their next write preserves whatever order is submitted.
