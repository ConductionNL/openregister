## 1. Pre-flight

- [x] 1.1 Confirm `feat-tounicode-cmap` is merged into `work/text-replacement`
- [x] 1.2 Branch off as `feat/tj-flattening`
- [x] 1.3 Capture a Word-generated fixture that emits the target name across multiple TJ fragments (check via inspection of the decoded content stream) and check in as `examples/poc-fixture-tj-fragmented.pdf`

## 2. Tokeniser TJ support

- [x] 2.1 Extend `PDFObject::tokeniseOperators` (introduced in upstream-PR #06) to recognise `TJ` array operands
- [x] 2.2 For each TJ, emit per-fragment text-showing entries with `parent_tj_index` link (D1)
- [x] 2.3 Preserve kerning numbers as non-text-showing entries between fragments
- [x] 2.4 Reject nested-array operands via `p_error` (OQ2)
- [x] 2.5 Skip empty TJ arrays with a `p_debug` log line
- [x] 2.6 (REQ-005) Tokeniser tightening: honour PDF 1.7 §7.2.3 Table 1 whitespace alphabet only (NUL/HT/LF/FF/CR/SP — VT excluded); treat `%`-to-EOL comments as whitespace per §7.2.4; numeric kerning tokenizer accepts ONLY `-+.0-9` (rejects `e`/`E` per §7.3.3 — accepting them lets a producer's `e` glyph token after a hex CID `<65>` get swallowed as part of a number); odd-length hex strings padded with trailing `0` per §7.3.4.3 instead of silently dropped via `@hex2bin($hex) ?: ''`

## 3. Match-and-splice

- [x] 3.1 Update the matcher in `PDFDoc::replace_text_in_document` to consume per-fragment entries as if they were `Tj` operands (the concatenation logic doesn't care about origin)
- [x] 3.2 When a match's source range covers TJ fragments, compute `m_start` and `m_end` fragment indices within the TJ
- [x] 3.3 Implement the four splice shapes per D2 (full / prefix / suffix / middle)
- [x] 3.4 Preserve OUTSIDE-the-match kerning numbers; drop INSIDE-the-match kerning
- [x] 3.5 Splice the new operator(s) into the source byte range
- [x] 3.6 (REQ-006) Multi-match loop: `processTjArray` re-resolves the concatenated text after each splice and iterates until no remaining substitution matches in the (possibly already modified) text. Earlier code returned on the first hit; multi-needle TJs and same-needle-twice-in-one-TJ silently under-counted `replacements_per_needle`. Render groups split on `kind === 'placeholder'` so the D2 split shape (leading TJ + placeholder Tj + trailing TJ) is preserved per match boundary. Placeholder always emitted as literal regardless of first matched fragment's shape (forces D2-table-row 1 shape uniformly). Lazy-init `replacements_per_needle[$needle]` inside the splice loop as a belt-and-braces guard against future entry-point refactors

## 4. Diagnostic surface

- [x] 4.1 Add `tj_arrays_modified: int` to the returned array

## 5. Tests / verification

- [x] 5.1 Round-trip on `examples/poc-fixture-tj-fragmented.pdf`: assert needle replaced, placeholder rendered correctly in a real viewer
- [x] 5.2 Unit fixture: hand-crafted TJ with the four splice positions (full / prefix / suffix / middle)
- [x] 5.3 Verify `examples/poc-replace-text.php` (Tj-only fixture) still exits 0 AND its emitted diagnostic line shows `tj_arrays_modified: 0` (the verification gate reads the child's stdout via `exec`, scans for the key, and fails if the value is non-zero OR the key is missing — protects against a regression where a Tj-only stream gets misclassified and a TJ splice fires)
- [ ] 5.4 Negative test: TJ with nested array operand → `p_error` + stream unchanged (deferred — code returns null on nested `]` but PoC has no synthetic fixture; revisit when fragmented hex fixture lands)
- [ ] 5.5 Negative test: TJ with CID-split needle alignment → `cid_split_mismatch` recorded, source unchanged (deferred to `feat-text-replacement-api` PR #10 — needs an Identity-H fragmented fixture which lands with the public API + subset-font fallback)
- [x] 5.6 REQ-005 parser-tightening scenarios (exercised via `parseTjArrayContent` reflection in `examples/poc-tj-flattening.php`): numeric tokenizer rejects exponent notation (`1e2`); odd-length hex `<41B>` pads to `\x41\xB0` per §7.3.4.3; `%`-to-EOL comments treated as whitespace per §7.2.4; VT (0x0B) is NOT in the whitespace alphabet per §7.2.3 Table 1
- [x] 5.7 REQ-006 multi-match scenarios (full PDF round-trip): two different needles match in one TJ → both placeholders land + both per-needle counters increment + `tj_arrays_modified == 1`; same needle twice in one TJ → both occurrences spliced + counter reaches 2

## 6. Upstream-PR draft

- [x] 6.1 Update `docs/upstream-prs/07-tj-flattening/{proposal,design,tasks}.md`
- [x] 6.2 Leave `Posted at: <pending>` placeholder

## 7. Quality gate

- [x] 7.1 PHP 7.4 compatibility
- [x] 7.2 No new composer dependencies
- [x] 7.3 snake_case discipline

## 8. Commit + PR

- [x] 8.1 Commit on `feat/tj-flattening`
- [x] 8.2 Open PR `feat/tj-flattening → work/text-replacement` — include before/after operator-sequence diff in the description
