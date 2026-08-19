## D1. Content-stream model shape

`parseContentStreamModel($stream, $pageFonts)` returns `array<int, BTBlock>`. Each `BTBlock` is an associative array with:

| Key | Type | Description |
|---|---|---|
| `bt_offset` | `int` | byte offset of the literal `BT` token |
| `et_offset` | `int` | byte offset of the literal `ET` token |
| `font_name` | `?string` | active `Tf` resource name at start of block (e.g. `F2`); null if no Tf was seen up to this point |
| `font_size` | `?string` | active `Tf` size literal (e.g. `11`); null if none |
| `tm` | `array{a,b,c,d,e,f}` | effective text matrix at start of block; default identity `(1,0,0,1,0,0)` |
| `operators` | `TextOp[]` | ordered list of `Tj`/`TJ` operators inside this block |

Each `TextOp`:

| Key | Type | Description |
|---|---|---|
| `kind` | `'Tj'` \| `'TJ'` | operator type |
| `op_offset` | `int` | byte offset of the `Tj`/`TJ` token |
| `operand_start` | `int` | byte offset of operand opening (`[` for TJ, `(` or `<` for Tj) |
| `operand_end` | `int` | byte offset *after* the operand closing token |
| `entries` | `array` | parsed entries (same shape as `parseTjArrayContent` output for TJ; one-element list for Tj) |
| `resolved_text` | `string` | UTF-8 text the operator produces given the active font |

Font state carries across BT/ET (it's part of graphics state per PDF 1.7 §8.4). Tm is reset to identity at every `BT` and is local to the block; the model captures the Tm after any Tm/Td/T* operator within the block, NOT the running text matrix at each Tj (cumulative advance is irrelevant to matching — only the block's anchor position matters for logical-line grouping).

## D2. Why parse first, splice later (vs. linear walk)

The existing `replaceInContentStream` is a linear walker: it streams operators and emits a possibly-mutated copy. It works only when the unit of matching is a single operand. Once needles can span operators, the matcher needs random access to multiple operators' resolved texts simultaneously — the linear shape can't express this naturally. A parsed model is the simplest representation that supports both the existing single-operator path (special case where the match consumes exactly one operator in one block) and the new multi-operator/multi-block paths.

The model is built ONCE per content stream and consumed in two stages (intra-block matching, then cross-block matching). The byte cost of parse+rebuild is comparable to the existing string-concatenation pipeline; benchmarking will confirm but the per-stream payload is small (a couple of hundred operators typically) so any overhead is dominated by the existing decompression cost on the input stream.

## D3. Tm extraction — only `Tm`, not Td/T*

PDF defines three text-positioning operators that update the text matrix:
- `Tm` — absolute set
- `Td` — relative translate
- `T*` / `TD` — newline (advance by `TL` leading)

Word and modern PDF producers almost always emit `Tm` once per BT/ET, with `Td/T*` rarely used outside multi-line text objects. For Phase 1 we only handle `Tm`. The Phase 2/3 logical-line grouping uses the block's anchor position, which is what `Tm` directly provides; `Td/T*` cases would land on TODO and only matter if a fixture shows them used for multi-block layout.

If no Tm is seen, default to identity. Real Word/Acrobat output always emits Tm; identity-default is a fallback for hand-crafted streams and for safety only.

## D4. Active Tf tracking

Tf operators may appear inside or outside `BT/ET`. The active font carries across blocks. The parser maintains a running `(font_name, font_size)` pair that's updated every time a `Tf` is scanned and snapshotted at each `BT` for that block's record. Tf changes WITHIN a block (mid-stream font switch) are not separately modelled in Phase 1 — the block records the font that was active *at start*. Phase 2 can extend the model to record per-operator Tf if a fixture demonstrates a mid-block switch that affects matching.

## D5. Resolved text composition

For each `Tj`/`TJ` operator, `resolved_text` is the concatenation of `resolveOperandToUnicode(entry.bytes, entry.shape, fontInfo)` over the parsed entries. `kern` entries contribute nothing. The block's resolved text is the concatenation of its operators' resolved texts.

