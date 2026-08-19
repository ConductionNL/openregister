## D1. Why pairs, not chains

A wrapped needle touches exactly two lines in the overwhelmingly dominant case (line-breaking splits at one whitespace). Supporting 3+-line spans would require dropping whole interior lines inside one byte-range edit — interior blocks' `ET…BT` gaps, their own graphics state, and their positioning would all need re-synthesis. The cost/benefit is wrong for v1: entities long enough to span three lines (40+ chars at body sizes) are rare, and the OpenRegister validation gate still fails closed on them, so nothing leaks silently. Pairs keep the edit shape identical to Phase 3's proven two-block splice.

## D2. Line-pair eligibility thresholds

| Rule | Value | Rationale |
|---|---|---|
| Y gap | `(0.5, 2.0 × font_size]` | Body leading is 1.05–1.5 × size (this fixture: 12.5pt at 11pt = 1.14×). A paragraph break (blank line) is ≥ 2× leading ≈ 2.27 × size — excluded. Needles don't span paragraph breaks. |
| X margin delta | `≤ 1.5 × font_size` | Wrapped continuations return to the paragraph margin (delta ≈ 0). Indented first lines stay within an em or two. Same-Y-range columns sit hundreds of points apart — excluded. |
| Font | same name + size bucket | Same policy as Phase 3: a needle that changes font mid-entity is out of scope. |
| Axes | identity (`b,c ≈ 0`, `a,d > 0`) | Same policy as Phase 3 — no rotated/skewed text. |

All thresholds are field-tunable heuristics in the same spirit as Phase 3's D8.

## D3. The wrap-as-whitespace model

The producer either keeps the trailing space on the top line (`(…op 14 mei )` — Word/LibreOffice shape) or trims it. Concatenating the pair's resolved texts handles the former: `…op 14 mei ` + `2026 heeft…` contains `14 mei 2026` directly. For the trimmed shape, a synthetic `' '` is inserted between the blocks in the **match view only**, with its concat position recorded; a match is accepted only when `needle[syntheticPos - matchPos] === ' '` — the synthetic char stands in for the wrap, never for arbitrary characters.

The synthetic char never maps to operand bytes: it lies strictly between the top block's last op (`text_end`) and the bottom block's first op (`text_start`), so `resolvedOffsetToEntryByte` is never asked to resolve it. A match that would *start* or *end* on the synthetic position finds no owning op in the index and is skipped.

## D4. Claimed ranges vs. the edit-applier's overlap skip

Phase 3 already skips overlapping edits at apply time (reverse-offset walk). That is not sufficient here: two overlapping needles in the same pair (`4 mei 2026` inside `14 mei 2026`) both produce edits with the **same** `start`/`end` (the shared start-op operand and end-op offsets), so the applier keeps whichever sorts first and both bump `replacements_per_needle` — over-counted stats and an order-dependent winner. Phase 4 therefore tracks claimed `[start, end)` ranges in concat coordinates per pair and skips overlapping later matches before any counter is touched. Callers that order substitutions longest-first (OpenRegister does since the same change-set) get the correct longest-needle-wins semantics.

## D5. Inter-block byte preservation

`buildCrossBlockEdit`'s historical shape drops the bytes between `startBlock.ET` and `endBlock.BT`. For Phase 3 (same line, usually byte-adjacent spans) the gap is whitespace. Cross-line edits span whole-line distances where the gap can carry `q/Q`, colour, or clip operators the rest of the page depends on. New optional `$preserveInterBlockBytes` parameter (default `false`, Phase 4 passes `true`) re-emits the range verbatim between the `ET` and the re-emitted end-block setup. Phase 3 call sites are unchanged.

## D6. Fixture evidence

fictieve_brief_anonimisering.pdf, oid=4: blocks 42/43, font `F2|11`, `tm.e` 90.02 both, `tm.f` 442.63 → 430.15 (gap 12.48 = 1.13 × size). With the full 34-needle production substitution map ordered longest-first: `unmatched_needles: []`, `cross_line_matches: 1`, `4 mei 2026` count stays 1 (claimed-range guard), smalot re-extraction shows `[DATE: …]` at the wrap point and the OpenRegister strict gate passes.
