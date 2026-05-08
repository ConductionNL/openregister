---
status: in-progress
---

# Datetime Input Handling

## Purpose
Defines how OpenRegister converts user-supplied datetime input at every stage of the object lifecycle — write, read, bulk, and search — so that empty, null, and whitespace-only values consistently normalize to `null` rather than being silently interpreted as the current date-time. Establishes a single canonical normalization helper that all code paths delegate to, eliminating the class of bug where PHP's `new DateTime('')` / `new DateTime(null)` silently produces "now" for user-cleared fields.

**OpenSpec changes**
- `fix-empty-string-date-conversion` (active) — introduces the `DateTimeNormalizer` helper, migrates identified call sites (read, bulk, search, metadata) to delegate to it, and pins the contract with unit + integration tests.

## Requirements

_Requirements for this capability are introduced by the active change above and will be merged here on archive._
