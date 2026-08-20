# ADR-008: Shared Format validators in `lib/Formats/` — one home per validation rule

**Status**: accepted (codifies the OR-side application of company ADR-011; see
the UUID gap addressed in `openspec/changes/extract-uuid-format-validator`)

**Date**: 2026-07-07

## Context

Company ADR-011 requires: before implementing any utility (validation,
formatting, parsing), search for an existing implementation and reuse it.
OpenRegister has a designated home for JSON-Schema format validators —
`lib/Formats/` — where `BsnFormat`, `Iso8601DateTimeFormat`, and `SemVerFormat`
each live as a single `Opis\JsonSchema\Format` implementation, and a
`DateTimeNormalizer` service centralises user-supplied datetime parsing with an
explicit "direct `new DateTime($value)` on user data is forbidden" contract
(`lib/Service/DateTimeNormalizer.php:11-13`).

In practice the discipline has eroded:

- The UUID-validation regex
  `'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'` is
  copy-pasted across 35+ call sites (controllers, services, save/render
  handlers, GraphQL scalar, facet handler) with subtly divergent variants
  (prefixed forms, 32-hex forms), and there is **no** `UuidFormat` in
  `lib/Formats/`.
- `ProcessingLogController::optionalDateParam()` calls
  `new DateTime($value)` directly on a request parameter
  (`lib/Controller/ProcessingLogController.php:359`), bypassing
  `DateTimeNormalizer`.
- Slug generation is reimplemented inline in `RegisterMapper::cleanObject()`
  instead of reusing `SchemaMapper::generateSlug()`.

Divergent copies mean a fix to one does not propagate, and validation drift
becomes a correctness/security hazard (e.g. `BsnFormat`'s elfproef accepting
all-zero input, addressed in the same change).

## Decision

**Every reusable validation/format/parse rule has exactly one implementation.
Format validators live in `lib/Formats/`; user-input datetime parsing goes
through `DateTimeNormalizer`; identifier and slug helpers are shared, not
inlined.**

### Numbered rules

#### Rule 1 — One validator per rule, in `lib/Formats/`

UUID, BSN, ISO-8601, SemVer, and any future format check is a single
`lib/Formats/` class. Call sites reference it (DI or static helper); they do not
carry their own regex copy. A duplicated regex is a defect to consolidate.

#### Rule 2 — No `new DateTime($userValue)` outside DateTimeNormalizer

Any conversion of a user-supplied datetime string to a `DateTime` MUST delegate
to `DateTimeNormalizer`. Direct construction on request input is forbidden, per
the class's own contract.

#### Rule 3 — Shared identifier/slug helpers

Slug generation, UUID formatting, and similar identifier utilities are shared
helpers reused across mappers and services, never re-inlined per class.

#### Rule 4 — Validators reject known-invalid sentinels

A format validator MUST reject structurally-valid-but-semantically-invalid
sentinels where they exist (e.g. the all-zero BSN, over-length numeric strings),
not merely pass a checksum on padded input.

## Consequences

- (+) A single place to fix a validation bug; no silent drift between copies.
- (+) Enforceable mechanically (a lint/grep gate can flag inline UUID regexes
  and raw `new DateTime` on request params).
- (−) Requires a one-time consolidation sweep of the existing 35+ UUID sites.
- Follow-up: `openspec/changes/extract-uuid-format-validator` performs the sweep,
  fixes the BSN sentinel gap, and routes the `ProcessingLogController` datetime
  through `DateTimeNormalizer`.
