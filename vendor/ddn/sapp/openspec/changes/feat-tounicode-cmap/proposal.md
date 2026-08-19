## Why

The current PoC `replaceTextInDocument()` does byte-level literal matching on the decoded content stream. This works for the synthesised WinAnsi fixture but fails on every real-world Word-generated PDF, because Word emits subset embedded fonts with `/Encoding /Identity-H` and a `/ToUnicode` CMap. In those streams the bytes `Jan Jansen` never appear — what's emitted is a sequence of glyph IDs (CIDs) that only mean "Jan Jansen" once you resolve them through the font's `/ToUnicode` CMap. Without CMap resolution, the entire Woo use case (Word-generated Dutch government documents) is dead.

This is the largest single piece of the text-replacement feature and the hardest to get right. It's also the gating dependency for any production rollout in OpenRegister.

## What Changes

- Add CMap parsing for `/ToUnicode` streams (PDF 1.7 §9.10.3 + Adobe ToUnicode CMap Tech Note 5411) plus the implicit encodings (`/WinAnsiEncoding`, `/MacRomanEncoding`, `/StandardEncoding`, `/Identity-H`, `/Identity-V`) for simple fonts.
- Build forward (text → CID-byte-sequence) and reverse (CID-byte-sequence → text) maps per font referenced by a page's `/Resources/Font`.
- Walk the content stream's text-showing operators (`Tj`, `'`, `"`, and `TJ` partially — full `TJ` is upstream-PR #07), track the current font from the `Tf` operator, and match the needle in TEXT SPACE (after CID→Unicode resolution), not byte space.
- Emit the placeholder in CID space using the FORWARD map of the currently active font. If the placeholder's Unicode characters can't be encoded via the active font's forward map, emit a `font_encoding_misses` diagnostic per substitution per stream (upstream-PR #08 adds the Helvetica fallback that recovers from this).
- Public API on `PDFDoc` stays the same shape (`replaceTextInDocument(array $substitutions): array`); the diagnostic surface gains `font_encoding_misses: array<int oid, array<string needle, string font_name>>`.

## Capabilities

### New Capabilities

- `tounicode-cmap-resolution`: ToUnicode CMap parsing, font-encoding-aware glyph-text mapping for both Identity-H and simple-font encodings.

### Modified Capabilities

- `text-replacement`: switch the matching layer from byte-space to text-space; track active font across the content stream; emit placeholders via the active font's forward map.

## Impact

- **Touched files**: new `src/CMap.php` (~250 LOC for CMap parser + map builder), new `src/FontEncoding.php` (~150 LOC for implicit-encoding + Identity-H/V handling), `src/PDFObject.php` (helpers to fetch a font's CMap), `src/PDFDoc.php` (refactor `replaceTextInDocument` to walk text-showing operators and consult per-font maps).
- **Public API**: `replaceTextInDocument` signature unchanged; diagnostic shape grows additively.
- **Depends on**: `feat-filter-chain-dispatch` (CMap streams are usually FlateDecode-encoded — already supported on the PoC path).
- **Unblocks**: production Woo PDFs (Word-generated Identity-H subset fonts).
- **Upstream-PR draft**: `docs/upstream-prs/06-tounicode-cmap/`.
