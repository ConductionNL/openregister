## Why

`DocumentProcessingHandler::anonymizeDocument` dispatches by file extension. PDFs fall through to `replaceWordsInTextDocument`, which calls `str_ireplace` on the raw binary `$fileNode->getContent()`. PDFs are not text — they're binary ZIP-like containers with content streams that are compressed (FlateDecode in >95% of cases), font-encoded (WinAnsi, MacRoman, Identity-H with ToUnicode CMaps), and split across multiple Tj/TJ operators (kerning arrays). `str_ireplace` produces an unopenable file. The capability is broken today: operators currently cannot reliably anonymise PDF inputs through the platform. DocuDesk's `anonymise-output-as-pdf-by-default` wraps the result in a PDF-conversion cascade that may "succeed" silently while still producing a broken file.

The discovery doc (`pdf-anonymisation-discovery`) considered eight approaches against the team's five hard constraints (no PII leak, identifiable placeholders, layout preservation, FOSS-only, no sidecars) and ruled out seven of them. Approach H — SAPP byte-level replacement with Helvetica fallback — is the only one that satisfies all constraints. This change implements that approach.

Two cross-change ties to be transparent about:

1. **SAPP fork dependency.** SAPP today supports only `/FlateDecode`. To handle the filter / encoding variety in real-world Woo PDFs we need to extend SAPP with: four additional stream-filter decoders (LZW, ASCII85, ASCIIHex, RunLength), a ToUnicode CMap parser + font-encoding resolver, a TJ-kerning-array flattening pre-pass, and a flagship `replaceTextInDocument()` API with font-switch + base-font-fallback support. We're upstreaming all of this as a series of small PRs to `dealfonso/sapp` (LGPL-3.0-or-later). During development OpenRegister consumes from `Conduction/sapp` `work/text-replacement` via a VCS composer repository; when upstream merges, we point back at upstream tags. See `upstream-issues/` for the planned PR series.

2. **Path B (NC Office ODT round-trip) deferred.** The discovery doc recommended a two-tier architecture with a Collabora-backed fallback. This change ships Path A only. Path B becomes a follow-up change (`pdf-anonymisation-odt-fallback`) once Path A is in operators' hands and we have measured fall-through-rate data from real Woo PDFs. PoC-first; commitment to Path B's NC Office dependency stays optional.

## What Changes

- **NEW capability:** `pdf-anonymisation`. Covers the byte-replace path: filter decoding, encoding resolution, text replacement with font switch, metadata sanitisation, validation gate. Does NOT cover the broader `DocumentProcessingHandler` surface (DOCX/ODT branches, which have their own sister changes).
- **NEW dependency** on a fork: `ddn/sapp:dev-work/text-replacement` from `Conduction/sapp`. Until SAPP upstream merges the work, composer pins to the fork branch via a VCS repository. Tracked under `tasks.md` §10.
- **NEW:** Internal classes in `lib/Service/File/Pdf/`:
  - `PdfTextReplacer` — wraps SAPP's `replaceTextInDocument()` API + applies our anonymisation conventions (placeholder format, adjacent-duplicate collapse, validation gate).
  - `PdfMetadataSanitizer` — strips `/Info` + `/XMP` fields in parity with the sister `office-document-sanitization` change's rules.
- **MODIFIED:** `DocumentProcessingHandler::anonymizeDocument` dispatch — PDF inputs route to `PdfTextReplacer` instead of the binary `str_ireplace` path. Word/ODT branches unchanged.
- **NEW post-validation gate.** After replacement, re-extract the output PDF via `smalot/pdfparser` and assert no entity text from the substitution map remains. Failure: discard the output, return a structured 500 with a diagnostic surface (which entities failed, which fonts encountered). Path B fall-through is reserved for the follow-up change; v1 fails-closed.
- **NEW:** Substitution-map convention preserved from existing detector flow — `[<TYPE>: <id>]` placeholder format unchanged; adjacent-duplicate placeholder collapse runs as a post-pass to handle variants-driven splits (`[P:7] [P:7]` → `[P:7]`).
- **NO new endpoints.** Anonymisation is invoked through the existing `POST /api/files/{fileId}/anonymize` path. PDFs that today produce a corrupt file now produce a clean anonymised PDF instead.
- **NO breaking change** for the existing surface. DOCX / ODT flows are unaffected. The `anonymizeDocument` contract (return shape, side effects) is preserved for callers — the change is behavioural for PDF inputs only.

### Hard constraints (locked from discovery)

1. **No original PII in the output.** The anonymised PDF MUST NOT contain the entity text in any layer (visible, hidden text layer, metadata, content stream). Visual-overlay-only approaches (paint a black rectangle over the text) are ruled out — the underlying text survives.
2. **Identifiable placeholders.** Each replaced entity MUST produce a placeholder of the form `[<TYPE>: <id>]` that can be cross-referenced with the grondslagen report. Pure black-bar redaction is ruled out.
3. **Layout preservation.** Tables must remain structurally recognisable. Overflow / gaps within cells are acceptable; paragraph wrapping changes are acceptable; loss of table structure is not.
4. **FOSS only.** No commercial dependencies. Rules out Setasign's SetaPDF-Redactor (the only PHP-native commercial alternative).
5. **No sidecars.** No separate long-running services to deploy alongside Nextcloud. PHP-side processing only, plus existing in-process Nextcloud integrations.

## Impact

- **Affected specs:** new capability `pdf-anonymisation`. No spec deltas.
- **Affected code:**
  - `lib/Service/File/DocumentProcessingHandler.php` — PDF branch in `anonymizeDocument` dispatch.
  - `lib/Service/File/Pdf/PdfTextReplacer.php` (new).
  - `lib/Service/File/Pdf/PdfMetadataSanitizer.php` (new).
  - `lib/Exception/PdfAnonymisationException.php` (new).
  - `composer.json` — add VCS repository entry for `Conduction/sapp` + `ddn/sapp:dev-work/text-replacement as 1.x-dev`.
- **Cross-app coordination:**
  - DocuDesk's `anonymise-output-as-pdf-by-default` cascade benefits automatically — its PDF-output path no longer wraps corrupt PDFs.
  - The `ocr-document-scanning` change handles image-only PDFs (no text layer); this change explicitly probes for a text layer up front and defers to OCR when absent.
  - Sister changes `office-document-sanitization` + `text-extraction-office-completeness` cover the DOCX/ODT routes and are independent of this work.
- **Operational dependency:** `Conduction/sapp` fork must be reachable from CI for composer install. Until upstream merges, the fork branch is the source of truth.
