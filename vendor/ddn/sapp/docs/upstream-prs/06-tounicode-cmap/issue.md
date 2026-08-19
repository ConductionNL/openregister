---
status: draft
target_repo: dealfonso/sapp
suggested_title: Font encoding resolver with ToUnicode CMap support
suggested_labels: enhancement
relates_to: openregister/pdf-anonymisation
prereq: 05-filter-chaining (the CMap streams are themselves FlateDecoded)
---

# Upstream issue draft — ToUnicode CMap parser + font encoding resolver

**Intended workflow:** post AFTER the filter-decoder series (01-05) has landed. CMap streams arrive FlateDecoded; the chained-filter dispatch needs to be in place first.

This is a larger PR than the filter-decoder series. Scope it carefully when posting; offer to split into a "parse CMap" PR and a "use CMap to resolve encodings" PR if the reviewer prefers.

**Posted at:** _(fill in once posted)_

---

## Issue body (copy from here)

## Summary

Add a font-encoding resolver that translates Unicode strings (operator-supplied substitution map keys) into per-font byte sequences appropriate for searching content-stream `Tj`/`TJ` operators. Required for the downstream text-replacement use case tracked under the broader SAPP-feature series; see the `/ASCIIHexDecode` issue for context.

The resolver handles every encoding scheme PDF 1.7 §9.6 (Type 1 / Type 3 / TrueType) and §9.7 (Composite Fonts) define for the fonts that appear in text content streams:

- `WinAnsiEncoding` / `MacRomanEncoding` / `StandardEncoding` (single-byte, ASCII passthrough for 0x20–0x7F)
- `/Encoding` dictionaries with `/Differences` arrays (per-character overrides against a base encoding)
- Composite fonts (`Identity-H` / `Identity-V`) — 2-byte glyph IDs, resolved via the font's `/ToUnicode` CMap stream

## Why this matters

In a Word-generated PDF carrying `"Jan Jansen"` in body text:
- The Tj operator's bytes are NOT literal ASCII `"Jan Jansen"` — they're glyph IDs that depend on the font's encoding.
- For a `WinAnsiEncoding` font, glyph IDs HAPPEN to be ASCII for the basic Latin range, so search-and-replace looks like it should work — but only by accident.
- For an `Identity-H` subset font (very common; Word emits these for non-basic-Latin glyphs or for compact embedding), glyph IDs are 2-byte values defined in the font's `/ToUnicode` CMap. Searching for `"Jan Jansen"` literal bytes returns nothing.

Without the encoding resolver, byte-replace can only handle the WinAnsi-Latin-1 corner of real-world PDFs. With it, the substitution map's keys translate correctly into per-font byte sequences and matching works across every font in the input.

## Proposed shape

```php
namespace ddn\sapp\fonts;

class FontEncodingResolver {
    /**
     * Build per-font encoding tables for every /Font in a document.
     * Returns an array keyed by font name → array{
     *     'unicodeToBytes': callable(string $unicode_chunk): string  // search-key encoder
     *     'bytesToUnicode': callable(string $bytes): string          // diagnostics / introspection
     * }
     */
    public function resolveAll(PDFDoc $doc): array { /* ... */ }
}

class CMap {
    /**
     * Parse a /ToUnicode CMap from a stream's decoded bytes.
     * Returns:
     *   - bfchar map: array<int $code, string $unicode>
     *   - bfrange entries: array<array{lo: int, hi: int, base_unicode: string}>
     *   - codespacerange: array<array{lo: int, hi: int}>
     */
    public static function parse(string $cmap_bytes): self { /* ... */ }
    
    public function lookup(int $code): ?string { /* ... */ }
    public function reverse(string $unicode): ?int { /* ... */ }
}
```

CMap streams are themselves FlateDecoded, so this PR depends on the existing FlateDecode + the chained filter pipeline from PR #05.

Encoding lookup logic per font type:

1. `/Type /Font /Subtype /Type1` or `/TrueType`, `/Encoding /WinAnsiEncoding|/MacRomanEncoding|/StandardEncoding` (no `/Differences`): use the static encoding table (Adobe's published mappings — 256-entry lookup).
2. `/Encoding << /BaseEncoding /WinAnsiEncoding /Differences [...] >>`: apply the /Differences overrides on top of the base.
3. `/Subtype /Type0` (composite) with `/Encoding /Identity-H` or `/Identity-V`: parse the font's `/ToUnicode` CMap stream; build forward (code → Unicode) and reverse (Unicode → code) tables from `beginbfchar` / `beginbfrange` entries.

For (3), the CMap syntax to support:
```
12 begincodespacerange
  <0000> <FFFF>
endcodespacerange

3 beginbfchar
  <0001> <0042>          % glyph 0x0001 → U+0042 'B'
  <0002> <00C9>          % glyph 0x0002 → U+00C9 'É'
  <0003> <0041 0301>     % glyph 0x0003 → U+0041 + combining U+0301
endbfchar

2 beginbfrange
  <0010> <001F> <0061>           % range form: 0x0010 → U+0061, 0x0011 → U+0062, ...
  <0020> <002F> [<00E0> <00E1> ...]  % array form: explicit per-code
endbfrange
```

Coverage on `beginbfchar` (per-code mapping), `beginbfrange` range form, and `beginbfrange` array form. Skip the rarer `begincidchar` / `begincidrange` operators for v1 — they appear in CMaps for non-Identity composites which are out of scope here.

## Acceptance test

- Resolve a WinAnsi font's encoding → unicodeToBytes("Jan Jansen") returns ASCII bytes literally.
- Resolve a WinAnsi font with a `/Differences` override that swaps two characters → the resolver applies the override.
- Resolve an Identity-H font with a `/ToUnicode` CMap from a real Word-generated PDF → unicodeToBytes("Jan Jansen") returns the correct 2-byte sequence per the CMap.
- Reverse direction: bytesToUnicode for the same font → returns "Jan Jansen".
- Edge cases:
  - CMap with surrogate-pair encoding (Unicode > U+FFFF)
  - CMap with combining characters (multi-codepoint Unicode value per glyph)
  - Font with no `/ToUnicode` (return null per-font; document the limitation — substitution map entries for that font silently fail)

## Out of scope

- Non-Identity composite fonts (`/Encoding /WinAnsi` on a Type0 — rare).
- `begincidchar` / `begincidrange` operators in the CMap.
- Font subsetting analysis (we don't need to know which Unicode characters are absent from the font's glyph set — the substitution map either matches or doesn't).
- Glyph metrics — purely about character mapping, not visual rendering.

## Ask

This PR is larger than the four filter-decoder PRs combined. Two ways to split if you prefer:

- **One PR**: full resolver + CMap parser in a single review.
- **Two PRs**: (a) CMap parser as a standalone class with its own tests; (b) `FontEncodingResolver` consumes the parser. Cleaner but more review overhead.

Defaulting to one PR unless you have a preference.

## (copy ends)
