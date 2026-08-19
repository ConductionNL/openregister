## Why

PDF text-showing operator `TJ` (PDF 1.7 §9.4.3) takes an ARRAY operand: alternating string fragments and numeric kerning adjustments, e.g. `[(Hello) -10 (World)] TJ`. Word and most modern PDF producers emit `TJ` (with kerning) much more often than the simpler `Tj` (single string). After upstream-PR #06 (ToUnicode CMap) lands, our matcher can walk Tj operators correctly — but matches that span fragment boundaries inside a `TJ` array still fail because the matcher only sees individual fragments.

The Woo use case has many of these. A typical line of body text in a Word document is emitted as one `TJ` operator with dozens of kerning splits — needles like "Jan Jansen" almost always cross at least one fragment boundary.

## What Changes

- Recognise `TJ` operators in the tokeniser introduced by `feat-tounicode-cmap`.
- For each `TJ`, concatenate the string fragments (ignoring the kerning numbers for matching purposes) and run the same text-space matcher across the concatenated text.
- On match: replace the ENTIRE `TJ` array (all fragments + all kerning numbers within the match span) with a single `Tj` carrying the placeholder. Kerning that lies OUTSIDE the match span (preceding fragments or following fragments) is preserved as a smaller `TJ` operator, or hoisted to standalone `Tj` operators if the match consumes the array's middle.
- The diagnostic surface gains `tj_arrays_modified: int` (counter for matched `TJ` operations, separate from `streams_modified` which still counts decoded content streams).

## Capabilities

### New Capabilities

- `tj-flattening`: TJ kerning-array text-space matching with selective fragment elision.

### Modified Capabilities

- `text-replacement`: extend the operator tokeniser + matcher to handle `TJ` array operands; extend the diagnostic surface with `tj_arrays_modified`.

## Impact

- **Touched files**: `src/PDFObject.php` (tokeniser TJ support, ~40 LOC), `src/PDFDoc.php` (match-and-splice logic for TJ arrays, ~80 LOC).
- **Public API**: `replaceTextInDocument` shape unchanged except for the additive `tj_arrays_modified` key.
- **Depends on**: `feat-tounicode-cmap` (text-space matching foundation).
- **Unblocks**: production Word-generated Woo PDFs at the typical text-emission shape.
- **Upstream-PR draft**: `docs/upstream-prs/07-tj-flattening/`.
