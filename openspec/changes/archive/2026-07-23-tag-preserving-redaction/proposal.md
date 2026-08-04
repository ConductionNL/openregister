## Why

OpenRegister's redaction engine flattens accessibility structure. When a
**tagged PDF** (PDF/UA, or any PDF carrying a logical structure tree) is
anonymised, the redacted derivative silently loses its tags, reading order and
alt text — and today the caller has no way to even know it happened.

Verified at HEAD, the redaction path is a pure-PHP byte pipeline that is
**structurally blind**:

- `DocumentProcessingHandler::replaceWordsInPdfDocument`
  (`lib/Service/File/DocumentProcessingHandler.php:1227`) drives
  `PdfTextReplacer::replaceInPdf` (`lib/Service/File/Pdf/PdfTextReplacer.php:114`),
  which loads the PDF via the Conduction `ddn/sapp` fork
  (`PDFDoc::from_string`), calls `replace_text_in_document()` to byte-replace
  text-showing operators in the content streams, and reserialises with
  `to_pdf_file_s(rebuild: true)` (`PdfTextReplacer.php:180`).
- SAPP (`ddn/sapp`, pinned to `dev-feat/chained-filter-text-replace` from
  `codeberg.org/Conduction/sapp`, composer.json:99) is a raw PDF-object /
  content-stream library. It has **zero** awareness of the structure tree:
  no `/StructTreeRoot`, `/MarkInfo`, marked-content (`BDC`/`EMC` + `MCID`),
  `/Alt`, `/ActualText`, `/Lang` or reading order. `rebuild: true` walks the
  object graph and reserialises byte offsets; it does not re-author or repair
  the tag→content correspondence that text mutation breaks.
- `PdfMetadataSanitizer::sanitize` (`PdfTextReplacer` sibling, run at
  `DocumentProcessingHandler.php:1341`) then strips `/Info` + XMP fields —
  again with no structure-tree handling.
- `smalot/pdfparser ^2.9` (the only other PDF reader in the stack,
  `DocumentProcessingHandler.php:1257` text-layer probe + `PdfTextReplacer.php:252`
  residual validation) is **read-only** text extraction; `dompdf/dompdf ^3.1`
  is HTML→PDF export, not on the redaction path.

The processing result the caller receives back reflects this blindness. After
`anonymizeDocument` (`DocumentProcessingHandler.php:318`), callers read
`getLastResidualEntities()` / `getLastSanitizationReport()`
(`DocumentProcessingHandler.php:183-216`), and the HTTP seam
`FileTextController::anonymizeFile` (`lib/Controller/FileTextController.php:425`)
returns `{success, complete, residual_count, residual_entities, …}`
(`FileTextController.php:574`). **Nothing** in that contract reports whether the
input was tagged or whether the tags survived — the accessibility regression is
invisible to the DocuDesk leaf and to the operator.

Why this matters now:

- **Legal (EN 301 549 / WCAG / the European Accessibility Act):** public-sector
  documents must be accessible. A redaction step that quietly strips PDF/UA
  structure turns an accessible source document into an inaccessible
  derivative — an accessibility *regression manufactured by the privacy tool*.
- **Consumer demand:** the DocuDesk leaf owns the operator-facing
  "accessible redaction" workflow and must consume a truthful signal from the
  engine to warn, block, or degrade. It cannot invent that signal client-side;
  taggedness and tag counts live in the PDF bytes the engine already parses.
- **Honesty over magic:** the current pure-PHP stack *cannot* re-author a
  structure tree, so this change does not promise repair it cannot deliver. It
  delivers **detection, measurement, explicit degradation, and a truthful
  result contract** — the engine either preserves the tag tree or says, in
  structured data, exactly what it lost and why.

## What Changes

- **Structure inspection seam.** A new `PdfStructureInspector`
  (`lib/Service/File/Pdf/`) reads a SAPP-parsed document and reports whether it
  is tagged (`/StructTreeRoot` present and/or `/MarkInfo` `/Marked true`) and
  the count of structure elements (`/Type /StructElem` objects) — a pure
  function over the parsed object model, unit-testable without a live instance.
