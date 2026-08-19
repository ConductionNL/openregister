# Proposal — `replaceTextInDocument()` flagship API

## Why

Closes the text-replacement series. Where PRs #01–#07 added foundation primitives (filter decoders, filter chaining, font-encoding resolver, TJ-flattening helper), this PR adds the user-facing API that composes them into a single high-level operation: "replace these Unicode strings throughout this PDF's content streams."

The downstream consumer is GDPR anonymisation in `ConductionNL/openregister`'s `pdf-anonymisation` change — operator-supplied entities (names, BSNs, organisation references) get replaced with identifiable placeholders so the resulting PDF can be published under the Dutch Wet Open Overheid (Woo) framework without leaking PII.

This is the **flagship PR** in the series. It's posted LAST so:
- The foundation pieces (#01–#07) have already shipped or are in flight — maintainer trust is established.
- Working downstream code (the OR-side `pdf-anonymisation` PoC) demonstrates the API's value end-to-end.
- The API surface can be reviewed against a real consumer rather than against speculation.

## What Changes

- **NEW method** `PDFDoc::replaceTextInDocument(array $substitutions, array $options = []): array`. Public, on `ddn\sapp\PDFDoc`.
  - `$substitutions`: `array<string $needle, string $replacement>` — operator-supplied Unicode-string mappings.
  - `$options`: see `design.md` D2 for the options dictionary.
  - Returns `array` with diagnostic info: replacement counts, per-font counts, unmatched substitutions, bytes changed.
- **NEW helper** `ddn\sapp\fonts\BaseFontFallback::register(PDFDoc $doc, string $base_font_name): string` — registers one of the 14 PDF standard fonts (default Helvetica) as a fallback font resource in every page's `/Resources/Font` dictionary. Used by the API for placeholder rendering. Returns the resource name (e.g. `/F-Replacement`).
- **NEW exception** `ddn\sapp\Exceptions\TextReplacementException` — thrown on unrecoverable conditions (encrypted PDF, unsupported font scheme without `/ToUnicode`, etc.).
- **NO change** to the existing public API surface — `replaceTextInDocument` is additive.

## Composition

```
$doc->replaceTextInDocument($substitutions, $options)
   │
   ├─► For each page's content streams:
   │     │
   │     ├─► get_stream(false)            ← uses PR #01–#04 decoders + PR #05 chaining
   │     ├─► TextContentStreamFlattener   ← PR #07
   │     │
   │     ├─► FontEncodingResolver         ← PR #06
   │     │     For each font, build unicodeToBytes() callable.
   │     │
   │     ├─► For each Tj in the flattened stream:
   │     │     - Find longest-matching substitution per current font's byte sequences
   │     │     - On match: emit /F-Replacement <size> Tf (placeholder) Tj /<original> <size> Tf
   │     │     - On no match: emit unchanged
   │     │
   │     ├─► Post-pass: collapse adjacent-duplicate placeholders (if option enabled)
   │     ├─► Re-encode stream (set_stream)  ← inverse of get_stream pipeline
   │     └─► Write back to PDFObject
   │
   ├─► Register `/F-Replacement` (Helvetica) in every page's /Resources/Font (once per doc)
   ├─► Rebuild xref (to_pdf_file_s(true))
   └─► Return diagnostics
```

Every primitive in the composition is a separately-merged PR in the series. The flagship PR's role is the orchestration + the options surface + the diagnostics shape.

## Impact

- **Spec target:** none directly — the API is a SAPP-specific addition. Indirectly references PDF 1.7 §7.3 (operators), §9.4 (text objects), §9.6 (fonts).
- **Unlocks:** the GDPR anonymisation use case at OpenRegister, and any other consumer needing text replacement in PHP-based PDF tooling.
- **Out of scope:**
  - Image-only filters (`/DCTDecode` etc.) — content streams using only these have no text to replace; the API skips them silently.
  - Form XObjects — the API only walks page content streams; nested Form XObjects are not recursed into for v1.
  - Annotation text contents — separate concern; documented as out-of-scope.
  - Bookmark / outline entries — same.
  - Per-character font-size matching — the placeholder uses the dominant font size at the match position.
  - Encrypted PDFs — throws `TextReplacementException` with a clear message.

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-text-replacement-api/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/text-replacement/spec.md`). Key as-shipped facts: public method is `replace_text_in_document` (snake_case per upstream convention); Helvetica subset-font fallback q/Q-wraps placeholders the active font cannot encode (resource name `/F-fb-anonym`, collision-handled); 12-key diagnostic surface frozen and locked via `@phpstan-type ReplaceTextStats`; input validation rejects empty needles + placeholders containing control chars or `()\`.
