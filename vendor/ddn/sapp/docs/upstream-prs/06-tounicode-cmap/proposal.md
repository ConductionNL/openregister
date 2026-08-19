# Proposal — ToUnicode CMap parser + per-font encoding resolver

## Why

Text replacement in a PDF content stream requires translating an operator-supplied search string (e.g. `"Jan Jansen"`) into the byte sequence that actually appears in a `Tj`/`TJ` operator for a specific font. That byte sequence depends on the font's **encoding**, and PDF defines several:

- Single-byte encodings (`WinAnsiEncoding`, `MacRomanEncoding`, `StandardEncoding`) — character codes 0x20–0x7F map to ASCII; the rest is locale-specific.
- `/Encoding << /BaseEncoding ... /Differences [...] >>` — single-byte encoding with per-character overrides.
- Composite fonts (`Identity-H`, `Identity-V`) — 2-byte glyph IDs that don't map to anything directly; the font's `/ToUnicode` CMap (a separate stream object) provides the glyph-ID → Unicode mapping.

For Word-generated PDFs in real-world Woo correspondence, the encoding distribution skews toward Identity-H (subset fonts). Without a ToUnicode CMap parser, the text-replacement code can only match the WinAnsi-Latin-1 corner — typically a small fraction of body text in modern PDFs.

This PR adds the `FontEncodingResolver` that text-replacement code (PR #08) needs to work on Identity-H fonts. It's the **single most-unlocking** PR in the series.

## What Changes

- **NEW class** `ddn\sapp\fonts\CMap` — parses a `/ToUnicode` CMap stream (FlateDecoded bytes) into:
  - forward table: 2-byte code → Unicode string
  - reverse table: Unicode codepoint → 2-byte code
  - codespace ranges (for validation of in-bounds codes)
- **NEW class** `ddn\sapp\fonts\FontEncodingResolver` — for each font dictionary in a PDF:
  - identifies the encoding scheme (WinAnsi / MacRoman / Standard / Differences / Identity-H/V)
  - builds two callables per font:
    - `unicodeToBytes(string $unicode_text): string` — substitution-map encoder; produces the byte sequence for a Unicode string in this font's encoding
    - `bytesToUnicode(string $bytes): string` — reverse direction, primarily for diagnostics
  - exposes a `resolveAll(PDFDoc $doc): array<string, FontResolution>` entry point
- **NEW namespace** `ddn\sapp\fonts\` — to keep encoding/font code separate from the existing `pdfvalue\` and `helpers\` namespaces. The two new classes live under `src/fonts/`.
- **NO change** to existing public API. The new types are additive.

## Impact

- **Spec target:** PDF 1.7 §9.6 (Type 1 / Type 3 / TrueType fonts) + §9.7 (Composite Fonts) + §9.10.3 (`ToUnicode` CMap).
- **Depends on PR #05** (filter chaining) — `/ToUnicode` CMaps are themselves FlateDecoded; without the chain dispatch the CMap stream is opaque.
- **Unlocks:** the flagship `replaceTextInDocument()` API (PR #08).
- **Scope of the parser:** covers `begincodespacerange`, `beginbfchar`, `beginbfrange` operators including the array-form for non-contiguous range mappings. Defers `begincidchar`, `begincidrange` (used by non-Identity composite fonts; rare) to a follow-up.
- **Out of scope:** font glyph metrics (we don't need to know what each glyph looks like — only which Unicode it maps to); font subsetting analysis (we don't need to know which glyphs are present — the substitution map either matches or doesn't); CFF/Type1C glyph access (we work at the encoding-table level, not the glyph-outline level).

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-tounicode-cmap/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/tounicode-cmap-resolution/spec.md`).
