## Context

`TextExtractionService::extractWord` (`lib/Service/TextExtractionService.php` ~line 1422) and `DocumentProcessingHandler::replaceWords` (`lib/Service/File/DocumentProcessingHandler.php`) are paired walkers on PhpWord's in-memory document model. The first reads text out; the second mutates text in place for entity substitution. They diverged independently during prior development — both only reach two levels of nesting, and neither visits headers, footers, footnotes, endnotes, or any structure PhpWord doesn't expose via the `getText()` / `getElements()` duo at top level.

The PhpWord 1.3.x object model exposes the deeper structure:

```
PhpWord
├── getSections(): Section[]
│   └── each Section
│       ├── getHeaders(): Header[]      (default / first / even)
│       ├── getFooters(): Footer[]      (default / first / even)
│       └── getElements(): AbstractContainer[] (body content)
│           ├── TextRun                 → getElements(): Text[], Link, ...
│           ├── Text                    → getText()
│           ├── Table
│           │   └── getRows(): Row[]
│           │       └── each Row
│           │           └── getCells(): Cell[]
│           │               └── each Cell
│           │                   └── getElements(): AbstractContainer[]   ← may include Table, ListItemRun, TextBox, TextRun
│           ├── ListItemRun
│           │   └── getElements(): Text[], Link, ...
│           ├── TextBox
│           │   └── getElements(): AbstractContainer[]   ← recursive
│           ├── Link                    → getText() (display), getTarget() (URL — already stripped by sister sanitiser)
│           └── (other: Image, Drawing, OLEObject — non-text, ignored)
├── getFootnotes(): Footnotes           → getItems(): Footnote[]   → each: getElements()
└── getEndnotes(): Endnotes             → getItems(): Endnote[]    → each: getElements()
```

PhpWord uses the same model for `.docx` (`Word2007` reader/writer) and `.odt` (`ODText` reader/writer). The walker is format-agnostic in its in-memory work; format selection only matters at load and save.

The sister change `office-document-sanitization` runs ahead of this walker and strips comments, tracked-change markup, custom XML data bindings, and hyperlinks. By the time the walker runs:

- Hyperlinks no longer exist — they've been flattened to bare runs by the sanitiser.
- Comments no longer exist — their parts are gone and their inline references are gone.
- Tracked changes are resolved — `<w:ins>` is unwrapped, `<w:del>` is gone.
- Custom XML is gone; `<w:sdt>` wrappers are unwrapped to their `<w:sdtContent>` children.

So the walker only needs to traverse the surviving content tree. It does not need to know about ins/del/sdt/hyperlink wrappers — they are sanitiser concerns.

In standalone tests (without the sanitiser), the walker MUST still tolerate the presence of these wrappers (PhpWord exposes them as `getElements()` children). Defensive design: any element exposing `getElements()` is recursed; any element exposing `getText()` has its text visited.

## Goals / Non-Goals

**Goals:**

- A single `OfficeDocumentWalker` class implementing both extraction (read) and anonymisation (mutate-in-place) over a PhpWord document.
- Walker traverses: sections, section headers / footers (all types), section body elements, tables (recursive cell content), lists, text frames, document-level footnotes, document-level endnotes.
- Extraction output is a single flat string with predictable section markers (`[Section N — Header]`, `[Section N — Body]`, `[Section N — Footer]`, `[Footnote N]`, `[Endnote N]`) for traceable readability.
- ODT is a first-class supported MIME: extraction returns populated text; anonymisation produces a valid `.odt` file using PhpWord's ODText writer.
- Pre-change behaviour for DOCX is strictly additive: existing text is still present; missing text now appears.
- ODT anonymisation no longer corrupts the output file.

**Non-Goals:**

- Embedded images / drawings / OLE objects / charts / SmartArt — text content not extracted.
- Legacy `.doc` (Word 97-2003 binary) anonymisation. Extraction works (PhpWord MsDoc reader); writing back is unsupported because PhpWord has no MsDoc writer.
- RTF, PPTX, ODP, ODS, XLSX — different scopes / libraries.
- Streaming traversal for huge documents — PhpWord loads the whole model into memory.
- Configurable section-marker style. v1 emits hard-coded `[Section N — Body]` etc. Follow-up if a real consumer needs a toggle.
- A return to the old "no markers in extracted text" output shape. The markers improve traceability and detection context; existing consumers (entity detection, summary report) tolerate the markers as plain text.

