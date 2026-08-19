## 1. Pre-flight

- [x] 1.1 Branch off `work/text-replacement` as `fix/rebuild-strip-prev-classic-xref` (existing branch carries earlier related fixes)
- [x] 1.2 Capture failure shapes from two fixtures:
  - Notulen20191102.pdf (table cells: character-per-`BT/ET`)
  - fictieve_brief_anonimisering_3.pdf (tagged-PDF: span-per-`BT/ET`)

## 2. Phase 1 — parse phase (foundation, no behaviour change)

- [ ] 2.1 Add `parseContentStreamModel($stream, array $pageFonts): array` (private) in `PDFDoc.php`
- [ ] 2.2 Walk the decoded content stream using `findNextOperator` (string-state-aware); identify every `BT`/`ET` pair
- [ ] 2.3 Track active `Tf` (resource name + size) across blocks; the Tf state is part of graphics state and carries between `BT/ET`s
- [ ] 2.4 Within each block, scan for `Tm` operator (`a b c d e f Tm`) to compute the block's effective text matrix; default to identity `(1,0,0,1,0,0)` if no Tm present
- [ ] 2.5 Within each block, parse each `Tj` and `TJ` operator into an `operators[]` list, recording `kind`, `op_offset`, `operand_start`, `operand_end`, parsed `entries`, and `resolved_text` (use `parseTjArrayContent` + `resolveOperandToUnicode`)
- [ ] 2.6 No call site yet — the parsed model is unused. Smoke-test by running against the two fixtures and confirming the parser identifies the expected block count and per-block content
- [ ] 2.7 Commit + push as a standalone change

## 3. Phase 2 — intra-block cross-operator matching

- [ ] 3.1 Add `findMatchesInBlock($block, array $substitutions): array` returning ops + byte-offset ranges per match
- [ ] 3.2 Implement `spliceBlock($block, $matches, $pageOid, $pageFonts, &$stats): string` — re-emits the block bytes with matched ranges replaced; preserves leading and trailing operator entries; emits placeholder inline before the block's `ET` using the existing Helvetica Tf-restore shape (`/F-fb-anonym 10 Tf (placeholder) Tj /<orig> <size> Tf`)
- [ ] 3.3 Wire `replaceInContentStream` to use the parsed model for intra-block matching; retain existing single-op fast path as a special case (only one op in the matched range)
- [ ] 3.4 Add `cross_operator_matches` counter; bumped when a match spans >1 operator
- [ ] 3.5 Verify on synthetic two-`Tj` fixture (`(foo) Tj (bar) Tj`, needle "foobar") that matching now works
- [ ] 3.6 Regression: re-run Notulen20191102 + Notulen20190602 + the Helvetica-fallback fixtures; per-needle counts must not regress

## 4. Phase 3 — cross-`BT/ET` matching within a logical line

- [ ] 4.1 Add `groupBlocksIntoLogicalLines(BTBlock[]): LogicalLine[]` — same Y (ε=0.5), same font name, identity axes, monotonic X with gap < 8× font size
- [ ] 4.2 Extend matching to walk per-line concatenated text; map matches back to {block, op, byte_in_op} tuples
- [ ] 4.3 Extend `spliceBlock` to a `spliceLogicalLine` that:
  - truncates the suffix of operator `S` in block `Bs`
  - drops fully-consumed blocks/operators between
  - truncates the prefix of operator `E` in block `Be`
  - emits placeholder inline in `Bs` (before `Bs`'s `ET`)
- [ ] 4.4 For dropped blocks, also drop their enclosing `q ... BDC ... BT ... ET ... EMC ... Q` wrapper when the BT/ET is the only text content of that wrapper (avoid orphaned structure-tree entries)
- [ ] 4.5 Add counters: `logical_lines_built`, `cross_block_matches`, `cross_block_skipped_font_mismatch`, `cross_block_skipped_y_mismatch`, `cross_block_skipped_x_overlap`
- [ ] 4.6 Verify on fictieve_brief_anonimisering_3.pdf: `d.smits@amsterdam.nl` and `06-28 334 891` now redact across the tagged spans
- [ ] 4.7 Verify on Notulen20191102.pdf: action-list "Kees" instances in rows 2 + 3 (character-per-`BT/ET`) now redact

## 5. Spec docs

- [ ] 5.1 Add `openspec/specs/cross-bt-et-matching/spec.md` documenting the grouping rule, splice algorithm, diagnostic counters, and the X-gap threshold heuristic
- [ ] 5.2 Update `openspec/specs/text-replacement/spec.md` with the new diagnostic-surface keys