The block-level resolved text powers cross-operator matching (Phase 2). The logical-line resolved text (concatenation across blocks) powers cross-block matching (Phase 3).

## D6. Splice algorithm (Phases 2 + 3)

Given a needle match in concatenated text and the mapping back to {block, op, byte_in_op}:

**Trivial case** — match fits in one operator of one block:
- existing intra-`TJ`-array splice (literal-fragment-split from feat-tj-flattening's follow-up fix) applies. No new code path needed.

**Multi-operator, single block** — match spans ops `S..E` within one block `B`:
1. Emit operators before `S` verbatim
2. Emit operator `S` truncated to its prefix (entries before the match start)
3. Emit `q-less` font switch: `/F-fb-anonym 10 Tf (placeholder) Tj /<font_name> <font_size> Tf`
4. Skip operators between `S` and `E`
5. Emit operator `E` truncated to its suffix (entries after the match end)
6. Emit operators after `E` verbatim

**Multi-block** — match spans blocks `Bs..Be`:
1. For block `Bs`: emit its full text up to (and including) the truncated operator `S` prefix; append the inline placeholder before `Bs`'s `ET`
2. For blocks between `Bs` and `Be`: emit nothing (drop the entire BT...ET region plus surrounding marked-content wrappers when applicable; see D7)
3. For block `Be`: emit its full text starting from the truncated operator `E` suffix; preserve `Be`'s `BT...Tm` header so the surviving suffix renders at the original X

The placeholder is emitted **inside block `Bs`'s text object**, AFTER its truncated prefix. This is the same trick used today: rely on the natural Tm advance from the prefix Tj to position the placeholder, then restore the font with an explicit Tf. No new Tm arithmetic is needed.

## D7. Marked-content wrappers around dropped blocks

Tagged PDFs wrap each `BT/ET` in:
```
EMC  /Span <</MCID N/Lang (xx-XX)>> BDC q
0.000... re W* n
BT ... ET
Q
```

When a whole BT/ET block is dropped (Phase 3, fully-consumed block between match start and end), the enclosing `q ... BDC ... ET ... Q` wrapper becomes empty. Two options:

- **Keep the empty wrapper** — preserves the structure tree's MCID reference but yields an empty span (no visible content).
- **Drop the wrapper too** — drops the structure tree's MCID reference along with its content. The page's `/StructParents` array may then have a dangling reference.

Phase 3 will drop the wrapper. The structure tree's downstream MCID resolution gracefully degrades — readers that don't enforce structure validity ignore the dangling reference. Anonymised PDFs aren't required to remain accessibility-conformant; redaction wins over structure preservation.

## D8. X-gap threshold for logical-line grouping

Two BT/ET blocks at the same Y are part of the same logical line only when their X positions don't overlap AND aren't separated by more than a heuristic gap. The gap protects against multi-column layouts where two columns share a Y baseline but represent unrelated text.

The threshold: `gap_max = 8 × font_size` (≈ 8 character widths of body text). For 11pt body text that's 88 PDF units — wider than a tab stop but narrower than a column-break in a typical letter layout. Field-tunable later if real fixtures show false joins.

Why `font_size` and not a fixed value: a 24pt heading legitimately has more inter-word spacing than 11pt body; using font-size-relative units stays sensible across font sizes.

## D9. Diagnostic counters added by phase

Phase 2:
- `cross_operator_matches: int` — count of matches that spanned >1 operator (intra-block)

Phase 3:
- `logical_lines_built: int` — total logical lines identified across the document
- `cross_block_matches: int` — matches that spanned >1 `BT/ET`
- `cross_block_skipped_font_mismatch: int` — adjacent same-Y blocks not joined due to font name differing
- `cross_block_skipped_y_mismatch: int` — adjacent blocks rejected because Y differed by > ε
- `cross_block_skipped_x_overlap: int` — adjacent blocks rejected because X positions overlap

These are PII-free (counters only) and follow the diagnostic-surface conventions from the existing stats schema.
