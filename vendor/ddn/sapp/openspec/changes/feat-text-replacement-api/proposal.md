## Why

After PRs `#01`–`#07` land, the text-replacement machinery handles all four PDF filters (FlateDecode + ASCIIHex + ASCII85 + RunLength + LZW), filter chains, Identity-H + ToUnicode CMap resolution, and TJ kerning-array flattening. What's still missing for the production use case is **the Helvetica fallback when a subset font can't encode the placeholder**. This is the final blocker for the >95% Woo case.

Word emits subset fonts containing only the glyphs the document actually uses. A document about "Jan Jansen" produced by a typical Dutch government template won't have `[`, `:`, or digits in the subset's font program. Our placeholder `[PERSON: 7]` then triggers `font_encoding_misses` and the substitution is skipped — usable output but with the wrong-but-not-corrupt behaviour of leaving the name in place.

The fix is the PDF `q/Q` save/restore pattern: temporarily switch to a built-in (non-subset) font like Helvetica before emitting the placeholder, then restore. Built-in fonts (also called "base 14") don't require font programs in the PDF — they're guaranteed available in every reader.

This PR also polishes the public API surface: documentation, idempotency tests, and final cleanup before we start drafting the upstream submission.

## What Changes

- Add the Helvetica fallback path in the placeholder-emit logic introduced by `feat-tounicode-cmap`. When the active font's forward map can't encode every character of the placeholder, the splicer:
    1. Adds `/F-anonymisation-fallback` to the page's `/Resources/Font` if not already present, pointing at a synthesised standard `/Helvetica` Type1 font with `/WinAnsiEncoding`.
    2. Wraps the placeholder in `q\n/F-anonymisation-fallback 12 Tf\n(<placeholder>) Tj\nQ` (the `q/Q` pair saves and restores the graphics state, isolating the font switch).
    3. The trailing `Q` restores the original font; subsequent operators in the content stream continue under the original `Tf`.
- Polish `PDFDoc::replaceTextInDocument` API: stable diagnostic-key naming, full PHPDoc, parameter validation (e.g. reject empty-string keys, reject placeholders containing reserved PDF string characters that would require escape handling).
- Add `examples/upstream-poc.php` — the upstream-PR's demo script that exercises the full pipeline on a representative real-world fixture.

## Capabilities

### New Capabilities

- `subset-font-fallback`: graphics-state-isolated Helvetica fallback for placeholders that the active subset font can't encode.

### Modified Capabilities

- `text-replacement`: the public API contract gains parameter-validation guarantees and a documented stable diagnostic shape suitable for upstream submission.

## Impact

- **Touched files**: `src/PDFDoc.php` (fallback path in the splicer + resource-injection logic, ~80 LOC), `src/PDFObject.php` (helper for synthesising the Helvetica resource object, ~30 LOC), `examples/upstream-poc.php` (new — ~60 LOC).
- **Public API**: `replaceTextInDocument`'s contract becomes stable and documented. Diagnostic shape: all keys from PRs `#06` and `#07` plus `subset_font_fallbacks_used: int` (counter).
- **Depends on**: `feat-tounicode-cmap`, `feat-tj-flattening` (uses both — the fallback fires inside the splicer for either Tj or TJ).
- **Unblocks**: production Woo PDFs with subset fonts that don't include placeholder characters.
- **Upstream-PR draft**: `docs/upstream-prs/08-text-replacement-api/` — this is the upstream-submission-ready package.
