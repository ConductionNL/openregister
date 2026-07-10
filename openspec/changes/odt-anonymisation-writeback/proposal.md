---
kind: code
---

## Why

OpenRegister can already EXTRACT text from `.odt` (OpenDocument Text) files — the archived `text-extraction-word-completeness` change added ODT to `TextExtractionService::extractWord()` / `isWordDocument()`. But OpenRegister CANNOT redact `.odt` safely. When an operator anonymises an `.odt`, `DocumentProcessingHandler::replaceWords()` (`lib/Service/File/DocumentProcessingHandler.php:234`) dispatches by extension: `['doc','docx']` route to the PhpWord object-model roundtrip, `pdf` routes to the PDF redactor, and **everything else — including `.odt` — falls through to `replaceWordsInTextDocument()`**, which runs `str_ireplace` over the raw file bytes.

An `.odt` is a ZIP container whose text lives (normally deflated) inside `content.xml`. `str_ireplace` over the transport bytes therefore has two failure modes, both bad: (1) for the normal compressed case the entity strings are not present in the byte stream, so nothing is replaced and the "anonymised" `.odt` is **byte-identical to the original** — a silent PII leak that reports success; (2) for stored (uncompressed) entries a replacement changes byte length and produces a **corrupt ZIP**. In `outputFormat: "preserve"` mode the leaky/corrupt file is the delivered output; in PDF-output modes the byte-identical case renders a PDF from the un-redacted ODT. This is the same class of defect the `pdf-anonymisation` and `anonymise-eml-structured` changes fixed for their formats.

This change closes the writeback side. The read side is already done (`text-extraction-word-completeness`, archived) and MUST NOT be duplicated — it is reused as prior work. This is a **backend-only, OpenRegister-only** change; the DocuDesk upload-widget enablement is a separate paired change (`odt-anonymisation-frontend`) and is explicitly out of scope here.

## What Changes

- **Route `.odt` into the Word branch.** `replaceWords()` dispatch (`DocumentProcessingHandler.php:234`) gains `odt` alongside `['doc','docx']`, so ODT inputs go to the shared PhpWord object-model path (`replaceWordsInWordDocument()`, `:1109`) instead of the raw-byte `str_ireplace` fallback.
- **Reuse the existing object-model traversal verbatim (Strategy A).** `replaceWordsInWordDocument()` already loads via `IOFactory::load()` (auto-detects and already reads ODT) and walks the model generically — `getText()`/`setText()`, tables (`getRows()`→`getCells()`), lists (`getItems()`), headers/footers, nested elements. This traversal is format-agnostic and is shared unchanged between DOCX and ODT. No new dependency: `phpoffice/phpword ^1.2` is already vendored and ships both the `ODText` reader and the `ODText` writer.
- **Select the writer by input extension.** The hardcoded `IOFactory::createWriter($phpWord, 'Word2007')->save()` (`:1239`) becomes writer selection: `odt → ODText`, else `Word2007`.
- **Gate the two Word2007-writer-specific workarounds off the ODT path.** The `Style\Numbering` `TypeError` fix (`:1226–1235`) and the soft-line-break `<w:br/>` fix (~`:1300+`) are Word2007-writer specific and MUST run only when the Word2007 writer is selected, never for the ODText writer.
- **Add a validation gate (fail loud).** After writeback, re-extract the output text via the existing `TextExtractionService::extractWord()` (ODT-aware) and assert each entity's original text is ABSENT from the output. On failure, apply the existing best-effort residuals policy (`getLastResidualEntities()` / `getLastPlaceholderMap()` already exist) — never silently return a byte-identical or corrupt file.
- **Interim guard included in this change.** This single change both ships real ODT writeback AND closes the silent-leak hole. There is no path where an unsupported/failed ODT anonymisation returns a byte-identical or corrupt file reported as success.
- **Optional cosmetic rename** of `replaceWordsInWordDocument` → `replaceWordsInOfficeDocument` (it now handles ODT too). Noted, not forced.
- **NO new HTTP endpoint, NO DB/schema change, NO new OpenRegister register/schema/object** (so NO seed data), **NO new external dependency**. Anonymisation is invoked through the existing service path; the change is behavioural for `.odt` inputs only. DOCX / PDF / text branches are byte-for-byte unchanged.

## Capabilities

### New Capabilities

- `odt-anonymisation`: ODT-aware anonymisation writeback in `DocumentProcessingHandler` — routing `.odt` into the shared PhpWord object-model path, writer selection by input extension (`ODText` for `.odt`), gating the Word2007-only roundtrip workarounds off the ODT path, and the post-write validation gate that fails loud (best-effort residuals) so an ODT anonymisation never emits a byte-identical or corrupt file.

### Modified Capabilities

(none — the broader `DocumentProcessingHandler` / `replaceWords` dispatch surface is currently covered only by the per-format capabilities `pdf-anonymisation` and `eml-anonymisation`; neither defines the ODT dispatch behaviour. The ODT branch is new and additive, so it is captured as a new capability rather than a delta. The non-ODT dispatch branches are unchanged.)

## Impact

- **Affected specs:** new capability `odt-anonymisation`. No spec deltas.
- **Affected code:**
  - `lib/Service/File/DocumentProcessingHandler.php` — add `odt` to the `replaceWords()` dispatch (`:234`); in `replaceWordsInWordDocument()` (`:1109`) select the writer by input extension and gate the Word2007-only workarounds (`:1226–1235`, ~`:1300+`) behind the Word2007 writer; add the post-write validation gate wired to the existing residuals accessors.
  - Reuses `lib/Service/TextExtractionService.php::extractWord()` (ODT-aware, from `text-extraction-word-completeness`) for the validation gate — no change to that method.
- **API contract:** No HTTP surface change. Behavioural change for `.odt` inputs to the existing anonymisation service path only.
- **Prior work (reused, not duplicated):** `text-extraction-word-completeness` (archived/done) closed the ODT READ/detection side.
- **Cross-app coordination:** DocuDesk's `outputFormat: "preserve"` and PDF-output cascades benefit automatically — ODT inputs no longer leak or corrupt. The paired DocuDesk frontend change `odt-anonymisation-frontend` (upload-widget allowlist + copy + i18n) is separate and soft-dependent; the backend fix (this change) should land first. opencatalogi / softwarecatalog are unaffected.
- **Privacy / compliance:** Closes the silent ODT PII leak — the central reason for this change.
- **Tests:** Unit tests over PhpWord-generated ODT fixtures at runtime (no committed binaries) covering paragraph, table, and header/footer; assert entity text removed AND the output re-opens via the `ODText` reader; plus a test that the validation gate fails loud on a residual.
- **Migration:** None. No DB change. ODT inputs that today produce a leaky/corrupt output now produce a redacted `.odt`; documented under CHANGELOG "Behavior changes".
