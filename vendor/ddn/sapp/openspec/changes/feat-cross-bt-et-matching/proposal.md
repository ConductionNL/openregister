## Why

`replace_text_in_document` matches each `Tj`/`TJ` operand independently against the substitution map. A needle whose text spans multiple operators — either across `TJ` arrays inside one `BT/ET`, or across separate `BT/ET` text objects — is never found. After `feat-tj-flattening` (PR #07) the intra-`TJ`-array case is solved; the multi-operator cases are the next dominant failure mode.

Two real-world shapes hit by Conduction's anonymisation pipeline:

1. **Word table cells** — every character (or short syllable) in its own `BT/ET` with an absolute `Tm`, e.g.
   ```
   BT /F3 11 Tf 1 0 0 1 227.57 614.98 Tm [(K)] TJ ET
   BT /F3 11 Tf 1 0 0 1 233.33 614.98 Tm [(e)] TJ ET
   ...
   ```
   Each Tj operand resolves to a single char; the needle "Kees" never matches because the matcher sees one fragment at a time.

2. **Tagged/accessible PDFs** — Acrobat/Word splits text into structure-tree spans (`/Span <</MCID N>>`), each in its own `BT/ET` at the same Y but different X, e.g.
   ```
   BT /F2 11 Tf 1 0 0 1 90.05 691.2 Tm [(via d.smits)] TJ ET
   BT /F2 11 Tf 1 0 0 1 142.05 691.2 Tm [(@amsterdam.nl.)] TJ ET
   ```
   The needle "d.smits@amsterdam.nl" spans two BT/ETs and stays unredacted. Dutch government letters are routinely tagged this way (WCAG/accessibility).

Both are spec-conforming PDF shapes Word and Acrobat emit by default. The current per-operator matcher cannot reach them.

## What Changes

- Introduce a content-stream **parse phase** that produces a structured model of `BT/ET` blocks: each block records its byte offsets, effective `Tm`, active `Tf`, and the list of `Tj`/`TJ` operators inside (with operand byte offsets, parsed entries, and resolved Unicode text). The existing `parseTjArrayContent` + `resolveOperandToUnicode` helpers are reused; no overlap.
- Introduce a **logical-line grouping** rule: BT/ET blocks with the same Y (in `Tm.f`, ε=0.5), same `Tf` font resource name, identity-axes (`a,b,c,d`), and monotonic X form one logical line.
- Introduce a **cross-operator splice** algorithm that, given a needle match spanning ops `S..E` (possibly across blocks `Bs..Be`):
  - truncates operator `S` to its prefix (bytes before match start)
  - drops fully-consumed operators between
  - truncates operator `E` to its suffix (bytes after match end)
  - emits the placeholder inline at the end of block `Bs` using the existing Helvetica fallback Tf-restore shape
- Diagnostic counters added: `logical_lines_built`, `cross_operator_matches` (matches that spanned >1 operator), `cross_block_matches` (matches that spanned >1 BT/ET), `cross_block_skipped_font_mismatch`, `cross_block_skipped_y_mismatch`, `cross_block_skipped_x_overlap`.

The work lands in three commits to keep review tractable:

1. **Phase 1 (this change's first commit) — parse phase, no behaviour change.** Add `parseContentStreamModel` (private). Does not alter matching. Foundation only.
2. **Phase 2 — intra-block cross-operator matching.** Wire the parsed model into needle matching; splice across multiple `Tj`/`TJ` operators within one `BT/ET`.
3. **Phase 3 — cross-`BT/ET` matching within a logical line.** Group blocks; splice across block boundaries.

## Capabilities

### New Capabilities

- `cross-bt-et-matching` — match and replace needles whose text spans multiple `Tj`/`TJ` operators (within or across `BT/ET` text objects) when those operators form one logical visual line.

### Modified Capabilities

- `text-replacement` — replace the per-operator linear matcher in `replaceInContentStream` with a parsed-model-driven matcher. Public API unchanged. Diagnostic surface gains the counters above.

## Impact

- **Touched files**: `src/PDFDoc.php` (parse phase + new matcher + splice algorithm, ~250 LOC net across the three phases).
- **Public API**: `replace_text_in_document` shape unchanged. Stats schema extended additively.
- **Depends on**: `feat-tounicode-cmap`, `feat-tj-flattening`, `feat-helvetica-fallback`.
- **Unblocks**: Word table-cell positioning, tagged/accessible PDF redaction.
- **Risks**: the logical-line heuristic (X-gap threshold, Y tolerance) may join unrelated text in multi-column layouts; mitigated by per-line diagnostic counters and a conservative default threshold (8× font size). False joins surface as visible misplacements or as needle false-positives.