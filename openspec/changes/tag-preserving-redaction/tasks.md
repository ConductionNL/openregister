# Tasks: tag-preserving-redaction

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 12.
     Acceptance criteria are plain indented bullets, not checkboxes. -->

## 1. Structure inspection seam

- [ ] 1.1 Add `lib/Service/File/Pdf/PdfStructureInspector.php` — taggedness + StructElem count over the SAPP object model (REQ-ORTPR-001)
  - `inspect(\ddn\sapp\PDFDoc $doc)` (or `isTagged()` + `countStructElements()`): tagged when the Catalog references `/StructTreeRoot` and/or `/MarkInfo` `/Marked true`; count objects whose dict has `/Type /StructElem`; iterate the parsed object model, never a raw byte scan; reuse the already-parsed doc (no second load); no live-instance dependency.

- [ ] 1.2 Add `lib/Service/File/Pdf/StructurePreservation.php` value object (REQ-ORTPR-003)
  - `final readonly` with `requested` (bool), `preserved` (bool), `tagCountBefore` (int), `tagCountAfter` (int), `lossReasons` (string[]); `jsonSerialize()` emitting exactly those five keys; a class constant enumerating the loss-reason set (design.md D2).

## 2. Preserve-or-degrade redaction

- [ ] 2.1 Thread a tri-state `?bool $preserveStructure` into `PdfTextReplacer::replaceInPdf` + a `StructurePreservation &$structureResult` out-param (REQ-ORTPR-004)
  - Resolve null→auto (preserve iff `tagCountBefore > 0`), true→attempt, false→skip-but-measure; measure before via the inspector on the loaded `PDFDoc`.

- [ ] 2.2 Pass the structure-tree object graph through the SAPP rebuild + sanitiser and re-measure (REQ-ORTPR-002)
  - When preserving, ensure `/StructTreeRoot` `/MarkInfo` `/RoleMap` document `/Lang` survive `to_pdf_file_s(rebuild:true)` and the `PdfMetadataSanitizer` pass; measure `tagCountAfter` on the output.

- [ ] 2.3 Apply the conservative `preserved` attestation and populate `lossReasons` (REQ-ORTPR-002)
  - `preserved:true` only when after==before>0 AND StructTreeRoot survived AND no structured page's marked-content operator count changed; else `preserved:false` with a machine-readable reason (`marked-content-correspondence-broken` / `structtreeroot-dropped-on-rebuild` / `engine-cannot-reauthor-structtree`); always still produce the redacted bytes (best-effort parity, PII-free reasons).

## 3. Result contract on the public seams

- [ ] 3.1 Add `lastStructurePreservation` + `getLastStructurePreservation(): ?StructurePreservation` to `DocumentProcessingHandler` (REQ-ORTPR-003)
  - Reset alongside the existing `lastResidualEntities`/`lastSanitizationReport` at the top of `anonymizeDocument`; populated on the PDF branch only; null on DOCX/ODT/text branches.

- [ ] 3.2 Thread `preserveStructure` through `anonymizeDocument` / `replaceWords` → `replaceWordsInPdfDocument` → `replaceInPdf` (REQ-ORTPR-004)
  - New optional param default null (auto) so every existing caller is unaffected; forward it only on the PDF branch.

- [ ] 3.3 Read the `preserveStructure` HTTP param and add the `structurePreservation` block to `FileTextController::anonymizeFile` (REQ-ORTPR-003, REQ-ORTPR-004)
  - Read optional param inside the already-gated method (no new route, no auth change); include the serialised block for PDF inputs, omit it for non-PDF; block is PII-free (structural counts + reasons only, ADR-005).

## 4. Tests & quality

- [ ] 4.1 PHPUnit `PdfStructureInspectorTest` — tagged fixture (StructTreeRoot+MarkInfo+N StructElem → detected, count N) and untagged fixture (not tagged, count 0) (REQ-ORTPR-001)
  - Fixtures committed under `tests/unit/.../fixtures/`; no live NC instance; run in the nextcloud:34 container per the OR PHPUnit convention.

- [ ] 4.2 PHPUnit `PdfTextReplacerTest` — preservation matrix (REQ-ORTPR-002, REQ-ORTPR-004, REQ-ORTPR-005)
  - tagged+attested (`preserved:true`, empty reasons); tagged+loss (`preserved:false`+`marked-content-correspondence-broken`); struct-tree dropped (`structtreeroot-dropped-on-rebuild`); auto-preserves-tagged-by-default; explicit-false-skips-but-measures; untagged reports `input-not-tagged`.

- [ ] 4.3 PHPUnit byte-stability guard + `StructurePreservationTest` field-set + `DocumentProcessingHandlerTest` non-PDF null (REQ-ORTPR-003, REQ-ORTPR-005)
  - Untagged output identical to a golden pre-change fixture; `jsonSerialize()` emits exactly the five contracted keys; DOCX path returns null accessor + no HTTP block.

- [ ] 4.4 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan, PHPUnit) and `openspec validate tag-preserving-redaction --strict`
  - SPDX docblocks on new files; if dev-deps are unavailable in the container, `php -l` all new files and note static-analysis/PHPUnit deferred to CI (per the OR convention).
