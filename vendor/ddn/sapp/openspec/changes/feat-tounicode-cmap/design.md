## Context

A PDF's content stream emits text via operators like `Tj` (show string), `'` (next-line + show), `"` (next-line + show with spacing), and `TJ` (show string array with kerning). Each takes a PDF string (one or more bytes) as its operand. To know what the user sees, you must resolve the bytes through the currently active font's encoding:

- **Simple fonts** (Type1, TrueType non-Identity): the encoding is a 1-byte-to-glyph-name map, then glyph name to Unicode via the font's `/ToUnicode` CMap (if present) or the glyph-name-to-Unicode mapping in Adobe Glyph List (AGL).
- **Composite fonts (Type0)** with `/Encoding /Identity-H` or `/Identity-V`: the encoding is "the 2-byte CID is identity-mapped to a 16-bit glyph index in the descendant font"; Unicode comes from `/ToUnicode` CMap exclusively.

ToUnicode CMaps (Adobe Tech Note 5411) are PostScript-syntax streams declaring:

- `bfchar <code> <unicode>` blocks — direct 1-to-1 mappings.
- `bfrange <start> <end> <startUnicode>` blocks — contiguous code ranges.
- `bfrange <start> <end> [<u1> <u2> ...]` blocks — contiguous codes with explicit per-code Unicode targets.
- Multi-codepoint Unicode targets (ligatures, decomposed characters) are encoded as hex strings of even length > 4.

The forward direction (Unicode → CID sequence) is needed to emit the placeholder. ToUnicode CMaps only declare the reverse direction explicitly, but they're 1-to-1 mappings on the typical Word-emitted shapes, so the reverse is trivial to invert. Where the inversion is not 1-to-1 (different CIDs mapping to the same Unicode codepoint via combining-mark sequences), we pick the lowest-CID mapping and document the choice.

## Goals / Non-Goals

**Goals:**

