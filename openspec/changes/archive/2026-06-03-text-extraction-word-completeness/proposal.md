## Why

OpenRegister's `TextExtractionService` extracts text from Word documents via PhpWord, but the current `extractWord()` implementation is incomplete in two ways. First, it descends only two element levels and appends text from elements exposing `getText()` or one level of `getElements()`. A PhpWord `Table` element does NOT expose `getElements()` — it exposes `getRows()` → `getCells()` → cell `getElements()` — so **all table content is silently dropped today**, along with nested tables, `TextRun`s inside cells, list items, and any text in headers, footers, footnotes, and endnotes. Second, `PhpWord\IOFactory::load()` defaults to the `Word2007` reader, so legacy `.doc` and OpenDocument `.odt` inputs fail to load even though `application/msword` is already in the routing allow-list.

The downstream consequence is the same as for any extraction gap: missing text means missing entities for anonymisation. The DocuDesk anonymisation pipeline (its default-PDF mode, paired with `anonymise-output-as-pdf-by-default`) consumes OpenRegister's extracted text — every Word table cell, header, and footer that is dropped today is PII that is never detected and never redacted. Government Word documents (formulieren, besluiten, dossier-overzichten) routinely carry names and BSNs inside tables and headers, exactly the content currently lost.

## What Changes

- **NEW:** A recursive element walker in `extractWord()` replacing the 2-level traversal. It handles text-bearing elements (`Text`, `TextRun`, `Title`, `Link`, `ListItem`, `ListItemRun`, `PreserveText`) via `getText()`; `Table` via `getRows()` → `getCells()` → recurse cell `getElements()` (including nested tables); and any other element exposing `getElements()` by recursing. A depth guard caps pathological recursion.
- **NEW:** Extraction of section **headers and footers** (`$section->getHeaders()`, `$section->getFooters()` — each exposes `getElements()`) and **footnotes / endnotes**, walked with the same recursive walker.
- **NEW:** Reader selection by MIME / extension in `extractWord()` instead of relying on the `Word2007` default — DOCX → `Word2007`, DOC → `MsDoc`, ODT → `ODText` (`RTF` → `RTF` and `HTML` → `HTML` are available too). MsDoc binary parsing is limited; on reader failure the method logs and returns null gracefully rather than throwing fatally, matching the pipeline's existing "null = unsupported/empty" contract.
- **NEW:** The ODT MIME `application/vnd.oasis.opendocument.text` added to the Word routing — `isWordDocument()` (around line 2058) and the dispatch in `performTextExtraction()` (around line 899). `application/msword` is already allow-listed and now actually loads via the `MsDoc` reader.
- **NEW capability:** `text-extraction-word`. Tightly scoped to Word-family extraction completeness (body incl. tables/headers/footers/notes, plus DOC/ODT reader support). The broader `TextExtractionService` surface (PDF, spreadsheet, EML) remains uncovered by this capability; retrofitting it is out of scope, following the precedent of `text-extraction-eml` and `entity-relation-grondslagen`.
- **NO new endpoints.** Extraction is invoked through the existing `extractFile($fileId)` path. No HTTP surface changes.
- **NO schema changes.** This is pure service logic — no OpenRegister schema is introduced or modified.
- **NO breaking change for DOCX.** DOCX files extract at least as much text as before (strictly more — table/header/footer content is now included). DOC and ODT inputs that previously failed to load now produce text.

### Output shape (flat plain-text extraction)

The flat string returned by `extractWord()` keeps the existing pattern (one extractor returns one string). Table cell text, header/footer text, and footnote/endnote text are now included in document order; previously they were absent. Example for a document with a header, a body table, and a footer:

```
(header text: "Gemeente Voorbeeld — Concept")

Onderwerp: Besluit Woo-verzoek 2025-017

Aanvrager | BSN        | Status
J. de Vries | 123456782 | Toegekend
A. Bakker   | 987654321 | Afgewezen

(footnote: "Conform artikel 5.1 Woo")

(footer text: "Pagina 1 van 3")
```

