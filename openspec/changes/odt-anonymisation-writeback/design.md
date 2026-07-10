## Context

OpenRegister's anonymisation engine lives in `DocumentProcessingHandler`. `replaceWords()` (`lib/Service/File/DocumentProcessingHandler.php:234`) builds a `needle → [<TYPE>: <id>]` replacement map and dispatches by file extension:

- `doc` / `docx` → `replaceWordsInWordDocument()` — PhpWord object-model roundtrip.
- `pdf` → `replaceWordsInPdfDocument()` — SAPP byte-level pipeline with a residual gate.
- ELSE → `replaceWordsInTextDocument()` — `str_ireplace` over `$node->getContent()`.

An `.odt` (`application/vnd.oasis.opendocument.text`) had no branch, so it hit the ELSE branch. An ODT is a ZIP container whose text lives (normally deflated) inside `content.xml`; running `str_ireplace` over the transport bytes matches nothing in the compressed case (byte-identical output → **silent PII leak reported as success**) or breaks the ZIP length/CRC in the stored case (**corrupt file**). This is the same defect class the `pdf-anonymisation` and `anonymise-eml-structured` changes fixed for their formats: redact the DECODED content, not the container bytes.

### Why not the PhpWord object-model roundtrip (the abandoned Strategy A)

The first plan (Strategy A) was to route ODT through the existing `replaceWordsInWordDocument()`, swapping the writer to `ODText`. Implementation disproved it. **PhpWord's ODText *reader* silently drops tables, headers, and footers on load** (empirically: a PhpWord-written ODT with a body paragraph, a table, a header and a footer re-reads as one section with a single body paragraph — `body elems=1`, `headers=0 footers=0`). The writer is fine; the reader is the limitation. So an object-model roundtrip would read only the body, write back only the body, and **delete every table/header/footer** — and because the validation gate would re-read through the *same* lossy reader, PII-bearing tables would be destroyed while the run reported "clean". That fails the acceptance bar ("tables structurally intact + headers/footers preserved") and is arguably worse than the original bug. Strategy A is therefore viable only for body-text-only ODTs and is rejected.

`phpoffice/phpword ^1.2` is vendored (used elsewhere), but this change does **not** rely on its ODT reader/writer. No dependency is added — the ODF XML is edited with PHP's built-in `ZipArchive` + `DOMDocument`.

## Goals / Non-Goals

**Goals:**

- Route `.odt` to a dedicated in-place XML path (`replaceWordsInOdtDocument`), away from both the raw-text fallback and the lossy PhpWord ODText reader.
- Redact by rewriting the ODF parts that carry visible text — `content.xml` (body, tables, lists) and `styles.xml` (page headers/footers) — preserving all structure, formatting, and every other ZIP entry.
- Handle entities split across `<text:span>` runs within a paragraph.
- Add a post-write validation gate that re-extracts the written ODF parts and asserts every entity's original text is absent; on any survivor (or an unverifiable container) record residuals via the existing best-effort policy — never emit an unredacted/corrupt file reported as success.
- Ship the real ODT writeback AND close the silent-leak hole in one change (the guard is inherent in the validation gate + routing).

**Non-Goals:**

- The PhpWord object-model roundtrip (Strategy A) — rejected above.
- NC Office (Collabora) ODT roundtrip (Strategy C). Not this change; would add a hard `richdocuments` dependency.
- The DocuDesk frontend enablement (`odt-anonymisation-frontend`: upload-widget allowlist, copy, i18n). Separate paired change.
- Re-implementing ODT extraction/detection — owned by `text-extraction-word-completeness` (unchanged).
- New entity detection, HTTP endpoint, DB column, register/schema/object, or external dependency.
- ODT preview in the in-viewer file viewer (a DocuDesk nicety, tracked separately).

## Decisions

### D1 — Strategy B: in-place ODF XML surgery (ZipArchive + DOMDocument)

`replaceWordsInOdtDocument()` writes the ODT bytes to a temp file, opens it with `ZipArchive`, and rewrites `content.xml` and `styles.xml` via `replaceTextInOdfXml()`. Every other entry (mimetype, images, settings) is left byte-untouched, so structure and formatting are preserved exactly. This never uses the lossy ODText reader and adds no dependency (built-in `ZipArchive` + `DOMDocument`).

*Alternatives considered:* **Strategy A** (PhpWord object-model roundtrip) — rejected: the ODText reader drops tables/headers/footers (see Context). **Strategy C** (Collabora roundtrip via `IConversionManager`) — highest fidelity but adds a hard dependency on `richdocuments`; deferred. Both are documented in the discovery.

### D2 — Route `odt` to its own branch in `replaceWords()`

Add an `odt` branch dispatching to `replaceWordsInOdtDocument()` (`:234`) BEFORE the `['doc','docx']` branch. This stops ODT reaching both `replaceWordsInTextDocument()` (raw-byte fallback) and `replaceWordsInWordDocument()` (lossy reader). DOC/DOCX behaviour is unchanged.

