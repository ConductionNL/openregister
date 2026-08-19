## Why

`feat-cross-bt-et-matching` (Phases 1–3) matches needles across operators and across `BT/ET` blocks **within one logical line** (same Y). A needle whose text wraps across a visual line break is still never found: the dominant shape is a date or name split by the producer's line-breaking, e.g. fictieve_brief_anonimisering.pdf:

```
BT /F2 11 Tf 1 0 0 1 90.02 442.63 Tm [(…intakegesprek dat op 14 mei )] TJ ET
BT /F2 11 Tf 1 0 0 1 90.02 430.15 Tm [(2026 heeft plaatsgevonden…)] TJ ET
```

The needle `14 mei 2026` spans the two blocks. Phase 3's logical-line grouper requires same-Y and so never pairs them; the entity text survives anonymisation. OpenRegister's strict validation gate (whitespace-normalised re-extraction) correctly detects the residual and fails the document closed — so wrapped entities currently make anonymisation impossible for affected documents.

## What Changes

- Introduce **Phase 4: cross-line matching** — `applyCrossLineReplacements`, wired after the Phase 3 pass in `replaceInContentStream`.
- Introduce a **line-pair grouping** rule (`groupBlocksIntoLinePairs`): same font name + size, identity axes, vertically adjacent with a gap in `(0.5, 2.0 × font_size]` (one leading step — excludes paragraph breaks), left margins within `1.5 × font_size` (excludes multi-column joins). When a visual line has several blocks, the pair is `(last block of top line, first block of bottom line)` — the only blocks a wrapped needle can touch.
- Match across the pair boundary treating the wrap as whitespace: direct concatenation when the top line keeps its trailing space (Word's shape); otherwise a **synthetic space** is inserted into the match view and a match is only accepted when the needle has a literal space at that position. The synthetic char lies strictly between the two ops, so the splice machinery never maps it to bytes.
- **Claimed-range tracking** per pair: overlapping needles (`4 mei 2026` inside `14 mei 2026`) must not both build edits over the same byte range — first*-claimed wins (callers order longest-first), later overlaps are skipped without bumping counters.
- Reuse `buildCrossBlockEdit` with a new `$preserveInterBlockBytes` flag: cross-line edits span whole-line distances where the `ET…BT` gap can carry graphics state (colour ops, clip paths) — re-emit it verbatim. Phase 3 call sites keep the historical drop behaviour.
- Diagnostic counters added: `cross_line_pairs_built`, `cross_line_matches`, `cross_line_skipped_multi_op_block`.

## v1 Restrictions (mirror Phase 3)

- Match must start in the LAST operator of the top block and end in the FIRST operator of the bottom block.
- Pair must be stream-ordered (`bottom.bt_offset > top.et_offset`).
- Needles spanning three or more lines are out of scope.

## Impact

- Affected: `src/PDFDoc.php` (`replaceInContentStream`, `buildCrossBlockEdit` signature, two new private methods).
- Consumers: OpenRegister `PdfTextReplacer` — wrapped entities now match; strict validation passes for documents whose only residuals were line-wrapped entities.
- No behaviour change for documents without cross-line needles (pass is a no-op when no pair matches).