- **Preserve-or-degrade-explicitly redaction.** When the input is tagged,
  `PdfTextReplacer::replaceInPdf` passes the structure-tree object graph
  (`/StructTreeRoot`, `/MarkInfo`, `/RoleMap`, document `/Lang`) through the
  SAPP rebuild rather than dropping it, and re-measures afterwards. When the
  text mutation breaks the tag→content correspondence (the common case: MCIDs
  can no longer be re-mapped by this engine) or when the tree cannot be carried
  through an operation/page, the engine records the loss **explicitly** — it
  never silently flattens.
- **`structurePreservation` result block** on the processing result, exposed via
  a new `getLastStructurePreservation()` accessor and the `anonymizeFile` HTTP
  response — the exact contract the DocuDesk accessible-redaction leaf consumes.
- **`preserveStructure` option** plumbed through the public seams
  (`replaceWords` / `anonymizeDocument` options + the `anonymizeFile` HTTP
  param). Default: **preserve when the input is tagged** (auto); untagged input
  is unaffected and byte-stable.

## Capabilities

### New Capabilities

- `tag-preserving-redaction`: Detect a tagged PDF, preserve its logical
  structure tree through text-replacement redaction where the engine can, and
  where it cannot, degrade explicitly and report the loss through a truthful
  `structurePreservation` result contract consumed by the DocuDesk accessible-
  redaction leaf.

### Modified Capabilities

<!-- None at spec level. `pdf-anonymisation`, `file-actions` and
     `office-document-sanitization` are touched at implementation level only
     (a new option arg + a new result block on existing seams); their existing
     spec-level requirements — the byte-replace pipeline, the residual
     validation gate, metadata sanitisation — are unchanged and are consumed,
     not re-specified, here. The untagged-PDF byte-stability guard
     (REQ-ORTPR-005) exists precisely to prove those requirements still hold. -->

## Impact

- **Backend:** new `lib/Service/File/Pdf/PdfStructureInspector.php` (taggedness
  + StructElem count over the SAPP object model) and a `StructurePreservation`
  value object (`lib/Service/File/Pdf/`); `PdfTextReplacer::replaceInPdf`
  gains a `preserveStructure` arg and an out-param carrying the preservation
  result; `DocumentProcessingHandler::replaceWords` / `anonymizeDocument`
  gain the `preserveStructure` option and a `lastStructurePreservation`
  accessor alongside the existing `lastResidualEntities` /
  `lastSanitizationReport` surfaces.
- **HTTP seam:** `FileTextController::anonymizeFile`
  (`lib/Controller/FileTextController.php:425`) reads an optional
  `preserveStructure` request param and adds a `structurePreservation` block to
  its `JSONResponse` — no new route, no auth-posture change (existing
  file-access gate at `FileTextController.php:105` unchanged).
- **Consumed unchanged (ADR-022):** `ddn/sapp` `PDFDoc` object model,
  `smalot/pdfparser` (residual validation), `PdfMetadataSanitizer`, the residual
  best-effort policy, the `AnonymisationLog` write path.
- **Consumer (leaf, not modified here):** the DocuDesk accessible-redaction
  change consumes the `structurePreservation` block verbatim (field names are
  contractual — see design.md) to warn/block/degrade in the operator UI.
- **Library reality (see design.md D1):** the current stack **cannot re-author**
  a structure tree. This change deliberately scopes to detection, measurement,
  best-effort pass-through, and truthful explicit degradation — a repair engine
  (e.g. an out-of-process `qpdf`/structure-aware path) is recorded as an
  explicit deferred alternative, not specced here.
- **Evidence:** EN 301 549 / EAA accessibility obligation on public-sector PDFs;
  DocuDesk accessible-redaction leaf demand (research-user-wishes); HEAD
  verification of the structurally-blind pipeline cited above.
