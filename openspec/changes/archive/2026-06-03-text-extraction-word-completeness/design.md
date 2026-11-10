## Context

`TextExtractionService` (in `lib/Service/`) is OpenRegister's text-from-files extractor. Its cascade in `performTextExtraction` (around line 899) covers plain-text MIMEs, PDF (smalot/pdfparser), Word documents (PhpWord), spreadsheets (PhpSpreadsheet), and now EML (`text-extraction-eml`). The Word branch is dispatched via `isWordDocument()` (around line 2058) and handled by the private `extractWord()` (around line 1422).

Two concrete defects in `extractWord()`:

1. **Incomplete traversal.** The current loop iterates `$phpWord->getSections()` → `$section->getElements()`. For each element it appends `getText()` if present, else iterates exactly one level of `$element->getElements()`. PhpWord's object model does not fit this 2-level shape:
   - A `Table` element exposes `getRows()` (each `Row` → `getCells()`, each `Cell` → `getElements()`), NOT `getElements()` at the table level. The current `method_exists($element, 'getElements')` check is false for `Table`, so **table content is dropped entirely today**.
   - `TextRun`s inside cells, nested tables, and `ListItem` / `ListItemRun` text live deeper than one level and are missed.
   - Section **headers and footers** (`$section->getHeaders()`, `$section->getFooters()` — each a container exposing `getElements()`) are never visited.
   - **Footnotes and endnotes** are never visited.

2. **Reader defaulting.** `PhpWord\IOFactory::load($filename, $readerName = 'Word2007')` defaults to the Word2007 reader. `.doc` and `.odt` inputs therefore fail to load — even though `application/msword` is already in the routing allow-list and the `MsDoc` and `ODText` readers are vendored (phpoffice/phpword ^1.2 ships Word2007, MsDoc, ODText, RTF, HTML).

The consumer need driving this is the same as for `text-extraction-eml`: a single flat plain-text string for entity detection. DocuDesk's anonymisation pipeline consumes that string; any Word table cell, header, or footer dropped today is PII that never reaches detection and is never redacted. This is a soft dependency in the same spirit as `text-extraction-eml`'s relationship with DocuDesk's `anonymise-output-as-pdf-by-default` — improving extraction quality the anonymisation pipeline consumes, with no hard cross-app coupling.

The capability scope follows the precedent set by `text-extraction-eml` and `entity-relation-grondslagen`: spec ONLY the Word-family extension; do not retroactively spec the broader `TextExtractionService` surface (PDF, spreadsheet, EML) which is implemented but currently uncovered by an OpenSpec capability. That broader retrofit is its own concern.

## Goals / Non-Goals

**Goals:**

