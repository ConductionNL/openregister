## Why

OpenRegister's `TextExtractionService::extractWord` reaches only two levels deep into a `.docx` document tree (section → element, with an optional one-level descent for elements exposing `getElements()`). Real Word content lives deeper:

- **Tables** are `Section → Table → Row → Cell → elements (which may include further tables, lists, text frames)`. The current walker collects table-cell text only when the table is a direct child of a section AND the inner element exposes `getText()` — nested tables, mixed-content cells, and table-inside-list constructs leak entirely.
- **Lists** (`ListItemRun`) carry their text via `getItems()` / `getElement()`, not `getText()` — the current walker skips them.
- **Headers and footers** (per-section, per-type — default / first / even) are first-class document chrome on `Section::getHeaders()` / `Section::getFooters()` and are never visited.
- **Footnotes / endnotes** are document-level collections on `PhpWord::getFootnotes()` / `PhpWord::getEndnotes()`, separate from sections — not visited.
- **Text frames / text boxes** (`TextBox`) hold their content via `getElements()` but are styled in a way the current walker skips.

Consequence: entities (names, case numbers, addresses) inside any of these structures are invisible to detection AND survive the anonymisation pass. For Woo/dossier documents this is a meaningful gap — handler names live in footers, case references live in headers, and addressee blocks are often laid out as tables.

OpenDocument Text (`.odt`) is worse off: `extractWord` is gated on `isWordDocument(mime)` which only matches `application/msword` and the DOCX MIME, so ODT falls through `extractFile`'s cascade to "unsupported" — null extraction. On the anonymisation side, `DocumentProcessingHandler::anonymizeDocument` dispatches ODT inputs to `replaceWordsInTextDocument` which does raw string-replace on the ZIP container; running this on an `.odt` corrupts the ZIP and produces an unopenable file. Operators see "Anonymisation succeeded" then can't open the result.

This change deepens the walker and adds ODT as a first-class supported format on both extraction and anonymisation paths. The sister change `office-document-sanitization` (already scaffolded) preserves these structures untouched and runs ahead of this walker; the two changes compose cleanly — sanitiser strips identity-bearing structures, then this walker covers what remains.

The work targets two PhpWord-based code paths simultaneously:

1. **Extraction read path** — `TextExtractionService::extractWord` (rename to `extractOfficeDocument` since it now serves both DOCX and ODT, with `extractWord` kept as a thin alias for compatibility).
2. **Anonymisation write path** — `DocumentProcessingHandler::replaceWords` (the existing DOCX walker for entity substitution) extends to the same depth AND gains an ODT path. The two paths share a single traversal abstraction so detection and anonymisation see the same content surface.

## What Changes

- **NEW:** `lib/Service/File/OfficeDocumentWalker.php` — a single traversal abstraction that visits all content-bearing elements of a PhpWord-loaded document. Used by both extraction and anonymisation. Visitor pattern: callers pass a callback receiving each text-bearing element; the walker handles the recursive structure.
- **NEW:** Walker covers, in addition to the existing section-element pass:
  - **Tables, recursive:** `Table → Row → Cell → elements`. Cells may themselves contain tables, lists, or text frames; the walker recurses without depth limit (PhpWord's load step bounds depth by the source document's structure).
  - **Lists:** `ListItemRun::getElements()` traversal yields the `Text` / `Link` / `Run` children of each list item.
  - **Text frames / text boxes:** `TextBox::getElements()` recursion (frames can contain tables, lists, paragraphs).
  - **Headers and footers per section:** for each `Section`, iterate `Section::getHeaders()` and `Section::getFooters()` arrays. Each header / footer container exposes `getElements()` for its content; recurse identically to body content. Each section can have separate first-page, even-page, and default header/footer; all are visited.
  - **Footnotes and endnotes:** at document level, iterate `PhpWord::getFootnotes()->getItems()` and `PhpWord::getEndnotes()->getItems()`. Each note exposes `getElements()` for its content.
  - **Hyperlink display text** (`Link::getText()`) is visited and emitted as text — but per the sister `office-document-sanitization` change, hyperlinks have already been flattened before this walker runs. The walker is implemented defensively (handles `Link` if encountered) but in the integrated pipeline it should not encounter any.