## Decisions

### D1. Single `OfficeDocumentWalker` for both read and mutate paths

Co-locating extraction and anonymisation traversal in one class prevents the two paths drifting apart again. The class exposes two top-level methods:

```php
final class OfficeDocumentWalker {
    public function extractText(\PhpOffice\PhpWord\PhpWord $phpWord): string;
    public function replace(
        \PhpOffice\PhpWord\PhpWord $phpWord,
        array $substitutions  // ['detected text' => 'replacement', ...]
    ): void;
}
```

Internally both methods drive a private `walk()` that yields text-bearing elements via callbacks. `extractText` collects via a string-builder callback; `replace` mutates via a setter callback.

**Rationale:** entity detection (read path) and entity substitution (write path) MUST see the same content surface — otherwise an entity found in a footer would not get substituted there. Shared traversal guarantees this invariant by construction.

**Alternative considered:** two separate classes with a shared visitor interface. Rejected: an interface increases ceremony for a small surface, and the two operations are inherently coupled (the read informs the write). One class with two public methods is leaner.

### D2. Element visitation rules

The walker classifies each visited element into one of four categories:

1. **Has `getText()` → text-leaf.** Visit it (collect for read; mutate for write).
2. **`Table` → recurse rows → cells → cell elements.** Tables are recognised by `instanceof Table` (not by `getElements()`, because some non-table elements expose `getElements()` for unrelated reasons).
3. **`ListItemRun` → recurse `getElements()`.** Each list item is a container of run-level children.
4. **`TextBox` → recurse `getElements()`.** Text frames may contain tables and lists.
5. **Anything else with `getElements()` (e.g. `TextRun`, leftover `<w:sdt>` content) → recurse `getElements()`.**
6. **Anything else (e.g. `Image`, `Drawing`, `OLEObject`) → skip.**