- DOCX extraction captures ALL body text including table cells (and nested tables), text runs, list items, plus section headers/footers and footnotes/endnotes — in document order — instead of dropping table and header/footer content.
- Legacy `.doc` (`MsDoc` reader) and OpenDocument `.odt` (`ODText` reader) inputs load and extract, selected by MIME/extension instead of relying on the Word2007 default.
- A recursive element walker with a depth guard is the single traversal mechanism, reused for body, headers/footers, and notes — no divergent traversal paths.
- Reader/load failure (notably the binary `MsDoc` parser's known limitations) is non-fatal: log and return null, matching the pipeline's existing "null = unsupported/empty" contract.
- No behaviour change for non-Word files; DOCX text is a strict superset of today's output.

**Non-Goals:**

- Retrofit OpenSpec capability for the broader `TextExtractionService` surface (PDF, spreadsheet, EML).
- Faithful layout / table-structure reconstruction (no Markdown or CSV table output). Flattened text only.
- Extraction of embedded objects, images, charts, OLE objects, or image alt-text.
- Extraction of tracked changes, revision marks, or comments.
- Decryption of password-protected Word documents (they fail to load → null).
- Routing `.rtf` / `.html` through the Word branch (readers exist; wiring those MIMEs is a follow-up).

## Decisions

### D1. Recursive element walker with a depth guard

Replace the 2-level loop with a single private recursive helper that accepts any PhpWord element (or element container) plus a current depth, and appends extracted text to an accumulator. Dispatch within the walker:

- **Text-bearing elements** — `Text`, `TextRun`, `Title`, `Link`, `ListItem`, `ListItemRun`, `PreserveText`: if the element exposes `getText()`, append its text. (`TextRun` also exposes `getElements()`; prefer recursing its children so styled sub-runs are captured. Where an element exposes both, the walker handles children when present and falls back to `getText()` otherwise — see D3.)
- **`Table`** — detect via `method_exists($element, 'getRows')`. Iterate `getRows()` → each `Row::getCells()` → each `Cell::getElements()` → recurse each cell element. Nested tables fall out naturally because a cell element may itself be a `Table` and re-enters the same branch.
- **Generic container** — any element exposing `getElements()` (and not already handled as a table): recurse over its children.
- **Otherwise** — ignore (non-text element: image, object, etc.).

**Depth guard:** the helper takes a `$depth` parameter; if `$depth` exceeds a constant cap (`MAX_WORD_DEPTH`, value 50 — far beyond any realistic document nesting, e.g. tables-in-cells-in-tables), it stops descending and returns, emitting a debug log noting the cap. This defends against pathological or malicious documents that nest containers to exhaust the stack.

**Rationale:** one recursive function correctly models PhpWord's arbitrary-depth tree. The order of checks (text-bearing → table → generic container) is chosen so that `Table` is matched by its `getRows()` shape before the generic `getElements()` fallback, and elements that carry both text and children are descended rather than flattened to a single `getText()`.

**Alternative considered:** keep an explicit per-type `instanceof` switch over the full PhpWord element class hierarchy. Rejected — brittle against PhpWord version changes and verbose; the duck-typed `method_exists` approach (already the file's existing style) tolerates new element types that expose the same accessors.

### D2. Reuse the walker for headers, footers, and notes

`extractWord()` walks, per section:

1. Body — `$section->getElements()`.
2. Headers — `foreach ($section->getHeaders() as $header) { walk($header->getElements()) }`.
3. Footers — `foreach ($section->getFooters() as $footer) { walk($footer->getElements()) }`.

Footnotes and endnotes are captured via **both** paths, unconditionally (decided — see Open Questions). PhpWord surfaces a `Footnote` / `Endnote` element inline in the run (exposing `getElements()`), so note text is captured when the walker recurses a `TextRun` containing one. In addition, `extractWord()` ALWAYS iterates the document-level notes collection (`$phpWord->getNotes()` / the footnote+endnote collections the vendored PhpWord 1.2 exposes) and walks each note's elements. Capturing both paths is belt-and-suspenders against PhpWord version differences in where notes are surfaced; de-duplication is not required for the flat-text use case (a repeated note string is acceptable, and in practice the two paths do not both yield the same note).

**Rationale:** headers/footers/notes are just more element containers; the same walker applies. No separate extraction logic.

### D3. Element handling order — children before `getText()` where both exist

Some elements (notably `TextRun`) expose both `getText()` (which may return the concatenated text, or in some PhpWord versions an empty/partial value) and `getElements()` (the styled sub-runs). The walker MUST prefer descending into `getElements()` when the element has children, falling back to `getText()` only for leaf text elements. This avoids both double-counting (appending `getText()` AND the children) and under-counting (appending an empty `TextRun::getText()` while ignoring its real children). Leaf elements that expose `getText()` but no children (`Text`, `Title`, `Link`, `ListItem`, `PreserveText`) append `getText()` directly.

**Rationale:** correctness — PhpWord's `getText()` semantics vary across element types; child traversal is the reliable source of truth for composite elements.

### D4. Reader selection by MIME / extension

`extractWord()` selects the PhpWord reader explicitly rather than relying on the `Word2007` default:

| Input | MIME | Reader name |
| --- | --- | --- |
| `.docx` | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` | `Word2007` |
| `.doc` | `application/msword` | `MsDoc` |
| `.odt` | `application/vnd.oasis.opendocument.text` | `ODText` |

A private helper maps MIME → reader name, falling back to extension when the MIME is generic/ambiguous, and finally defaulting to `Word2007`. The reader name is passed to `IOFactory::load($tempPath, $readerName)`.

**Rationale:** the load call already exists; this is a constant-time branch selecting the correct vendored reader. RTF and HTML readers are available but not wired (out of scope per the proposal).

**Alternative considered:** call `IOFactory::createReader($readerName)` then `canRead()` to auto-detect. Rejected — adds I/O and indirection; the MIME is already known from the routing layer, so a direct map is simpler and deterministic.

### D5. Graceful null on reader / load failure

The `MsDoc` reader for the legacy binary `.doc` format is inherently limited and can fail on documents it cannot parse. On any `IOFactory::load()` failure (exception) OR an empty-after-walk result, `extractWord()` logs (no PII — file ID, MIME, reader name, exception class only, per ADR-005) and returns null. It MUST NOT throw a fatal error for unsupported/limited inputs — the surrounding pipeline already treats null as "unsupported/empty" and continues.

This is a behaviour change from the current `extractWord()`, which throws `Exception("Word extraction failed: ...")` on PhpWord errors. The new behaviour returns null instead, so a single un-parseable `.doc` no longer aborts a batch. The "PhpWord library not available" guard (class missing) retains its current throw — that is a deployment/config error, not a per-document failure.

**Rationale:** legacy `.doc` parsing is best-effort; one bad binary document should degrade to "no text for this file", not fail the extraction run.

**Alternative considered:** keep throwing and let the caller catch. Rejected — the caller's catch turns any throw into a logged error and null anyway, but throwing per-document is noisier and risks aborting callers that don't wrap each file; returning null is the cleaner contract and matches the EML branch's `extractEml` pattern.

### D6. Routing — add ODT MIME; DOC already allow-listed

`isWordDocument()` (around line 2058) gains `application/vnd.oasis.opendocument.text` alongside the existing `application/vnd.openxmlformats-officedocument.wordprocessingml.document` and `application/msword`. No change is needed in `performTextExtraction()`'s dispatch (around line 899) beyond what `isWordDocument()` already routes — the existing `else if ($this->isWordDocument(...))` branch now also catches ODT. `application/msword` was already allow-listed but silently failed to load under the Word2007 default; D4 fixes that.

### D7. No new classes; keep walker private to the service

The recursive walker and the MIME→reader map are private methods on `TextExtractionService`. Unlike `text-extraction-eml` (which introduced `EmlParser` + value objects for a structured second consumer), this change has a single consumer shape (a flat string) and no structured second path, so no new classes are warranted. This respects ADR-011 (reuse before adding utilities) — the new logic is local to the one extractor that needs it.

## Risks / Trade-offs

- **[Behaviour change: DOCX output grows]** Table/header/footer/note text is now included, so extracted-text length increases for documents that have those. A tenant relying on the old truncated output (unlikely) sees more content. → Mitigation: documented in CHANGELOG under "Behavior changes"; existing extractions are not re-run automatically (opt-in via `forceReExtract`).
- **[Behaviour change: per-document failures now return null instead of throwing]** Callers that depended on `extractWord()` throwing on a bad document would no longer get an exception. → Mitigation: the existing caller already converts throws to logged-error + null, so observable behaviour for the standard path is unchanged; the change only prevents un-wrapped callers from aborting. Documented.
- **[MsDoc reader limitations]** The binary `.doc` reader may extract partial or no text for some real-world documents. → Mitigation per D5: graceful null + log; the format is best-effort and explicitly so in the spec.
- **[Pathological nesting / stack exhaustion]** A malicious document could nest containers deeply. → Mitigation per D1: depth guard (`MAX_WORD_DEPTH` = 50) caps recursion and logs the cap hit.
- **[PhpWord version drift on note/header APIs]** Header/footer/note accessors could vary across PhpWord versions. → Mitigation per D2/D3: duck-typed `method_exists` checks; the spec asserts outcomes (text captured), not specific traversal calls, so the implementation can adapt to the vendored version.
- **[ODT MIME detection]** Nextcloud must report `application/vnd.oasis.opendocument.text` for `.odt` files for routing to fire. → Mitigation: standard Nextcloud MIME mapping covers ODT; reader selection also falls back to extension (D4) if the MIME is generic.

## Migration Plan

1. Land the `extractWord()` rewrite (recursive walker, headers/footers/notes, reader selection, graceful null), the new private helpers, and the `isWordDocument()` ODT addition in `lib/Service/TextExtractionService.php`. No new dependency — `phpoffice/phpword ^1.2` is already vendored.
2. Land unit tests with fixture documents (DOCX with table + header/footer; ODT; DOC if feasible) plus reader-selection and graceful-fallback tests.
3. Release. Existing extracted-text records for Word files are not re-extracted automatically; new Word inputs (or `forceReExtract: true`) produce the more complete text.

**Rollback:** revert the `extractWord()` and `isWordDocument()` changes — DOCX returns to the truncated 2-level output and DOC/ODT fail to load again. No data migration, no dependency change to revert. Rollback is per-commit clean.

## Seed Data

No new or modified schemas — seed data not applicable for this change. This change is pure service logic in `TextExtractionService`; it does not introduce or modify any OpenRegister register, schema, or object definition.

## Open Questions

- **Document-level footnote/endnote collection vs inline notes** — DECIDED (user, 2026-06-03): capture notes via BOTH paths unconditionally — inline `Footnote`/`Endnote` elements during the run walk AND a always-on iteration of the document-level notes collection. No fixture-gated fallback. See D2.
- **`MAX_WORD_DEPTH` value** — DECIDED (user, 2026-06-03): 50. Generous cap well above realistic nesting; revisit only if a legitimate template is found to exceed it.
- **Behaviour-change risk acceptance for the throw→null switch** — DECIDED (user, 2026-06-03): accept the throw→null change in `extractWord()` (matches the EML branch and the pipeline's null contract; the existing caller already converts the throw to logged-error+null). See D5.
