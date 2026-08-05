---
kind: code
---

## Why

Dropping a spreadsheet of records onto a page is the most common way non-technical
users put data into a register, and OpenRegister has no way to do it. The bulk
endpoint exists but takes a JSON array — it is an integration surface, not
something you hand to a policy officer with a CSV of 4,000 addresses.

The write primitive for this already exists and is currently unreachable.
`SaveObject::saveObjectsStreaming()` consumes rows lazily, routes each through
the standard `saveObject()` path so the request-scoped reference cache engages,
and records per-row outcomes on a `BatchOperationStatus` instead of failing the
batch. Its matched pair `clearReferenceValidationCache()` exists so a long import
does not carry stale verdicts across batches.

Both were built as the prerequisite for a streaming import, and the import was
never built. `routes.php` still carries the epitaph: *"The objects import route
was also removed — use the registers import endpoint instead."* Hydra's
`orphaned-write-capability` gate found them with **only test callers** — fully
implemented, unit-tested by calling the class directly, reachable from nothing.
This change is one half of resolving that; the other half (an opt-in `stream`
flag on the existing bulk endpoint) is already done and is what a UI would build
on.

### Why this is not the file widget

The obvious-sounding framing — "streaming upload for large and multiple files" —
is a different feature, and conflating them would produce the wrong design.
`saveObjectsStreaming()` streams **records**, not bytes: its rows are the shape
`saveObject()` accepts, and file content never passes through it. Binary upload
is `FileService` and Nextcloud storage.

The two compose rather than overlap. Dropping 200 PDFs is a file-upload
concern; the 200 resulting **objects** are what this streams. A spreadsheet drop
is purely this. Keeping them separate means the file widget does not grow a
record-parsing responsibility it has no business owning.

## What Changes

- **ADD** a record-import surface that accepts a tabular file (CSV, TSV,
  XLSX, JSON-lines) against a chosen register + schema, and writes through
  `saveObjectsStreaming()` so memory stays bounded and per-row failures are
  isolated rather than fatal.

- **ADD** a column-mapping step. A dropped file's headers will not match schema
  property names, and silently discarding unmatched columns is the failure mode
  that makes imports untrustworthy. The mapping is proposed automatically and
  confirmed by the user before anything is written.

- **ADD** a dry-run pass that reports what WOULD happen — created, updated,
  unchanged, failed, with per-row reasons — without writing. An import that can
  only be understood after it has run is not reversible in practice.

- **ADD** a result view built from `BatchOperationStatus`, which already carries
  created/updated/unchanged/failed plus reference-cache hit/miss counters. The
  failed rows must be downloadable as a file with the same shape as the input,
  so a user can fix and re-drop only what failed.

- **EXTEND** the widget with a drop target for record files. It delegates to the
  import surface; it does not parse or write anything itself.

## Impact

Sequenced so nothing depends on an unbuilt piece:

1. Parsing + column mapping, with the dry-run pass. Useful alone, and the part
   most likely to need iteration with real files.
2. The write path through `saveObjectsStreaming()`, reusing the `stream` flag
   already wired into `BulkController`.
3. The result view and failed-row export.
4. The widget drop target last, once the surface it delegates to is proven.

Idempotency is the open question and belongs in `design.md` rather than here: a
re-dropped file must update rather than duplicate, which means the mapping has to
nominate an identifying column, and a file with no stable identifier can only
ever create. That is a modelling decision with a real trap in it, and deciding it
by default would be the wrong call.

`saveObjects()` is NOT replaced. It remains the faster path for flat payloads;
streaming wins on large or heavily cross-referenced ones, and the caller chooses.