Order matters: check `instanceof Table` BEFORE `getElements()` (a Table doesn't expose `getElements()` but does have rows / cells — different traversal). Check `Link` AFTER `getText()` (a Link exposes both; the walker reads its display text via `getText()` rather than recursing into its inner runs).

### D3. Output ordering and section markers

Headers print before the section body; footers print after. Within a section:

```
[Section N — Header default]
{header content}
[Section N — Header firstPage]    (only if distinct from default)
{header content}
[Section N — Header even]         (only if distinct from default)
{header content}
[Section N — Body]
{body content in document order — paragraphs, tables, lists, frames}
[Section N — Footer default]
{footer content}
... (firstPage / even footers if distinct)
```

After all sections, document-level notes:

```
[Footnote 1] {content}
[Footnote 2] {content}
[Endnote 1] {content}
```

Footnotes are numbered by their order in `PhpWord::getFootnotes()->getItems()`. Endnotes likewise. The numbering MAY not match the inline reference numbers in the body (PhpWord doesn't expose the rendered footnote-mark numbering); operators read the markers as "first / second / third" not "the one labelled 4". Acceptable trade-off.

**Rationale:** headers carry case identifiers and dossier references that contextually precede the body; footers carry handler names and page chrome that contextually follow. Reading order matches the reader's eye over a printed page.

**Trade-off:** entities in headers that are detected via context ("Aanvraag van [PERSON]") have their context "Aanvraag van " emitted right before the entity name — preserves context. Same for body and footer. Footnotes lose body-position context (their content is collected at the end, not inline); detection may produce slightly worse confidence for footnote entities. Acceptable; footnote entities are rare and the alternative (inline emission) requires PhpWord to expose footnote-reference position which it does not.

### D4. ODT integration via PhpWord's ODText reader / writer

PhpWord 1.3 ships `\PhpOffice\PhpWord\Reader\ODText` and `\PhpOffice\PhpWord\Writer\ODText`. Both implement the same in-memory model interface that `Word2007` reader / writer use. The walker is format-agnostic; format selection happens only at:

1. **Load**: `IOFactory::load($path)` auto-detects based on file extension AND content sniffing. We accept the default behaviour.
2. **Save**: explicit `IOFactory::createWriter($phpWord, 'ODText')` for `.odt` outputs; `'Word2007'` for `.docx`. The MIME-derived selection is straightforward:
   - `application/vnd.openxmlformats-officedocument.wordprocessingml.document` → `Word2007`
   - `application/vnd.oasis.opendocument.text` → `ODText`

`DocumentProcessingHandler::anonymizeDocument` performs this selection. Outputs always preserve the input format — DOCX in / DOCX out, ODT in / ODT out.

**Edge case:** PhpWord's ODText writer is known to silently drop formatting features it doesn't model (e.g. complex frames, some custom styles). The combination of sanitiser-first + writer-back means the output ODT may have slightly different formatting from the input. Acceptable for the anonymisation use case (the output is a frozen artefact, not a source-of-truth template). Documented as a known trade-off.

**Validation:** the manual gate (per office-document-sanitization D10) applies here too — the anonymised ODT MUST open cleanly in LibreOffice and Word.

### D5. Walker mutation semantics

`replace($phpWord, $substitutions)` walks the document and, at each text-leaf element, calls:

```php
$originalText = $element->getText();
$newText = strtr($originalText, $substitutions);
if ($newText !== $originalText) {
    $element->setText($newText);
}
```

`strtr` with the full substitution array does longest-match-first replacement and is safe for overlapping patterns (e.g. "Jan Jansen" and "Jan" — strtr picks the longer match correctly).

**Edge case:** `Text` elements expose `setText()`. `Link` elements expose `getText()` but not always `setText()` (depending on PhpWord version — 1.3 added it). The walker checks `method_exists($element, 'setText')` and skips elements that can't be mutated, with a debug log. By the time the walker runs in production, hyperlinks have been flattened by the sanitiser, so `Link` elements should not appear.

**Edge case:** a substitution that splits a text run. PhpWord's text model is run-based; a single visible "Jan Jansen" may be split across two `Text` elements (e.g. "Jan " in one run, "Jansen" in another) if Word's underlying XML happened to break it across `<w:r>` boundaries. The walker visits each element separately; the substitution misses the cross-run match. Mitigation: detection-side substitution-map preparation MAY concatenate adjacent run text BEFORE detection (out of scope for this change — see grondslagen-summary work for context). The walker as designed handles per-element matches correctly; cross-run matches are an upstream concern.

### D6. ADR-005 logging compliance

Walker MUST NOT log any visited text content. Permitted log content: file ID, MIME type, counts (paragraphs visited, tables visited, footnotes visited, substitutions applied), and structural error detail.

When the walker encounters an unrecognised element type, the log entry MAY include the class name (e.g. `PhpOffice\PhpWord\Element\OLEObject`) but MUST NOT include any text content from that element (even if it happens to expose `getText()`).

### D7. Backwards compatibility for extraction consumers

Existing consumers of `TextExtractionService::extractWord` (entity detection, the grondslagen-summary template, ad-hoc full-text-search) receive a longer string after this change. The structural shape (single flat string) is unchanged. The new section markers are inline plain text; they don't break any existing consumer.

For consumers that depend on the EXACT pre-change extraction output (regression test snapshots, etc.), the change DOES update those snapshots — they are part of this PR. A grep for `extractWord` / `extractFile` snapshots in `tests/` is a required step before claiming task done.

### D8. Re-extraction is opt-in via `forceReExtract`

Existing extracted-text records for DOCX files have less content than what the new walker produces. The change does not auto-re-extract — operators trigger re-extraction explicitly via the existing `forceReExtract: true` parameter on `extractFile`. CHANGELOG documents this so tenants who notice "my detection is missing entities in headers" know how to refresh.

For ODT files: pre-change extracted-text records are null (extraction was unsupported). Any tenant who tried to extract ODT before saw an "unsupported" failure — there are no stale ODT records to refresh.

### D9. `extractWord` rename to `extractOfficeDocument` (with alias)

The method name `extractWord` is misleading once ODT is supported. Rename to `extractOfficeDocument`. Keep `extractWord` as a thin forwarder for one release cycle, marked `@deprecated`. Internal callers update immediately; external callers (if any — unlikely since this is a private-ish service) see no breakage.

Public API surface: the cascade dispatch in `extractFile` is internal; `extractWord` was already private. The rename is purely internal hygiene; no external observability.

### D10. Test fixture strategy

Real `.docx` and `.odt` files in `tests/Fixtures/office-completeness/`. Crafting:

- A `complete.docx` containing: 2 sections, each with distinct default / first-page / even-page headers and footers; body with paragraphs, a 2-level-nested table (table inside cell of outer table), a bulleted list with 5 items, an inline text frame, 3 footnotes, 2 endnotes. Generated once via a PHPUnit fixture-builder that uses PhpWord to construct the doc programmatically; checked in.
- A `complete.odt` produced by saving the same in-memory model via `Writer\ODText`. Confirms parity.
- An `edge-cases.docx` containing: empty section, table with merged cells, list with sub-list, header with embedded hyperlink-display-text (pre-sanitiser state), TextBox containing a table.

Fixture build script lives in `tests/Fixtures/office-completeness/build-fixtures.php` so future contributors can regenerate.

## Risks / Trade-offs

- **[PhpWord ODText writer formatting loss]** → Mitigation per D4; accepted as a known trade-off for the anonymisation use case. Validation gate ensures the output opens cleanly.
- **[Cross-run substitution gaps]** → Per D5 edge case. Upstream concern; not addressed in this change. Documented.
- **[Section markers appear in extracted text]** → Per D7 and D3. Acceptable; section markers improve detection context for downstream NER. If a consumer needs a markerless variant, a v1.1 toggle adds it.
- **[Large documents — memory pressure on PhpWord load]** → No new pressure beyond PhpWord's existing behaviour. Walker adds O(1) memory overhead. For 50MB+ documents, PhpWord itself may be the limit; out of scope here.
- **[Detection coverage regression for non-Office MIMEs]** → The change is narrowly scoped to `extractWord` rename and ODT addition. PDF / EML / spreadsheet branches are NOT touched. Verified by integration test fixture for each non-Office MIME pre/post change.
- **[ODT-side anonymisation breaks downstream "view this anonymised file" feature]** → Both anonymised DOCX and anonymised ODT need to be viewable in Nextcloud Files. Nextcloud's Collabora / OnlyOffice integration handles both natively. No additional integration work.
- **[Existing test snapshots fail]** → Per D7. Snapshots updated as part of this PR. CI fails until updated. Required step in tasks.md.
- **[Footnote inline-position loss]** → Per D3 trade-off. Acceptable.

## Migration Plan

1. Land `OfficeDocumentWalker` class with both `extractText` and `replace` methods + unit tests.
2. Refactor `TextExtractionService::extractWord` (rename to `extractOfficeDocument`, internal callers updated) and add the ODT branch in the cascade.
3. Refactor `DocumentProcessingHandler::replaceWords` to delegate; add the ODT writer-back path; restrict the raw-string-replace dispatch to plain-text MIMEs only.
4. Update existing test snapshots reflecting the new richer DOCX extraction output.
5. Land integration tests for ODT extraction + anonymisation round-trip.
6. Land the manual validation gate (Word + LibreOffice round-trip on the fixture documents).
7. Release. Tenants with stale DOCX extraction can refresh via `forceReExtract`.

**Rollback:** revert the `TextExtractionService` and `DocumentProcessingHandler` wiring changes. Walker class becomes unused. Test snapshots revert. Clean per-commit revert.

## Seed Data

Not applicable — this change adds service code and refactors existing paths. No new OpenRegister schemas; no `_registers.json` entries required.

## Open Questions

- **Section-marker text format (`[Section 1 — Header default]` vs `=== Section 1 (header, default) ===` vs Markdown headings)** — provisional: `[Section N — <role> <variant>]`. Bracketed-prefix style matches the existing EML extraction's attachment marker style (`--- Attachment: ... ---`) for tonal consistency.
- **Footnote ordering in extracted output — preserve document order via reference numbering or use PhpWord's `getItems()` order?** — provisional: `getItems()` order, with the marker number reflecting that ordering (not the rendered footnote number, which PhpWord doesn't expose). Confirmed acceptable trade-off in D3.
- **`extractWord` deprecation window** — provisional one release cycle; remove the forwarder in the change after the apply lands. If consumers complain, extend to two cycles.
- **PhpWord version sensitivity (1.3.x vs newer)** — provisional: pinned to `^1.3`. Newer PhpWord versions may add ODText writer fidelity improvements; consider upgrade in a separate change.
- **Should the walker emit footnotes inline (at the reference position in the body) rather than at the document tail?** — provisional: end-of-document per D3 trade-off. If detection regresses on footnote entities, consider inline emission as a follow-up (requires bypassing PhpWord's abstraction).