- **MODIFIED:** `TextExtractionService::extractWord` refactored to delegate to `OfficeDocumentWalker::extractText($phpWord): string`. Output remains a single flat string; ordering preserves document order (body sections in section order, with headers preceding section body and footers following — chosen for predictable entity-relation context per design D3).
- **NEW:** `TextExtractionService::isOpenDocumentText(string $mimeType): bool` recognises `application/vnd.oasis.opendocument.text`.
- **NEW:** ODT branch in the `extractFile` cascade dispatching to `OfficeDocumentWalker::extractText` (PhpWord loads `.odt` via its `ODText` reader; no new library dependency).
- **MODIFIED:** `DocumentProcessingHandler::replaceWords` refactored to delegate to `OfficeDocumentWalker::replace($phpWord, $substitutions): void`. The walker visits the same deeper structure and applies entity substitutions in place on each visited text element. The result is written back through PhpWord's `Writer\Word2007` writer for DOCX OR `Writer\ODText` writer for ODT.
- **MODIFIED:** `DocumentProcessingHandler::anonymizeDocument` MIME dispatch: ODT inputs now route to a new ODT-aware write path using `OfficeDocumentWalker` + PhpWord ODText writer. The existing `replaceWordsInTextDocument` raw-string-replace path is restricted to truly plain-text MIMEs (`text/plain`, `text/markdown`, etc.) AND only invoked when the input is NOT an Office format. ODT no longer falls through to it.
- **NEW capability:** `text-extraction-office-completeness`. Scoped to the walker depth + ODT format support. The broader `TextExtractionService` surface (PDF, EML, spreadsheet) remains uncovered by this capability — same precedent as `text-extraction-eml`.
- **NO new endpoints.** Extraction and anonymisation are invoked through existing entry points; no HTTP contract change.
- **NO breaking change for DOCX files.** Existing extraction output for DOCX files gains content (header/footer/table/list/footnote text now appears) — this is additive, not removal. Existing anonymised outputs for DOCX files gain coverage (entities in those structures now get substituted). Per ADR-005, the anonymisation output is unambiguously safer than before.
- **BEHAVIOUR change for ODT files.** ODT extraction goes from null → populated text. ODT anonymisation goes from "corrupted output" → "correctly anonymised output". CHANGELOG entry under "Behavior changes" makes this explicit.

### Pipeline shape

```
INPUT (.docx / .odt)
    │
    ├──► office-document-sanitization runs first (sister change)
    │       — strips comments, tracked changes, metadata, custom XML, etc.
    │       — produces sanitised derivative at a temp path
    │
    ├──► OfficeDocumentWalker::extractText($phpWord)
    │       — recurses: sections, headers, footers, body, footnotes,
    │         endnotes, tables (nested), lists, text frames
    │       — returns single flat string for entity detection
    │
    ├──► entity detection (Presidio / LLM via existing services)
    │
    ├──► OfficeDocumentWalker::replace($phpWord, $substitutions)
    │       — same traversal; mutates text elements in place
    │
    └──► writer-back via PhpWord Writer\Word2007 (DOCX) or
                                   Writer\ODText (ODT)
```

### Output shape (extracted text)

Ordering chosen per design D3 — predictable, sectional, matches reading order. Within a section: header text first (default header, then first-page if distinct, then even-page if distinct), then body, then footer text (same ordering). Footnotes and endnotes are appended at the end of the document, each prefixed by an identifier marker (`[Footnote 1]`, `[Endnote a]`) so they remain traceable.

```
[Section 1 — Header]
{ default-header content }
[Section 1 — Body]
{ paragraph 1 }
{ paragraph 2 }
{ table content — row 1 col 1 | row 1 col 2 | ... }
{ list item 1 }
{ list item 2 }
{ text frame content }
[Section 1 — Footer]
{ default-footer content }

[Section 2 — Header]
...

[Footnote 1] { footnote body text }
[Footnote 2] { footnote body text }
[Endnote a] { endnote body text }
```

The section / header / footer / footnote markers are inserted by the walker as plain text lines; they don't affect entity detection (Presidio / LLM treats them as benign text). Operators viewing extracted text in the OR UI see them as readable section dividers.

### Out of scope