### Out of scope

- **A retrofit OpenSpec capability for the broader `TextExtractionService` surface** (PDF, spreadsheet, EML) — out of scope here. This change covers the Word family only.
- **Faithful layout / formatting reconstruction** — extraction targets entity detection, not document fidelity. Table structure is flattened to text; no Markdown/CSV table reconstruction in v1.
- **Embedded objects and images** — drawings, charts, embedded OLE objects, and image alt-text are not extracted. Only text-bearing elements are walked.
- **Tracked changes / comments** — revision marks and comment threads are not extracted in v1; only accepted body text is returned.
- **Password-protected / encrypted Word documents** — these fail to load; the method logs and returns null gracefully (no decryption attempt).
- **`.rtf` and `.html` routing** — the readers exist and are selectable, but adding those MIMEs to the Word routing allow-list is a follow-up; this change wires DOCX, DOC, and ODT.

## Capabilities

### New Capabilities

- `text-extraction-word`: Word-family text extraction completeness in `TextExtractionService` — recursive element walking that captures table cells (incl. nested tables), text runs, list items, section headers/footers, and footnotes/endnotes; plus reader selection by MIME/extension to support legacy `.doc` (MsDoc) and `.odt` (ODText) alongside `.docx` (Word2007), with graceful null fallback on reader failure.

### Modified Capabilities

(none — the broader `TextExtractionService` surface is currently uncovered by an OpenSpec capability; this change does not retrofit it. See `Out of scope`.)

## Impact

- **Code (openregister):**
  - `lib/Service/TextExtractionService.php`:
    - `extractWord()` (around line 1422) — replace the 2-level traversal with a recursive walker; add section header/footer and footnote/endnote extraction; select the reader by MIME/extension; return null gracefully on reader failure instead of throwing.
    - New private helper method(s) for the recursive element walk (with a depth guard).
    - `isWordDocument()` (around line 2058) — add `application/vnd.oasis.opendocument.text`.
    - `performTextExtraction()` (around line 899) — the `isWordDocument()` dispatch now also routes ODT (no structural change, follows from `isWordDocument()`).
  - No new classes required; the walker can live as private methods on `TextExtractionService`. (`phpoffice/phpword ^1.2` already vendored; readers Word2007, MsDoc, ODText, RTF, HTML are available — no new dependency.)
- **API contract:** No changes to existing API. The flat-text path returns strictly more content for DOCX (table/header/footer/notes now included) and populated content for DOC/ODT (previously failed). No HTTP surface changes.
- **Schemas:** None. No OpenRegister schema is created or modified.
- **Cross-app:**
  - Improves the extraction quality that DocuDesk's anonymisation pipeline consumes — soft dependency, same framing as `text-extraction-eml`'s relationship to DocuDesk's `anonymise-output-as-pdf-by-default`. Word table/header/footer PII becomes visible to entity detection and therefore redactable.
  - opencatalogi and softwarecatalog consume extraction unchanged in shape; they simply get more complete Word text.
- **Performance:** The recursive walk is O(number of document elements) — linear in document size, same order as today, just complete. The depth guard bounds worst-case recursion. Reader selection is a constant-time branch. No measurable regression for typical documents.
- **Privacy / compliance:** Table cells, headers, and footers in government Word documents carry PII (names, BSNs, case numbers). Making this text visible to entity detection improves anonymisation completeness. No new data leaves the OR boundary.
- **Tests:** Unit tests with small fixture documents — a DOCX with a table plus header/footer asserting cell and header/footer text is captured; an ODT; a DOC if feasible — plus reader-selection and graceful-fallback assertions. Follow the style of the existing `tests/Service/TextExtractionServiceIntegrationTest.php`.
- **Migration:** None. No DB changes. Word inputs already extracted will not be re-extracted automatically; new extractions (or `forceReExtract: true`) produce the more complete text. DOCX text length increases; a tenant relying on the old truncated output (unlikely) gets a behaviour change — documented in CHANGELOG under "Behavior changes".
