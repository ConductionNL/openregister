## Why

OpenRegister's anonymisation pipeline for Word documents has a structural gap: the current `DocumentProcessingHandler::replaceWords` walker only mutates text runs it can reach via PhpWord's high-level API. It does not address the non-text structures inside `.docx` that carry author identity and stale content — comments, tracked changes, revision history, document metadata (Author, Last-Modified-By, Title, Subject), person-identifying field codes (`AUTHOR`, `USERNAME`, `LASTSAVEDBY`), custom XML data bindings, and hyperlinks. These structures regularly carry PII that bypasses entity detection AND survives the anonymisation pass. Operators publishing anonymised Woo/dossier documents have been leaking names through reviewer comments and tracked-change author attributes without realising.

OpenDocument Text (`.odt`) is worse off: the file falls through `TextExtractionService::extractFile` to "unsupported" (no detection at all), and the anonymisation path dispatches to `replaceWordsInTextDocument` which does raw string replacement on the ZIP container — corrupting any `.odt` it touches.

This change introduces a **document sanitiser** that runs ahead of the anonymisation walker. It produces a derivative file (originals are preserved) by performing XML-level surgery on the `.docx` / `.odt` ZIP container to:

- strip comments, tracked-change markup (accepting all inserts, dropping deletions), revision history, custom XML parts;
- replace document metadata with a sentinel value;
- strip person-identifying field codes (wrapper + cached result);
- flatten hyperlinks to plain text (drop URL + relationship, keep visible text).

After sanitisation, the cleaned derivative is passed to the existing walker for entity detection and anonymisation. ODT is added as a supported format alongside DOCX.

The sister change **`text-extraction-office-completeness`** (separate proposal) covers the deeper walker traversal (tables, lists, headers, footers, footnotes, endnotes) and ODT extraction/anonymisation paths. The two changes can land independently — this one fixes structural leakage; the next expands content coverage.

## What Changes

- **NEW:** Service `lib/Service/File/OfficeDocumentSanitizer.php` orchestrates format-aware sanitisation. Format detection by MIME (`application/vnd.openxmlformats-officedocument.wordprocessingml.document` for DOCX; `application/vnd.oasis.opendocument.text` for ODT) routes to a per-format sanitiser strategy.
- **NEW:** `lib/Service/File/Sanitizer/DocxSanitizer.php` performs XML-level surgery on a copy of the input `.docx`:
  - Removes the entire `word/comments.xml` part, `word/commentsExtended.xml`, `word/commentsIds.xml`, and `word/people.xml`; drops their entries from `[Content_Types].xml` and `word/_rels/document.xml.rels`; removes inline `<w:commentRangeStart>`, `<w:commentRangeEnd>`, `<w:commentReference>` nodes from `document.xml`, headers, footers, footnotes, endnotes.
  - Accepts all tracked changes: in `document.xml` and all referenced parts, unwraps `<w:ins>` (keeps inner content), removes `<w:del>` (drops deleted content), strips revision attributes (`w:rsidR`, `w:rsidRPr`, `w:rsidDel`).
  - Removes `customXml/*.xml` parts entirely; drops their content-type entries and document-level relationships. Removes the corresponding `<w:dataBinding>` and `<w:sdt>` wrappers in `document.xml` (preserving inner `<w:r>` runs so visible text is kept).
  - Replaces `docProps/core.xml`, `docProps/app.xml`, `docProps/custom.xml` field values for `dc:creator`, `cp:lastModifiedBy`, `dc:title`, `dc:subject`, `cp:keywords`, `dc:description`, `cp:category`, `cp:contentStatus`, `Company`, `Manager` with the sentinel string `DocuDesk Anonymisation`. Unknown / extension fields are emptied.
  - Strips person-identity field codes by matching `<w:fldSimple w:instr=" AUTHOR ">`, `USERNAME`, `USERINITIALS`, `LASTSAVEDBY` (case-insensitive, whitespace-tolerant) and the multi-element `<w:fldChar>` / `<w:instrText>` form. Removes the wrapper element and its cached result inner runs. Other field codes (`DATE`, `TIME`, `PAGE`, `NUMPAGES`, etc.) are preserved.
  - Flattens hyperlinks: replaces every `<w:hyperlink>` element with its inner `<w:r>` runs (visible text kept), removes the relationship entry from `_rels/document.xml.rels` for the hyperlink's `r:id`.
