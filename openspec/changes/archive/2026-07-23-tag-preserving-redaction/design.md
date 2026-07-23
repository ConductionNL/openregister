## Context

Verified at OpenRegister HEAD (`development` @ 12a52c7fc):

- **PDF manipulation library:** `ddn/sapp` — the Conduction fork of
  dealfonso/sapp ("Simple and Agnostic PDF Parser"), pinned to
  `dev-feat/chained-filter-text-replace` from `codeberg.org/Conduction/sapp`
  (composer.json:99, composer.lock:308). Pure PHP, operates on raw PDF objects
  and content streams. `PDFDoc::replace_text_in_document()` byte-replaces the
  text shown by `Tj`/`TJ` operators; `to_pdf_file_s(rebuild: true)`
  reserialises the object graph.
- **Other PDF libs:** `smalot/pdfparser ^2.9` is **read-only** text extraction
  (used for the text-layer probe and residual validation); `dompdf/dompdf ^3.1`
  is HTML→PDF export. Neither writes structure.
- **The redaction path:** `DocumentProcessingHandler::anonymizeDocument`
  (:318) → `replaceWords` (:262) → `replaceWordsInPdfDocument` (:1227) →
  `PdfTextReplacer::replaceInPdf` (:114) → `PdfMetadataSanitizer::sanitize`
  (:1341). Result surfaced via `getLastResidualEntities()` /
  `getLastSanitizationReport()` (:183-216) and the `anonymizeFile` HTTP
  response (`FileTextController.php:574`).

**The hard constraint:** SAPP has no concept of the logical structure tree.
There is no `/StructTreeRoot`, `/MarkInfo`, marked-content (`BDC`/`EMC` +
`/MCID`), `/Alt`, `/ActualText`, `/RoleMap` or reading-order handling anywhere
in the fork. Text replacement rewrites content-stream bytes and changes the
number and composition of glyphs behind each `MCID`; even when the
`/StructTreeRoot` object survives the rebuild, the tag→content correspondence
it encodes is stale. **This engine cannot re-author or repair a structure
tree.** Any spec that promised structure *repair* on this stack would be
speccing magic. This change therefore scopes to what the stack *can* honestly
do: detect, measure, best-effort pass-through, and truthful explicit
degradation.

Stakeholders: OpenRegister redaction engine (owner); DocuDesk accessible-
redaction leaf (consumer of the `structurePreservation` contract); EN 301 549 /
EAA accessibility obligations.

## Goals / Non-Goals

**Goals:**

- Detect whether a redaction input is a tagged PDF, and measure its structure-
  element count before and after redaction — over the SAPP object model, in a
  unit-testable seam.
- Preserve the structure-tree object graph through the SAPP rebuild when the
  engine can, and when it cannot, **degrade explicitly** with machine-readable
  loss reasons — never a silent flatten.
- Expose a truthful `structurePreservation` result block (exact field names
  below) on the processing result and the HTTP seam for the DocuDesk leaf.
- Keep untagged-PDF redaction output **byte-stable** with today's behaviour.

**Non-Goals:**

- **No structure-tree repair / re-authoring / MCID re-mapping** — impossible on
  the pure-PHP stack (see D1). Recorded as a deferred alternative.
- No change to the byte-replace algorithm, the residual validation gate, or
  metadata sanitisation (consumed unchanged).
- No DOCX/ODT structure preservation (the Office sanitiser path is out of
  scope; this change is PDF-only, matching where taggedness lives).
- No OCR/image-only handling (already deferred to `ocr-document-scanning`).
- No operator UI — that is the DocuDesk leaf's half; this change ships the
  engine contract it consumes.

## Decisions

### D1 — The current stack cannot re-author a structure tree; scope to detect + pass-through + honest degradation

`ddn/sapp` exposes a parsed object model but no structure-tree API. The
achievable v1 posture:

1. **Detect** taggedness: the document Catalog carries `/StructTreeRoot N G R`
   and/or `/MarkInfo << /Marked true >>`.
2. **Measure** structure elements: count parsed objects whose dictionary has
   `/Type /StructElem`. (A raw byte scan is unreliable because PDF 1.5+ packs
   objects into compressed `/ObjStm` streams; SAPP has already decompressed and
   parsed them, so counting over the object model is the honest measurement.)
