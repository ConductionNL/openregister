# Spec — ToUnicode CMap parser + encoding resolver

## Requirements

### REQ-01. `CMap::parse(string $bytes)` MUST parse `beginbfchar` / `endbfchar`

The parser MUST handle the per-code mapping operator:

```
N beginbfchar
  <src1> <unicode1>
  <src2> <unicode2>
  ...
endbfchar
```

Each entry is two hex strings: the source character code (1–4 bytes typically), and the target Unicode codepoint(s).

**Scenario: simple bfchar mapping**

GIVEN a CMap stream containing `1 beginbfchar <0042> <0061> endbfchar`
WHEN `CMap::parse()` is called
THEN `$cmap->lookup(0x42)` returns the Unicode character `'a'` (U+0061)
AND `$cmap->reverse('a')` returns the integer `0x42`

**Scenario: multi-codepoint Unicode value**

GIVEN a CMap entry `<0050> <0041 0301>` (codepoint U+0050 maps to U+0041 + combining U+0301)
WHEN `CMap::parse()` runs
THEN `$cmap->lookup(0x50)` returns a string with TWO Unicode codepoints (`'A' + combining-accent`)

### REQ-02. `CMap::parse()` MUST parse `beginbfrange` / `endbfrange`

The parser MUST handle the range-form mapping operator in BOTH variants:

```
N beginbfrange
  <lo> <hi> <base_unicode>                     # range form: incrementing source maps to incrementing unicode
  <lo> <hi> [<u_lo> <u_lo+1> ... <u_hi>]       # array form: explicit per-code unicode
endbfrange
```

**Scenario: bfrange range form**

GIVEN `1 beginbfrange <0010> <001F> <0061> endbfrange`
WHEN the parser runs
THEN `lookup(0x10)` returns `'a'` (U+0061), `lookup(0x11)` returns `'b'`, ..., `lookup(0x1F)` returns `'p'`

**Scenario: bfrange array form**

GIVEN `1 beginbfrange <0020> <0023> [<00E0> <00E1> <00E2> <00E3>] endbfrange`
WHEN the parser runs
THEN `lookup(0x20)` returns U+00E0, `lookup(0x21)` returns U+00E1, etc.

### REQ-03. `CMap::parse()` MUST track codespace ranges

The parser MUST record codespace ranges from `begincodespacerange` so the resolver can validate that input codes are within the font's defined range.

**Scenario: codespace range recorded**

GIVEN `1 begincodespacerange <0000> <FFFF> endcodespacerange`
WHEN the parser runs
THEN `$cmap->isInCodespace(0x0042)` returns `true`
AND `$cmap->isInCodespace(0x10000)` returns `false`

### REQ-04. `FontEncodingResolver::resolveFont()` MUST resolve every supported encoding scheme

For each font dictionary, the resolver MUST identify the encoding scheme and produce a `FontResolution` object with working `unicodeToBytes()` and `bytesToUnicode()` callables:

| Encoding type | Detection | Resolver behaviour |
|---------------|-----------|---------------------|
| `WinAnsiEncoding` / `MacRomanEncoding` / `StandardEncoding` (no Differences) | `/Encoding` is a name | Use the static encoding table |
| `/Encoding << /BaseEncoding ... /Differences [...] >>` | `/Encoding` is a dict | Apply Differences overrides on top of the base |
| `Identity-H` / `Identity-V` (composite font) | `/Subtype /Type0` + `/Encoding /Identity-H` | Parse `/ToUnicode` CMap; build forward/reverse tables |
| Composite font WITHOUT `/ToUnicode` | `/Subtype /Type0` + no `/ToUnicode` | Return resolution that returns `null` from `unicodeToBytes()` |

**Scenario: WinAnsi font resolves**

GIVEN a font dictionary `<< /Type /Font /Subtype /TrueType /Encoding /WinAnsiEncoding ... >>`
WHEN `resolveFont()` is called
THEN `$resolution->unicodeToBytes('Hi')` returns the bytes `\x48\x69` (literal ASCII)

**Scenario: WinAnsi + Differences resolves**

GIVEN a font with `/Encoding << /BaseEncoding /WinAnsiEncoding /Differences [ 0x80 /Euro ] >>`
WHEN `resolveFont()` is called
THEN `$resolution->unicodeToBytes('€')` returns `\x80`
AND `$resolution->bytesToUnicode("\x80")` returns `'€'`

**Scenario: Identity-H with ToUnicode CMap resolves**

GIVEN a composite font with `/Encoding /Identity-H` and a `/ToUnicode` stream containing `<0001> <0042>` (glyph 1 maps to Unicode 'B')
WHEN `resolveFont()` is called
THEN `$resolution->unicodeToBytes('B')` returns the 2-byte sequence `\x00\x01`

**Scenario: Identity-H without ToUnicode is unrecoverable**

GIVEN a composite font with `/Encoding /Identity-H` and NO `/ToUnicode` entry
WHEN `resolveFont()` is called
THEN `$resolution->unicodeToBytes('anything')` returns `null`

### REQ-05. `unicodeToBytes()` MUST return `null` when ANY character is unrepresentable

If the search Unicode string contains a character that the font's encoding can't represent (not in the reverse table), the method MUST return `null` rather than partial bytes. Callers use `null` as the signal to skip this font.

**Scenario: unrepresentable character returns null**

GIVEN a WinAnsi font (which doesn't include U+4E2D, '中')
WHEN `unicodeToBytes('Hi中')` is called
THEN the return value is `null`

### REQ-06. Resolver MUST cache per-font results within a document

A single `resolveAll()` call MUST parse each font dictionary at most once. Repeated `resolveFont()` calls for the same font (identified by object reference) MUST return the cached `FontResolution`.

**Scenario: same font resolved twice returns same instance**

GIVEN a PDF where two pages reference the same font dictionary
WHEN `resolveAll()` runs
THEN the font is parsed once; both page references map to the same `FontResolution` instance (identity-equal)

### REQ-07. `/ToUnicode` stream MUST be decoded via the chained-filter dispatch

The CMap stream is read via `$tounicode_obj->get_stream(false)`. This means the implementation depends on PR #05 (filter chaining) being in place — most CMap streams are FlateDecoded and may be wrapped in ASCII85.

**Scenario: FlateDecoded CMap stream decodes**

GIVEN a `/ToUnicode` stream object with `/Filter /FlateDecode`
WHEN the resolver reads it
THEN `get_stream(false)` returns the decoded CMap bytes ready for `CMap::parse()`

### REQ-08. No glyph metrics or rendering

The resolver MUST NOT depend on glyph metrics, glyph outlines, font subsetting tables, or any rasterisation logic. It works at the encoding-table level only.

### REQ-09. Deferred CMap operators MUST fall through to a clear error

Operators not handled by this PR (`begincidchar`, `begincidrange`, `usecmap`) MUST cause `CMap::parse()` to either:
- Return a partial CMap with a warning logged, OR
- Throw a `RuntimeException` naming the unsupported operator

The implementation chooses one — both are acceptable for the use case. Document the choice in the spec.

**Scenario: unsupported operator surfaces clearly**

GIVEN a CMap containing `begincidchar` (unsupported)
WHEN `CMap::parse()` runs
THEN either a warning is logged and the operator is skipped, OR an exception is thrown
AND the caller can tell which operator caused the issue
