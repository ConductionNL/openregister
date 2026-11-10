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
- [x] `php -l` the annotated file (clean).

## 3. Gaps deferred to issues (do NOT implement here)

- [x] `extended-field-types#EFT-003` — `recurrence` type (file issue). **Filed:** Codeberg issue openregister#101 (pre-migration, not migrated to GitHub).
- [x] `extended-field-types#EFT-005` — real `color` type with per-format
      validation incl. `oklch` (file issue). **Filed:** Codeberg issue openregister#102 (pre-migration, not migrated to GitHub).
- [x] `geo-metadata-kaart#GEO-003` — PDOK map UI component (file issue). **Filed:** Codeberg issue openregister#103 (pre-migration, not migrated to GitHub).
- [x] `geo-metadata-kaart#GEO-010` — geo-fencing + event triggers (file issue). **Filed:** Codeberg issue openregister#104 (pre-migration, not migrated to GitHub).
