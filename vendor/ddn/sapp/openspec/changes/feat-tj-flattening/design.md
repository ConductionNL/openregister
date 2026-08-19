## Context

`TJ` operator syntax: `[ <fragment_1> <kern_1> <fragment_2> <kern_2> ... <fragment_N> ] TJ`. Each fragment is a string literal `(...)` or hex string `<...>`. Each kern is a number (units of 1/1000 of a font em; negative values reduce spacing, positive values increase it).

For text-space matching purposes the kern values are irrelevant — they affect rendering position, not the text the user reads. Word/Acrobat split text into many `TJ` fragments for sub-pixel kerning; a needle like "Jan Jansen" is typically emitted as something like `[(J) 2 (a) -1 (n) 3 ( ) -2 (J) 1 (a) -1 (n) 2 (s) 1 (e) -1 (n)] TJ`.

The matching layer from upstream-PR #06 already handles `Tj`. We extend the tokeniser to recognise the `TJ` array shape, expose its fragments to the matcher as individual text-showing operands, then splice the array on match.

## Goals / Non-Goals

**Goals:**

- Match needles that span TJ fragment boundaries.
- Preserve the kerning OUTSIDE the match span (text appearance before/after the placeholder stays visually identical).
- Drop the kerning INSIDE the match span (replaced by the placeholder's single `Tj`, kerning numbers were specific to the original characters and don't apply to the placeholder).

**Non-Goals:**

- Re-emit a `TJ` array around the placeholder for "perfect" rendering. The placeholder is a synthetic marker; preserving the original micro-kerning is not worth the implementation complexity.
- Preserving the original fragment-count cardinality. The spliced output has fewer operators than the input by design.

## Decisions

### D1 — Tokeniser exposes TJ fragments as virtual text-showing entries

When the tokeniser hits a `TJ` operator, it emits per-fragment entries `(operator: 'TJ_fragment', operand_bytes, fragment_index, parent_tj_index, source_byte_start, source_byte_end)`. The matching layer treats `TJ_fragment` identically to `Tj` for text-space concatenation; the splicing layer uses the `parent_tj_index` to know which fragments to retain when splicing.

### D2 — Match-span splice rules

For a match span covering fragments `[m_start, m_end]` within a `TJ` array of fragments `[0, N-1]`:

| Case | Splice result |
|------|---------------|
| `m_start == 0 && m_end == N-1` (full TJ matched) | Replace entire `[...] TJ` with `(placeholder) Tj` |
| `m_start == 0 && m_end < N-1` (matched prefix) | `(placeholder) Tj [<remaining fragments + kerns>] TJ` |
| `m_start > 0 && m_end == N-1` (matched suffix) | `[<leading fragments + kerns>] TJ (placeholder) Tj` |
| `m_start > 0 && m_end < N-1` (matched middle) | `[<leading fragments + kerns>] TJ (placeholder) Tj [<trailing fragments + kerns>] TJ` |

Kerning numbers OUTSIDE the match span are preserved exactly. Kerning numbers INSIDE the match span are discarded.

### D3 — Cross-`Tf` matches inside a single TJ are rare; same start-font rule applies

A `TJ` operator is always emitted under a single `Tf`-set font scope (the operator doesn't internally switch fonts). So the cross-font matching concern from upstream-PR #06 doesn't apply inside a TJ. We still emit the placeholder via the active font's forward map.

### D4 — Fragment-boundary alignment with CID interiors

The CID-interior-split rule from upstream-PR #06 extends naturally: a match's start or end MUST align with a CID boundary inside the fragment it lands on. The fragment-level boundary itself is always CID-aligned (TJ fragments are whole-character sequences).

### D5 — Hex-string fragments treated identically to literal-string fragments

`TJ` operands can mix `(...)` literal strings and `<...>` hex strings (typical Identity-H pattern). The tokeniser normalises both to byte arrays; the matcher treats them identically; the splicer emits the placeholder as a hex string when the active font is composite (2-byte CID), as a literal string when it's simple (1-byte). Existing convention in upstream sapp.

## Risks / Trade-offs

- **Risk**: Discarding internal kerning may cause visible "tightening" of the surrounding text if the original `TJ` used large kerning adjustments. → **Mitigation**: in practice Word's per-character kerning is < 10 units (< 1% em), invisible to the eye. The placeholder's appearance is intentionally distinct anyway.

- **Risk**: A `TJ` with zero fragments (`[] TJ` — spec-legal but pointless) could trip the tokeniser. → **Mitigation**: skip empty `TJ` arrays during tokenisation; emit a `p_debug` for observability.

- **Trade-off**: Splicing logic for the "matched middle" case (Case 4 in D2) produces three operators where there was one. This grows the content stream slightly. → **Mitigation**: the growth is < 10 bytes per match for typical placeholder lengths. Re-encoding via FlateDecode usually shrinks it back below the original size.

## Open Questions

- **OQ1**: Should we collapse adjacent surviving `TJ` operators after splicing (e.g. when a match leaves two single-fragment `TJ` operators side by side)? **Provisional**: no. Acrobat handles consecutive `TJ` operators correctly; collapsing them would add complexity without observable benefit.

- **OQ2**: Some PDFs emit `TJ` with array elements that are themselves arrays (deeply unusual but spec-legal per §9.4.3 ¶2 — "Each element ... shall be either a string or a number"). Reject or tolerate? **Provisional**: reject (per spec). Emit `p_error` if the tokeniser sees nested arrays.

## Migration Plan

Strictly additive change. No migration required for consumers — string-form callers (and unaware-of-this-change callers) observe byte-for-byte identical behaviour after merge. Rollback path is a clean revert of the commit.