- **NEW:** `lib/Service/File/Sanitizer/OdtSanitizer.php` performs the equivalent XML surgery on a copy of the input `.odt`:
  - Removes all `office:annotation` elements (comments).
  - Removes `text:tracked-changes` container; for inline `text:change`, `text:change-start`, `text:change-end` markers: accept inserts (keep referenced content), drop deletes (remove the referenced ranges).
  - Replaces `meta.xml` fields `dc:creator`, `meta:initial-creator`, `dc:title`, `dc:subject`, `meta:keyword`, `meta:user-defined` with sentinel / empty per the same rules as DOCX.
  - Flattens `text:a xlink:href` hyperlinks to plain `text:span` content.
  - Removes person-identity placeholder elements: `text:author-name`, `text:author-initials`, `text:initial-creator`.
- **NEW:** `lib/Service/File/SanitizationReport.php` value object capturing per-run counts (comments removed, tracked-change groups accepted, hyperlinks flattened, metadata fields scrubbed, custom XML parts dropped, field codes stripped). Returned to the caller alongside the sanitised file path.
- **NEW:** Sanitisation runs on a copy at a temp path (under Nextcloud's temp dir). The **original file is never mutated**. The caller owns the lifecycle of the sanitised output — it lands wherever the anonymisation flow places its derivative (existing `AnonymizationLog` / dossier-folder convention applies).
- **MODIFIED:** `lib/Service/File/DocumentProcessingHandler::anonymizeDocument` calls `OfficeDocumentSanitizer::sanitize($fileId)` before the walker pass when the input MIME matches DOCX or ODT. The walker pass then operates on the sanitised derivative.
- **MODIFIED:** `AnonymizationLog` (or the existing log record carrying anonymisation results) gains a `sanitization` JSON column holding the `SanitizationReport` payload, so operators can audit what was removed.
- **NEW capability:** `office-document-sanitization`. Scoped to the sanitiser surface only (DOCX + ODT structural cleanup). The existing walker / detection surfaces remain covered by other changes (entity-relation-grondslagen for detection; sister change `text-extraction-office-completeness` for deeper traversal).
- **NO new endpoints.** Sanitisation is internal to the anonymisation pipeline. The existing `POST /api/objects/{id}/anonymize` endpoint runs sanitisation transparently; no client-side change required.
- **NO breaking change.** Documents previously anonymised without sanitisation continue to exist; new anonymisations produce sanitised derivatives. Originals are preserved in both cases (and were already preserved in the existing flow).

### Pipeline shape

```
INPUT (.docx / .odt — original, in NC Files)
    │
    ├──► copy to /tmp/openregister-sanitize-<uuid>.<ext>
    │
    ├──► OfficeDocumentSanitizer (XML-surgery on the tmp copy)
    │       • strip comments, tracked-change markup, revision history
    │       • strip custom XML parts (and inline data-binding wrappers)
    │       • scrub metadata → "DocuDesk Anonymisation" sentinel
    │       • strip person-identity field codes
    │       • flatten hyperlinks
    │
    ├──► cleaned derivative passed to DocumentProcessingHandler walker
    │       • entity detection
    │       • entity substitution
    │
    └──► anonymised output written to dossier folder (existing flow)

ORIGINAL file: untouched, in its original location.
TEMP copy:    deleted after the pipeline completes.
SANITIZATION REPORT: persisted on the AnonymizationLog row.
```

### Output shape (sanitisation report)

```php
SanitizationReport {
    commentsRemoved: 12,
    trackedChangesAccepted: 7,     // insert groups
    trackedChangesDropped: 3,      // delete groups
    revisionAttributesStripped: 84,
    hyperlinksFlattened: 4,
    metadataFieldsScrubbed: 6,
    customXmlPartsDropped: 1,
    fieldCodesStripped: 2,         // AUTHOR / USERNAME / etc.
    sentinelApplied: "DocuDesk Anonymisation",
}
```

### Out of scope

- **Deeper walker traversal** (tables / lists / headers / footers / footnotes) — handled by sister change `text-extraction-office-completeness`. This change preserves these structures untouched; the walker change extends coverage.
- **Embedded images / drawings** — no OCR; image text is not extracted or anonymised. Out of scope.
- **OOXML revision IDs beyond w:rsid\* attributes on runs/paragraphs** — section-level rsid attributes are preserved (they don't carry PII directly). Forensic correlation is acknowledged but not addressed.
- **Encrypted DOCX / ODT** — password-protected files cannot be opened by the sanitiser; the pipeline raises a typed exception and the caller falls back to "refuse-to-anonymise" with an operator notice.
- **RTF, DOC (legacy binary), PPTX, ODS, ODP** — out of scope. Sanitisation targets the XML-container Office formats only (DOCX, ODT). Legacy `.doc` continues to be extracted via existing PhpWord-DOC reader without sanitisation (its content-leakage surface is different and beyond this change).
- **Word "Document Inspector" parity** — Microsoft's Inspector covers a wider set of structures (ink, smart tags, embedded objects). This change covers the PII-bearing subset relevant to Woo/dossier anonymisation. Future extension covers additional structures if operator workflows surface a need.
- **Reverse operation** — sanitisation is one-way. There is no "restore comments / restore tracked changes" path. The original is preserved separately; that is the only source for the unsanitised content.

## Capabilities

### New Capabilities

- `office-document-sanitization`: XML-level surgery on `.docx` and `.odt` files removing PII-bearing and identity-bearing structures (comments, tracked changes, revision history, person-identifying metadata, custom XML data bindings, person-identifying field codes, hyperlinks) ahead of the entity-anonymisation walker. Operates on a temp copy so the original is preserved. Produces a sanitisation report with per-category counts for audit.

### Modified Capabilities

(none — the sanitiser is a new surface; no existing OpenSpec capability covers `DocumentProcessingHandler` or `TextExtractionService` today.)

## Impact

- **Code (openregister):**
  - `lib/Service/File/OfficeDocumentSanitizer.php` — NEW orchestrator.
  - `lib/Service/File/Sanitizer/DocxSanitizer.php` — NEW DOCX strategy.
  - `lib/Service/File/Sanitizer/OdtSanitizer.php` — NEW ODT strategy.
  - `lib/Service/File/Sanitizer/SanitizerInterface.php` — NEW interface (per-format strategies implement).
  - `lib/Service/File/SanitizationReport.php` — NEW value object.
  - `lib/Exception/SanitizationException.php` — NEW typed exception.
  - `lib/Service/File/DocumentProcessingHandler.php` — MODIFIED `anonymizeDocument` to call the sanitiser ahead of the walker for DOCX/ODT inputs.
  - `lib/Db/AnonymizationLog.php` + migration — MODIFIED to add `sanitization` JSON column.
- **API contract:** No HTTP changes. The existing `POST /api/objects/{id}/anonymize` endpoint runs sanitisation transparently. Response gains a `sanitization` object alongside the existing anonymisation result (additive, non-breaking).
- **Cross-app:**
  - DocuDesk's anonymisation UI gains a "Sanitisation summary" block in the per-file report. Implementation lives in DocuDesk; this OR change exposes the data.
  - DocuDesk's `grondslagen-summary` Twig templates may render the sanitisation counts as an additional table block (operator decision; not specified here).
  - opencatalogi / softwarecatalog: unaffected.
- **Performance:** Adds an XML parse + serialize pass per `.docx`/`.odt` input. Typical files (<5 MB, ~100 paragraphs, <50 comments, <100 tracked changes) complete in well under a second. Large files (megabytes of body content, hundreds of comments) are bounded by the parser's linear cost. No new external services; all in-process.
- **Privacy / compliance:** Major win — removes a class of silent PII leakage (comments, tracked-change authors, document metadata, person-identity field codes). The sanitisation report provides audit evidence per ADR-005's compliance posture.
- **Tests:** Unit tests for `DocxSanitizer` and `OdtSanitizer` covering each strip category individually + combined. Integration test verifying Word + LibreOffice reopen the sanitised output without "found unreadable content" warnings (validation gate before claiming task done).
- **Migration:** One migration adding the `sanitization` JSON column to the anonymisation-log table. Backfill is not required (existing rows have `null`; that's interpreted as "pre-sanitisation run").
