# Tasks — Bucket 3a investigation: geo / field-types / OAS

Annotations-only retrofit. No production behaviour is added; missing REQs are
deferred to GitHub issues (see proposal.md "Gaps for issue filing").

## 1. Investigation (done)

- [x] Read all 5 REQs in their specs.
- [x] grep `lib/` + `src/` for each implementing unit.
- [x] Classify each REQ (1 IMPLEMENTED, 1 PARTIAL, 3 MISSING).

## 2. Annotation (done)

- [x] `oas-validation#API-46` — add scenario-level `@spec` to
      `lib/Service/Oas/ProblemDetailsBuilder.php` (class docblock + file
      docblock) pointing at the API-46 / RFC 7807 scenario.
- [x] Add cross-reference @spec in `lib/Service/Oas/ProblemDetailsBuilder.php`
      pointing to the retrofit spec at
      `openspec/changes/retrofit-2026-05-24-b3a-geo-fieldtypes-oas/specs/oas-validation/spec.md`.
- [x] `php -l` the annotated file (clean).

## 3. Gaps deferred to issues (do NOT implement here)

Issue bodies written to `issues-to-file.md` in this change directory.
Requires Codeberg credentials to file — see that file for the full text.

- [x] `extended-field-types#EFT-003` — `recurrence` type (issue body in issues-to-file.md).
- [x] `extended-field-types#EFT-005` — real `color` type with per-format
      validation incl. `oklch` (issue body in issues-to-file.md).
- [x] `geo-metadata-kaart#GEO-003` — PDOK map UI component (issue body in issues-to-file.md).
- [x] `geo-metadata-kaart#GEO-010` — geo-fencing + event triggers (issue body in issues-to-file.md).