3. **Pass-through** on rebuild: when `preserveStructure` is active, ensure the
   `/StructTreeRoot`, `/MarkInfo`, `/RoleMap` and document `/Lang` objects
   referenced from the Catalog survive `to_pdf_file_s(rebuild: true)` and the
   subsequent `PdfMetadataSanitizer` pass (the sanitiser strips `/Info` + XMP
   only, but MUST NOT drop the struct tree — asserted by test).
4. **Degrade explicitly** otherwise: the engine records `preserved: false` with
   `lossReasons[]` and returns the redacted bytes anyway (best-effort parity
   with the existing residual policy — the file is produced, the loss is
   reported, the operator/leaf decides).

**"Preserved" is claimed conservatively.** Because MCIDs cannot be re-mapped by
this engine, `preserved: true` is asserted **only** when *all* hold:
`tagCountAfter === tagCountBefore` (> 0), the `/StructTreeRoot` survived the
rebuild, and no structured page's marked-content operator count was altered by
the replacement. In the common case where the redaction mutates marked content
on a tagged page, the tree is passed through but the correspondence is stale, so
the engine reports `preserved: false` with
`marked-content-correspondence-broken` — it does not claim a preservation it
cannot guarantee. This is the crux of "do not spec magic": the engine tells the
truth about a degraded result rather than presenting a pass-through as a
faithful preservation.

*Alternative considered — out-of-process structure-aware repair* (e.g. `qpdf`,
or an iText/pikepdf-class tool): would enable genuine tag-tree preservation and
MCID re-mapping, but adds an external binary/service dependency outside the
pure-PHP stack, a new trust/security surface, and packaging work (ADR-017
territory). **Rejected for v1**, recorded in Open Questions as the path to true
preservation. This change's contract is forward-compatible: a future repair
engine simply raises the rate of `preserved: true` without changing the result
shape.

### D2 — The `structurePreservation` result contract (field names are contractual)

Every PDF redaction produces exactly this block (consumed verbatim by the
DocuDesk leaf — field names MUST NOT drift):

```json
{
  "structurePreservation": {
    "requested": true,
    "preserved": false,
    "tagCountBefore": 42,
    "tagCountAfter": 42,
    "lossReasons": ["marked-content-correspondence-broken"]
  }
}
```

- `requested` (bool) — whether preservation was in effect for this run
  (resolved `preserveStructure` option; see D3).
- `preserved` (bool) — whether the engine can attest the tag tree survived
  faithfully (the conservative rule in D1). `false` whenever `requested` was
  true but the guarantee could not be met, AND whenever preservation was not
  applicable (untagged input) — a non-tagged document was not "preserved".
- `tagCountBefore` (int) — `/StructElem` count of the input (0 for untagged).
- `tagCountAfter` (int) — `/StructElem` count of the output.
- `lossReasons` (string[]) — machine-readable reasons, empty when
  `preserved` is true. Enumerated set (extensible):
  `engine-cannot-reauthor-structtree`,
  `marked-content-correspondence-broken`,
  `structtreeroot-dropped-on-rebuild`,
  `input-not-tagged` (preservation requested but not applicable),
  `page-structure-not-preservable`.

`getLastStructurePreservation(): ?StructurePreservation` mirrors the existing
`getLastResidualEntities()` / `getLastSanitizationReport()` accessor pattern
(:183-216). The block is emitted for PDF redaction only; for DOCX/ODT/text
paths the accessor returns null (no PDF structure tree involved) and the HTTP
response omits the block.

### D3 — `preserveStructure` option resolution (default: preserve when tagged)

A tri-state `?bool $preserveStructure` threaded through
`anonymizeDocument` / `replaceWords` and read from the `anonymizeFile` HTTP
param:

- `null` / absent → **auto**: preserve iff the input is tagged
  (`tagCountBefore > 0`). This is the default.
- `true` → attempt preservation even if detection is ambiguous; still degrade
  explicitly if impossible (never fails the redaction on this alone in v1 —
  best-effort parity).
- `false` → skip preservation entirely; `requested` is false, `preserved`
  false, `lossReasons` empty, tag counts still measured for the truthful
  report.