### D3 — Paragraph-scoped, span-aware text-node rewriting

`replaceTextInOdfXml()` parses the part with `DOMDocument` and processes each paragraph container (`text:p`, `text:h`) independently. Per paragraph it concatenates the descendant text nodes (with a byte→node ownership map), computes non-overlapping replacement ranges over the concatenation (longest needle first; case-insensitive to mirror the `str_ireplace` semantics used on the DOCX/text paths), then rebuilds each text node so a matched entity — even one split across multiple `<text:span>` runs — becomes its placeholder exactly once, with the covered bytes removed from the other runs. Non-text elements (`<text:s/>`, `<text:tab/>`, `<text:line-break/>`), attributes, and markup are untouched.

*Known limitation:* an entity broken by a non-text element (e.g. a `<text:s/>` space element) between runs is not concatenated and so is not matched — the same class of limitation the object-model path has; the validation gate (D4) catches any such residual.

### D4 — Post-write validation gate (fail loud)

After the parts are rewritten, `recordOdtResidualEntities()` re-opens the written ODT and re-extracts the concatenated paragraph text of `content.xml` + `styles.xml` with `extractOdfConcatenatedText()` — the SAME within-paragraph concatenation the replacement used, so the gate is consistent with the rewrite (including the split-run case). It asserts each entity's original text is absent. Any survivor — or an unreadable container / missing `content.xml`, meaning redaction cannot be proven — is recorded via the existing best-effort residuals policy (`getLastResidualEntities()`, `{text,type,id}` record shape and `[<TYPE>: <id>]` placeholder parsing shared with the PDF path), so DocuDesk can warn the operator. The file is still written (best-effort). The gate is the single choke point that makes the change fail loud: there is no path where an unredacted or corrupt ODT is returned as a clean success. *Alternative considered:* trust the writer without re-reading — rejected: that is exactly the silent-success assumption that produced the original leak.

### D5 — No rename of `replaceWordsInWordDocument`

Because ODT now has its own method (`replaceWordsInOdtDocument`), `replaceWordsInWordDocument` keeps its accurate name — the discovery's optional rename is unnecessary and is skipped to keep the diff tight.

## Declarative-vs-imperative (ADR-031)

**Justified-imperative — N/A to the declarative trigger list.** ADR-031 governs schema-level business logic: lifecycle hooks, aggregations, derived fields, declarative relations, notifications, and dashboard widgets configured as OpenRegister data. This change introduces NONE of those. It adds document parsing and in-place redaction over a binary office format (ZIP/XML surgery + a re-extraction validation gate) — inherently imperative document-processing / redaction concerns. No register, schema, or object is created or modified, so **no seed data is needed and none must be generated by the apply agent.** ADR-031's declarative path does not apply.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Entity text split by a non-text ODF element (`<text:s/>`, tab, line-break) is not concatenated → not matched | Same class of limitation as the object-model path; the validation gate records the residual and the best-effort residuals list warns the operator (fail loud, never silent). |
| `DOMDocument` re-serialisation alters incidental whitespace/attribute quoting | `preserveWhiteSpace=true`, `formatOutput=false`; only text-node values are changed; unit tests assert structure (spans, table, surrounding text) is preserved and the output re-opens as a valid ODT. |
| `ZipArchive` edit disturbs the required first/stored `mimetype` entry | Only `content.xml`/`styles.xml` entries are replaced; the `mimetype` entry is untouched. Tests assert the output is a valid ODT (ZIP + OpenDocument mimetype) and re-reads. |
| Silent leak reappears if the gate is bypassed on error | The gate is the single choke point; a write that cannot be re-read, is missing `content.xml`, or still contains entity text is reported via residuals, never returned as clean success. |
| PhpWord ODText reader still drops tables/headers/footers (unchanged upstream) | This change deliberately does NOT use that reader for writeback; extraction/detection quality is owned by `text-extraction-word-completeness`. |

## Migration Plan

No DB migration; deploy is code-only. Behavioural change for `.odt` inputs: they were silently leaking or corrupting; they now produce a redacted `.odt` with structure preserved (or a fail-loud residual report). Document under CHANGELOG "Behavior changes". Rollback is reverting the code; no data migration to undo. The paired DocuDesk change `odt-anonymisation-frontend` ships separately and should land after this backend fix so users are not routed to the bug before it is closed.

## Open Questions

- Fidelity on real government ODTs (discovery Q2): the design bar is "structure preserved, entity text removed". Strategy B preserves structure by construction; the remaining risk is the split-by-non-text-element case (mitigated by the fail-loud gate). Representative real-world ODT fidelity measurement is deferred to QA on a live stack.
- Whether a future high-fidelity/positional-anonymisation change extends this XML path (e.g. handling `<text:s/>`-split entities) or moves to Collabora (Strategy C).