- Parse ToUnicode CMap streams correctly for the Adobe-Distiller / Word-emitted shape (covers >99% of in-the-wild Identity-H subset fonts).
- Build forward + reverse maps per font referenced by the page being processed.
- Match the needle in text space: concatenate the resolved Unicode characters from text-showing operators in source order, find the needle, identify the byte ranges in the content stream that contributed to the match.
- Replace the matched byte ranges with a placeholder encoded via the active font's forward map. If the forward map can't encode a placeholder character, emit a `font_encoding_misses` diagnostic and SKIP the substitution (no corruption — upstream-PR #08 handles the recovery).

**Non-Goals:**

- TJ kerning-array flattening — upstream-PR #07.
- Subset-font Helvetica fallback for unencodable placeholders — upstream-PR #08.
- Vertical writing mode (`Identity-V`) — same code path as `Identity-H` modulo glyph-position semantics; we handle the encoding identically.
- Right-to-left / bidi text — out of scope for the Woo use case.
- CIDFont-to-GID maps via `/CIDToGIDMap` — relevant for direct glyph-ID emission but not for our matching layer.

## Decisions

### D1 — Two new files, named after their domain

- `src/CMap.php` — parser for the PostScript-syntax CMap streams. Builds both forward and reverse maps. Public methods: `CMap::fromStream(string $bytes): CMap`, `CMap::cidToUnicode(string $cidBytes): string`, `CMap::unicodeToCid(string $unicode): string|null` (null = unencodable).
- `src/FontEncoding.php` — implicit-encoding tables for `/WinAnsiEncoding`, `/MacRomanEncoding`, `/StandardEncoding`, plus Identity-H/V passthrough. Public methods: `FontEncoding::forName(string $name): FontEncoding`, `FontEncoding::byteToUnicode(int $byte): string`, `FontEncoding::unicodeToByte(string $unicode): int|null`.

Both classes are pure value objects with no dependencies on `PDFDoc` / `PDFObject` — they take bytes in and return Unicode (or vice versa). Upstream-friendly shape.

### D2 — Font resolver lives on `PDFDoc`

Add `PDFDoc::resolveFontMap(int $oid, string $fontResourceName): array{forward: callable, reverse: callable, name: string}|null`. Caller passes the active page's font resource name (e.g. `F1` from `/F1 12 Tf`) plus the page object's OID; the resolver walks `/Resources/Font/<name>`, locates the `/ToUnicode` stream (if any), parses it via `CMap::fromStream`, and falls back to `FontEncoding::forName($baseEncoding)` for simple fonts without a CMap.

### D3 — Matching algorithm

For each content stream we want to replace text in:

1. Tokenise the stream's operator sequence (simple state machine: split on whitespace + operator names; preserve the operand sequences). This is the same parser depth we already need for `Tf` tracking in the PoC.
2. Walk the operator list, tracking the current font (`Tf` sets it). For each text-showing operator (`Tj`, `'`, `"`, and the string fragments inside `TJ`), record `(operator_index, source_byte_start, source_byte_end, resolved_unicode)`.
3. Concatenate the resolved Unicode in order. Find the needle's start + end position in the concatenated string.
4. Map the position back to a (start_operator_index, start_op_byte_offset, end_operator_index, end_op_byte_offset) span.
5. Emit the placeholder: encode the placeholder Unicode through the active font (resolved from the operator at the match's start). If the font's forward map can't encode every character of the placeholder, record `font_encoding_misses` and skip.
6. Splice the new operator sequence into the content stream, replacing all source bytes that contributed to the matched span.

### D4 — Multi-font matches choose the start-font as authoritative

If the matched text spans multiple `Tf` switches (rare but possible — "Jan" in one font, " " in another, "Jansen" in a third), we use the FIRST font in the span to encode the placeholder, emit a single `Tj` operator, and elide the intermediate `Tf` switches inside the match range. The remaining `Tf` switches outside the match are preserved.

This choice is conservative: it always produces a syntactically valid content stream. Visual quality may degrade slightly (the placeholder uses one font even if the original text used several), but our placeholder format `[PERSON: 7]` is intentionally synthetic and doesn't pretend to match the document's typography.

### D5 — Multi-codepoint ToUnicode targets are flattened to NFC

ToUnicode CMaps can map a CID to a hex string of even length > 4, denoting a sequence of Unicode codepoints (ligatures: `ﬁ` → `fi`; decomposed accented characters: `é` → `é`). We flatten these to NFC (Unicode Normalisation Form C) before matching. The needle is also NFC-normalised before the search. This handles the case where `ﬁ` appears in the PDF but the operator searched for `fi`.

### D6 — Diagnostic surface is purely additive

`replaceTextInDocument` returns the same array shape as the PoC plus a new optional key `font_encoding_misses: array<int oid, array<string needle, string font_name>>`. Callers that don't inspect the new key see no behavioural change.

## Risks / Trade-offs

- **Risk**: ToUnicode CMap syntax has corners (nested `beginbfrange` blocks, comment lines, `usecmap` references to other CMaps). The Word-emitted shape uses only `beginbfchar` and `beginbfrange`. → **Mitigation**: implement only the Word-emitted shape; reject other shapes with `p_error` and a stream-unchanged failure. Document the gap. PRs `#07` / `#08` don't need the corners; we can fill them in later if production PDFs surface them.

- **Risk**: Multi-codepoint ToUnicode targets and NFC normalisation interact badly with naive substring search (a 1-CID source could expand to N Unicode codepoints; a match boundary can split a CID). → **Mitigation**: index every text-showing operator output with `(source_byte_offset, unicode_codepoint_offset)` pairs; only allow matches that align with CID boundaries. Skip and diagnose a `cid_split_mismatch` if the match would cross a CID interior.

- **Risk**: Subset fonts often omit Unicode characters that aren't in the original document. The placeholder `[PERSON: 7]` uses `[`, `]`, `:`, digits, and capital letters — common but not guaranteed. → **Mitigation**: emit `font_encoding_misses` for the unencodable characters; upstream-PR #08 adds the fallback. This PR ships a usable feature for documents where the subset font covers the placeholder.

- **Trade-off**: Building per-page font resolution maps is non-trivial in PDF terms — fonts live in inheritable resources that can come from `/Resources` on the page itself, the page tree's `/Resources`, or a `/Form` XObject's `/Resources`. → **Mitigation**: walk the inheritance chain (page → parent pages → catalog) in `resolveFontMap`. Restrict to direct page-level resources for the first pass; widen if real-world fixtures need it.

## Migration Plan

The PoC's byte-level matcher continues to work on WinAnsi-encoded streams as a degenerate case (the FontEncoding tables for `/WinAnsiEncoding` are 1-byte = 1-codepoint, so text-space matching collapses to byte-space matching). No consumer code changes required.

For OpenRegister: the `pdf-anonymisation` change's existing test fixture (`testdoc.pdf`, which uses Identity-H subset Helvetica) starts producing correct redacted output after this PR lands.

Rollback: revert the commit. The PoC byte-level path is preserved as the fallback when the matched stream has no `Tf` operators (unusual but spec-valid; we treat the whole stream as byte-space).

## Open Questions

- **OQ1**: When a font has no `/ToUnicode` CMap AND no recognisable implicit encoding (custom `/Differences` array, custom CIDFont), should we ATTEMPT a best-effort match by treating bytes as Latin-1 (matches the original PoC behaviour) or skip the stream entirely? **Provisional**: skip the stream and emit a `font_encoding_unknown` diagnostic. False matches on unknown encodings could redact innocent bytes; safer to skip.

- **OQ2**: Should we cache parsed CMaps across `replaceTextInDocument` calls? **Provisional**: yes, inside `PDFDoc` (per-document cache). CMaps are immutable for the document's lifetime; parsing them is O(N) in CMap stream size and a single Word document can reference 5-10 fonts.

- **OQ3**: Identity-V — same code path as Identity-H? **Provisional**: yes, identical for the matching layer (the V/H difference is glyph-positioning, which we don't touch). Confirm with a real-world V fixture if one appears.
