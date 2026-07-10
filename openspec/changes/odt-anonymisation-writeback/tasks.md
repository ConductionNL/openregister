# Tasks — odt-anonymisation-writeback

> No seed-data task: this change adds no OpenRegister register/schema/object and no DB migration.
> Backend-only, OpenRegister-only. The DocuDesk upload-widget enablement is the separate `odt-anonymisation-frontend` change and is out of scope here.
> Reuses prior work: `text-extraction-word-completeness` (archived) already made `TextExtractionService::extractWord()` ODT-aware — do NOT duplicate it.
> **Strategy pivot (see design.md D1):** implementation proved PhpWord's ODText *reader* silently drops tables, headers and footers on load, so the object-model roundtrip (Strategy A) would destroy them. This change implements **Strategy B — in-place XML surgery on content.xml + styles.xml**, which never uses the lossy reader and preserves all structure.

## 1. Route ODT to a dedicated in-place XML path

- [x] 1.1 In `DocumentProcessingHandler::replaceWords()` (`lib/Service/File/DocumentProcessingHandler.php:234`), add an `odt` branch dispatching to the new `replaceWordsInOdtDocument()` BEFORE the `['doc','docx']` branch, so `.odt` never reaches `replaceWordsInTextDocument()` (raw-byte fallback) nor `replaceWordsInWordDocument()` (lossy ODText reader).

## 2. In-place ODF XML surgery

- [x] 2.1 Add `replaceWordsInOdtDocument()`: materialise the ODT to a temp file, open the ZIP, rewrite `content.xml` (body/tables/lists) and `styles.xml` (page headers/footers) in place, and write the result back — every other ZIP entry (mimetype, images, settings) untouched.
- [x] 2.2 Add `replaceTextInOdfXml()` (pure transform): parse the part, process each `text:p`/`text:h` paragraph independently, and rewrite its text nodes; return the input unchanged on parse failure.
- [x] 2.3 Handle entities split across `<text:span>` runs: concatenate a paragraph's text nodes with a byte→node ownership map, compute non-overlapping replacement ranges (longest needle first, case-insensitive to mirror `str_ireplace`), and rebuild each node so the placeholder is emitted once and covered bytes are dropped.

## 3. Validation gate (fail loud)

- [x] 3.1 Add `recordOdtResidualEntities()`: re-open the written ODT, re-extract the concatenated paragraph text of `content.xml` + `styles.xml` (`extractOdfConcatenatedText()`), and assert each entity's original text is absent.
- [x] 3.2 On any survivor — or an unreadable container / missing content.xml (redaction unprovable) — record residuals via the existing best-effort policy (`getLastResidualEntities()`, `[<TYPE>: <id>]` record shape shared with the PDF path); never report an unredacted or corrupt ODT as a clean success.

## 4. Optional cleanup

- [x] 4.1 (Optional, non-blocking) Rename `replaceWordsInWordDocument` → `replaceWordsInOfficeDocument`. SKIPPED — ODT now has its own method, so the Word method keeps its accurate name; skipping avoids widening the diff.

## 5. Tests (every new path — repo requires coverage; runs in-container)

- [x] 5.1 Generate an ODT fixture at runtime via PhpWord (no committed binaries) covering a paragraph, a table cell, a header, and a footer.
- [x] 5.2 Assert entity text is removed from all regions (paragraph, table, header, footer) AND the table/header/footer STRUCTURE is preserved in the output (proving XML surgery, not the lossy reader).
- [x] 5.3 Assert the output is a valid ODT container and is NOT byte-identical to the input (rules out the raw-text no-op / corruption).
- [x] 5.4 Assert `replaceTextInOdfXml()` handles an entity split across `<text:span>` runs, preserves surrounding markup/text, and leaves unparseable XML unchanged.
- [x] 5.5 Assert the validation gate fails loud: a surviving entity is recorded in `getLastResidualEntities()` with the `{text,type,id}` shape, an unreadable output reports all entities as residual, and a clean redaction reports none.

## 6. Docs / changelog

- [x] 6.1 Add a CHANGELOG "Behavior changes" entry: `.odt` inputs were silently leaking or corrupting; they now produce a redacted `.odt` (structure preserved) or a fail-loud residual report.

## Acceptance criteria

- Anonymising an `.odt` never routes through `replaceWordsInTextDocument()` or PhpWord's ODText reader; redaction operates on the ODF XML in place.
- The output re-opens as a valid ODT and carries no original entity text across paragraphs, tables, headers, and footers; tables/headers/footers are preserved, not dropped.
- DOCX/PDF/text branches are unchanged (no regression).
- The validation gate re-extracts from the written ODF parts and either passes clean or reports residuals — never returns a byte-identical, corrupt, or unverifiable file as a success.
- No new HTTP endpoint, DB migration, register/schema/object, or external dependency is introduced.

## Quality / test / i18n reminders

- `openspec validate "odt-anonymisation-writeback"` passes.
- Any changed PHP files retain the EUPL-1.2 SPDX `@license` + `@copyright` headers (Conduction convention).
- No PII in logs, error responses, or debug output on the ODT path (ADR-005); log only structural detail (file id, extension, residual count).
- No user-facing strings are added in this backend change, so no new i18n keys; the reworded upload copy lives in the paired `odt-anonymisation-frontend` DocuDesk change.
- Every new code path is covered by a unit test that runs in-container per the project test config.