Rationale for best-effort (produce-and-report) over fail-closed: matches the
existing residual policy (:1294 "no longer fails closed on residual entity
text"), and the DocuDesk leaf owns the block/warn decision. A future
`preserveStructure: 'strict'` that refuses to emit a flattened derivative is an
Open Question, not v1.

### D4 — Unit-test seams

- `PdfStructureInspector::inspect(PDFDoc $doc): array` (or `->isTagged()` +
  `->countStructElements()`) — pure over the SAPP object model; unit-tested with
  a tagged fixture (StructTreeRoot + MarkInfo + N StructElem) and an untagged
  fixture (`tagCountBefore === 0`, `isTagged === false`). No live NC instance.
- `PdfTextReplacer::replaceInPdf(..., ?bool $preserveStructure, StructurePreservation &$structureResult)` —
  unit-tested for: tagged input preserved (counts equal, empty lossReasons only
  when the conservative rule holds); tagged input with mutated marked content
  → `preserved:false` + `marked-content-correspondence-broken`; untagged input
  → `tagCountBefore:0`, `lossReasons:[input-not-tagged]` when requested; and the
  **byte-stability guard** (untagged redaction output identical to the
  `preserveStructure:false` / pre-change output).
- `StructurePreservation` value object with `jsonSerialize()` — asserts the
  exact field set of D2.

### D5 — ADR / discipline checks

- ADR-005 (PII-free logs): tag counts and loss reasons are structural, never
  PII — safe to log; the residual-text carve-out is unchanged.
- ADR-022 (consume-not-rebuild): SAPP, smalot, `PdfMetadataSanitizer` and the
  residual policy are consumed unchanged; this change adds a measurement seam
  and a result block, not a parallel PDF engine.
- Auth: no new route; `anonymizeFile`'s existing file-access gate
  (`FileTextController.php:105`) is unchanged. The new param is read inside the
  already-gated method.

## Risks / Trade-offs

- **[`preserved:false` will be the common outcome on tagged PDFs]** — accepted
  and by design: an honest "we degraded this and here is why" beats a silent
  flatten. The value is the truthful signal; raising the `true` rate needs the
  D1 repair engine (deferred).
- **[Pass-through struct tree with stale MCIDs is not a *valid* PDF/UA tree]** —
  true; that is exactly why `preserved` is reported `false` in that case. The
  block never asserts validity it cannot back.
- **[SAPP rebuild might drop the StructTreeRoot entirely]** — detected by
  `tagCountAfter < tagCountBefore` / missing root → `structtreeroot-dropped-on-rebuild`;
  reported, not hidden. A test pins this.
- **[Tag counting over the object model is approximate]** (nested StructElem in
  object streams, cross-reference streams) — acceptable: the count is a
  before/after *comparison* signal, not an absolute audit; equal-count is a
  necessary-not-sufficient input to the conservative `preserved` rule.
- **[Extra parse to measure adds latency]** — the document is already parsed by
  SAPP on the redaction path; inspection reuses that object model, no second
  load.

## Migration Plan

Additive and back-compatible: new `?bool $preserveStructure` params default to
`null` (auto) so every existing caller is unaffected; the new result block is
additive on the accessor and the JSON response. Untagged PDFs are byte-stable
(REQ-ORTPR-005). No schema, register or route changes. Rollback = drop the new
param/accessor/block; the redaction pipeline is otherwise untouched.

## Open Questions

- **True structure preservation** needs an out-of-process structure-aware tool
  (qpdf / pikepdf / iText class) — ADR-017-shaped packaging decision; deferred.
  The `structurePreservation` contract is forward-compatible with it.
- **`preserveStructure: 'strict'`** (refuse to emit a flattened derivative,
  fail-closed) — deferred; v1 is best-effort produce-and-report, matching the
  residual policy. The DocuDesk leaf can enforce a block on `preserved:false`
  in the meantime.
- **DOCX/ODT accessibility structure** (headings, alt text in the OOXML/ODF
  package) — a separate seam on the Office sanitiser path; out of scope here.
- **Per-page loss granularity** — v1 reports document-level `lossReasons`; a
  future `lossPages: int[]` could localise the degradation.
