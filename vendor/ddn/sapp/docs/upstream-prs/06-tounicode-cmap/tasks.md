# Tasks — ToUnicode CMap parser + encoding resolver

## 1. CMap parser (REQ-01, REQ-02, REQ-03, REQ-09)

- [ ] 1.1 Create `src/fonts/CMap.php` with `class CMap` under namespace `ddn\sapp\fonts`.
- [ ] 1.2 Implement `public static function parse(string $bytes): self`. Tokenise the CMap (whitespace-separated operators, hex-string literals `<...>`, array literals `[ ... ]`, integer counts).
- [ ] 1.3 Implement `begincodespacerange` / `endcodespacerange` handling — record ranges as `array<{lo: int, hi: int}>`.
- [ ] 1.4 Implement `beginbfchar` / `endbfchar` handling — populate `$this->forward[code] = unicode_string`.
- [ ] 1.5 Implement `beginbfrange` / `endbfrange` handling — both range form AND array form.
- [ ] 1.6 Reverse-table population — for each `forward[code] = unicode`, set `$this->reverse[unicode] = code`.
- [ ] 1.7 Methods: `lookup(int $code): ?string`, `reverse(string $unicode): ?int`, `isInCodespace(int $code): bool`.
- [ ] 1.8 Handle deferred operators (`begincidchar` etc.) per REQ-09 — log a warning and skip, OR throw. Pick one; document.

## 2. Single-byte encoding tables (REQ-04)

- [ ] 2.1 Embed `WinAnsiEncoding` 256-entry table as a constant in `FontEncodingResolver`. Source: PDF 1.7 Appendix D §D.2.
- [ ] 2.2 Embed `MacRomanEncoding` 256-entry table. Source: PDF 1.7 Appendix D §D.1.
- [ ] 2.3 Embed `StandardEncoding` 256-entry table. Source: PDF 1.7 Appendix D §D.3.
- [ ] 2.4 Embed PDF Standard Glyph Names → Unicode mapping (~1 K entries). Used for `/Differences` overrides where the override is by glyph name. Source: Adobe Glyph List for New Fonts (AGLFN).

## 3. Encoding resolver (REQ-04, REQ-05, REQ-06, REQ-07)

- [ ] 3.1 Create `src/fonts/FontEncodingResolver.php` with `class FontEncodingResolver` and `class FontResolution`.
- [ ] 3.2 Implement `resolveAll(PDFDoc $doc): array` — walk every page's `/Resources/Font` dictionary, dedupe by object ID, return `array<font_name, FontResolution>`.
- [ ] 3.3 Implement `resolveFont(PDFObject $font_dict): FontResolution` — identify encoding scheme per the table in `design.md` D7.
- [ ] 3.4 For composite fonts with `/ToUnicode`: fetch the CMap stream via `$tounicode_obj->get_stream(false)` (depends on PR #05), parse via `CMap::parse()`, store in the resolution.
- [ ] 3.5 Implement `FontResolution::unicodeToBytes(string): ?string` — translate via the per-font reverse table.
- [ ] 3.6 Implement `FontResolution::bytesToUnicode(string): ?string` — translate via the per-font forward table.
- [ ] 3.7 Implement `FontResolution::getEncodingName(): string` — return e.g. `"WinAnsi"`, `"WinAnsi+Differences"`, `"Identity-H+ToUnicode"`, `"Identity-H (no ToUnicode)"`. Diagnostic.

## 4. Caching (REQ-06)

- [ ] 4.1 `FontEncodingResolver` instance maintains a `array<object_id, FontResolution>` cache.
- [ ] 4.2 `resolveAll()` populates the cache as it walks pages. Repeated lookups for the same font return the cached instance.

## 5. Fixtures + verification

- [ ] 5.1 Synthesise / source a fixture PDF with a WinAnsi font carrying body text.
- [ ] 5.2 Fixture PDF with WinAnsi + `/Differences` overrides.
- [ ] 5.3 Fixture PDF with Identity-H composite font (Word-generated). Should have `/ToUnicode` CMap.
- [ ] 5.4 Fixture PDF with multi-codepoint Unicode mappings (combining accents).
- [ ] 5.5 Fixture PDF with a composite font WITHOUT `/ToUnicode` (synthesised — these are rare in practice).
- [ ] 5.6 Verification script: for each fixture, assert `unicodeToBytes()` produces the expected byte sequence; round-trip via `bytesToUnicode()`.

## 6. Issue + PR

- [ ] 6.1 Post the issue body from `issue.md`. Frontmatter `Posted at:`.
- [ ] 6.2 Branch `feat/tounicode-cmap` off upstream `main`. PR depends on #05 being merged or open.
- [ ] 6.3 Open the PR; offer the single-PR vs. two-PR split per the issue's "Ask" section.
- [ ] 6.4 Squash-merge into `work/text-replacement`.

## 7. Quality

- [ ] 7.1 No regression in existing SAPP API.
- [ ] 7.2 No new dependencies — pure PHP only.
- [ ] 7.3 REQ-01 through REQ-09 each have a passing verification step.
- [ ] 7.4 Empirical measurement on PoC PDFs (the OR-side `pdf-anonymisation` change's PoC milestone) — document which CMap operator forms actually appear in real Woo PDFs vs. what we cover here.

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-tounicode-cmap/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/tounicode-cmap-resolution/spec.md`).