- **Embedded images / drawings / ink** — no OCR; image text is not extracted. Future change if needed (likely a separate Tesseract integration).
- **Charts / SmartArt diagrams** — their text content is buried in a separate part (`charts/chart1.xml`); not extracted. Out of scope; rare in Woo/dossier docs.
- **DOC (legacy binary `.doc` Word 97-2003 format)** — PhpWord's MsDoc reader covers it but with reduced fidelity and no ODText-equivalent writer. The walker is implemented against the in-memory PhpWord model so DOC inputs gain the deeper extraction automatically. Anonymisation of DOC is out of scope — DOC anonymisation would require a downgrading writer that PhpWord does not provide. DOC inputs continue to be extraction-only.
- **PPTX / ODP / ODS / XLSX** — different PhpOffice libraries (PhpPresentation, PhpSpreadsheet); separate concerns.
- **RTF** — PhpWord has an RTF reader but no writer; out of scope same reason as DOC.
- **Streaming traversal for very large documents** — PhpWord loads the whole document model into memory; this is fine for typical sizes (<10 MB). Streaming would require bypassing PhpWord entirely, which is out of scope.
- **Section markers in the extracted text are configurable** — v1 always emits the `[Section N — Body]` markers. If a downstream consumer wants the raw text without dividers, follow-up adds a toggle.

## Capabilities

### New Capabilities

- `text-extraction-office-completeness`: deeper traversal of `.docx` and `.odt` document trees on both extraction and anonymisation paths — covers tables (recursive), lists, headers, footers, footnotes, endnotes, and text frames; adds ODT as a first-class supported format alongside DOCX. Shared `OfficeDocumentWalker` abstraction guarantees detection and anonymisation see the same content surface.

### Modified Capabilities

(none — the broader `TextExtractionService` and `DocumentProcessingHandler` surfaces are currently uncovered by OpenSpec capabilities; this change does not retrofit them. See sister change `office-document-sanitization` and existing change `text-extraction-eml` for prior precedent.)

## Impact

- **Code (openregister):**
  - `lib/Service/File/OfficeDocumentWalker.php` — NEW shared traversal class.
  - `lib/Service/TextExtractionService.php` — MODIFIED `extractWord` refactored to delegate; `isOpenDocumentText` added; ODT branch in cascade.
  - `lib/Service/File/DocumentProcessingHandler.php` — MODIFIED `replaceWords` refactored to delegate; ODT write-back via PhpWord ODText writer; the raw-string-replace dispatch is restricted to plain-text MIMEs only.
  - Existing tests under `tests/` updated to reflect the new extraction output (header/footer/table content now present in DOCX extracted text).
- **API contract:** No HTTP changes. Existing `extractFile` returns populated text for ODT inputs (where it previously returned null). Existing `anonymize` produces a correctly-anonymised `.odt` file (where it previously produced a corrupted one). Both are behaviour improvements; no client-side change required.
- **Cross-app:**
  - DocuDesk's anonymisation pipeline now supports `.odt` end-to-end (when paired with the sister `office-document-sanitization` change).
  - opencatalogi / softwarecatalog: unaffected.
- **Performance:** Walker traversal is O(N) in document content size. For typical Woo docs (<10 MB body, hundreds of paragraphs, tens of tables / lists / footnotes), well under a second. PhpWord's in-memory document model is the dominant memory cost; the walker adds a recursive visit on top, no copies.
- **Privacy / compliance:** Closes a documented gap in entity detection coverage (handler names in footers, case refs in headers, addressees in tables). The anonymisation output becomes provably more complete than before. ADR-005 logging constraints continue to apply — walker MUST NOT log any visited text content.
- **Tests:** Unit tests for the walker covering: nested tables; lists; headers / footers per section; footnotes; endnotes; text frames; ODT round-trip (load → modify → save → reload, content preserved). Integration tests verifying detection covers all the new surface AND anonymisation substitutes entities in all the new surface.
- **Migration:** None. No DB changes. Pre-change extracted-text records for DOCX files have less content than post-change records — re-extraction is opt-in via the existing `forceReExtract` parameter. Pre-change anonymisation logs (if any) for ODT files are stale; the file outputs are corrupted and need to be re-anonymised manually.
