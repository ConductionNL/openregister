# Design — ToUnicode CMap parser + encoding resolver

## Decisions

### D1. Two classes, one namespace

- `CMap` — parser. Stateless after construction. Owns the forward / reverse tables and codespace ranges. Returned by static factory `CMap::parse(string $cmap_bytes): self`.
- `FontEncodingResolver` — orchestrator. Reads font dictionaries, identifies encoding scheme, builds per-font byte-translation callables.

Both under `ddn\sapp\fonts\` (new namespace). Sibling to the existing `ddn\sapp\pdfvalue\` and `ddn\sapp\helpers\` namespaces.

### D2. `CMap::parse()` covers three core operators

The PDF 1.7 §9.10.3 ToUnicode CMap mini-language is large; we cover the subset needed for the text-replacement use case:

- `begincodespacerange ... endcodespacerange` — declares the valid character-code ranges. Used to validate input codes.
- `beginbfchar ... endbfchar` — per-code Unicode mapping. Each entry: `<src_code> <unicode_hex>`.
- `beginbfrange ... endbfrange` — range-form mapping. Each entry: `<lo> <hi> <base_unicode>` OR `<lo> <hi> [<u1> <u2> ...]` (array form, explicit per-code mapping for non-contiguous ranges).

Deferred to a follow-up if needed:
- `begincidchar` / `begincidrange` — used by non-Identity composite fonts (rare).
- `usecmap` directive — chains CMaps; rare in `/ToUnicode` streams.
- Locale-specific predefined CMaps (Adobe-Japan, Adobe-Korea, etc.) — not used by Identity-H/V which is what Word generates.

### D3. Forward + reverse tables

For each `beginbfchar` / `beginbfrange` entry, populate:

- `$this->forward[code] = unicode_string` — when we read a stream and want to know what it says.
- `$this->reverse[unicode_string] = code` — when we have a Unicode search key and want to know what bytes to look for.

Multi-codepoint Unicode strings per glyph (combining characters, ligatures) are stored as-is in the forward table; the reverse table keys the string verbatim. For substitution-map use:

```
$resolver = $resolver->resolve($font_dict);
$bytes_to_search = $resolver->unicodeToBytes("Jan Jansen");
// Walk each character of "Jan Jansen", look up reverse[char] for each,
// concatenate the resulting 2-byte codes
```

Edge case: if any character in the search string isn't in `$this->reverse`, the substitution-map entry CAN'T match in this font — we return `null` (rather than partial bytes). Caller skips that font.

### D4. Single-byte encodings

For WinAnsi / MacRoman / Standard, we ship hard-coded 256-entry tables. These are well-defined by PDF 1.7 Appendix D; not algorithmically derived. Approach: embed the tables as PHP arrays in `FontEncodingResolver` constants. About 4 KB per encoding. Trivial.

`/Differences` overrides apply on top: take the base encoding, then for each `(code, name)` pair in the Differences array, replace `forward[code]` with the Unicode for `name`. The PDF Standard Glyph Names list (PDF 1.7 Appendix D §D.4) provides the `glyph_name → unicode` translation; we embed that too (~1 K names).

### D5. Identity-H/V composite fonts

`Identity-H` and `Identity-V` are "no encoding" — character codes pass through as-is (Identity-H: 2-byte big-endian glyph IDs in horizontal writing). The actual code-to-Unicode mapping ALWAYS comes from the font's `/ToUnicode` stream.

If a composite font has no `/ToUnicode`:
- For text-replacement, this is a hard failure mode: we can't translate Unicode search keys to byte sequences for this font. The resolver returns `null` for that font.
- Callers should fall through to a different code path (e.g. skip this font's text, or use a heuristic). The flagship `replaceTextInDocument()` API (PR #08) handles this by skipping the font with a warning.

### D6. Type0 (composite) without Identity encoding

A Type0 font with `/Encoding /WinAnsi` or similar (non-Identity) is unusual but spec-permitted. We treat these via the same `FontEncodingResolver.identifyEncoding()` logic — base encoding + `/Differences` if present. The ToUnicode CMap, if present, takes priority (it's the source of truth for that font's mappings).

### D7. Resolver entry points

```php
class FontEncodingResolver {
    public function resolveAll(PDFDoc $doc): array {
        // Returns array<font_resource_name, FontResolution>
        // Walks every page's /Resources/Font dictionary, dedupes by font reference.
    }
    
    public function resolveFont(PDFObject $font_dict): FontResolution {
        // Resolves a single font; useful for testing.
    }
}

class FontResolution {
    public function unicodeToBytes(string $unicode_chunk): ?string;
    public function bytesToUnicode(string $bytes_chunk): ?string;
    public function getEncodingName(): string;  // diagnostic — "WinAnsi", "Identity-H+ToUnicode", ...
}
```

### D8. CMap stream access via SAPP's chain dispatch

The `/ToUnicode` entry on a composite-font dictionary is an indirect reference to a stream object. The stream is FlateDecoded (almost always). With the PR #05 chained-filter dispatch in place, the resolver calls `$tounicode_obj->get_stream(false)` and gets the decoded CMap bytes ready to parse.

Without PR #05, the resolver would have to call `gzuncompress($obj->get_stream(true))` manually. We could ship this PR with a fallback that does its own decompression IF PR #05 hasn't merged yet, but that's needless complexity — PR #05 is a precondition.

### D9. No glyph metrics, no rendering

We are NOT building a font rasteriser. We have NO opinion on what each glyph looks like. The resolver answers ONE question: "for this Unicode character, in this font, what bytes should I search for in a Tj operator?" Everything else (kerning, ligature analysis, glyph substitution tables) is out of scope.

### D10. Caching

Within a single document, multiple pages may reference the same font. The resolver MUST dedupe by font object ID — parse each font once, return cached `FontResolution` instances on subsequent lookups for the same font. This matters at scale: a 50-page PDF with one font dictionary should NOT trigger 50 CMap parses.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Real-world CMaps use operators we haven't covered (`usecmap`, `begincidchar`) | Medium | Empirical test against ~20 real Woo PDFs (the PoC's measurement); document the deferred operators in the issue's "Out of scope"; if real-world impact is high, follow-up PR adds them |
| Multi-codepoint Unicode strings break `unicodeToBytes()` | Medium | Spec REQ-04 covers; unit-test combining characters explicitly |
| Surrogate-pair Unicode (> U+FFFF) handling | Low | The reverse table keys raw Unicode strings; surrogate pairs are just longer strings; PHP's string semantics handle them transparently |
| Refactor surface — the resolver depends on private PDFObject internals | Low | The resolver uses only the public PDFObject API (get_value, get_stream); no friend-access required |
| Concurrent-document use breaks the resolver's caching | Low | The resolver is per-document; cache is scoped to a `resolveAll()` call |
| PR is too big for one review | Medium-High | Offer in the issue: split into (a) CMap parser standalone, (b) FontEncodingResolver consuming the parser. Two-PR path is cleaner but doubles round-trip |

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-tounicode-cmap/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/tounicode-cmap-resolution/spec.md`).
