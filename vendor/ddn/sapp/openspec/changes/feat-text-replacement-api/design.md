## Context

PDF 1.7 §9.6.2.2 defines a "standard 14" set of fonts — Helvetica, Helvetica-Bold, Helvetica-Oblique, Helvetica-BoldOblique, Times-Roman (and its variants), Courier (and its variants), Symbol, ZapfDingbats — that every conformant reader MUST support without an embedded font program. They take a minimal Type1 font dictionary:

```
<<
  /Type /Font
  /Subtype /Type1
  /BaseFont /Helvetica
  /Encoding /WinAnsiEncoding
>>
```

This is the same shape our PoC `poc-make-fixture.php` already uses. To use it as a placeholder font:

1. Add the dictionary as an object in the PDF (if not already present).
2. Add a reference to the new font under `/Resources/Font/<our-name>` on the page being modified (or its inherited Resources dictionary).
3. Wrap the placeholder emission in `q ... Q` so the original font, font size, and any other graphics-state knobs are restored.

The `q/Q` pair (PDF 1.7 §8.4.4 ¶3) saves/restores the entire graphics state stack including the current font (`Tf` state), font size, text matrix, and rendering mode. So `q\n/F-fallback 12 Tf\n(placeholder) Tj\nQ` is locally scoped — the next operator after `Q` sees the original font as if nothing happened.

## Goals / Non-Goals

**Goals:**

- Recover the substitution that upstream-PRs #06 / #07 skip due to subset-font encoding misses.
- Produce a content stream that renders correctly in every PDF reader (Adobe, Foxit, pdf.js, poppler, Preview).
- Inject the fallback font resource at most once per page (idempotent).
- The polished `replaceTextInDocument` API is stable: same signature, same diagnostic-key naming, full PHPDoc — ready for the upstream submission.

**Non-Goals:**

- Visual matching with the original font. The placeholder is intentionally a synthetic marker; Helvetica is fine.
- Font-size matching. We hard-code 12pt; if the surrounding text was 10pt the placeholder will be slightly taller. Acceptable for an anonymisation marker.
- Helvetica-Bold / Helvetica-Italic variants. The single regular Helvetica covers the placeholder set we use.
- Vertical text fallback. Out of scope for the Woo use case.

## Decisions

### D1 — Synthesise the Helvetica font once per page, lazily

The resource injector tracks a per-page `$fallbackFontInjected` boolean. On the first match in a page that requires the fallback, it:

1. Creates a new `PDFObject` for the Helvetica dictionary via `$pdfDoc->create_object($value)` (existing API).
2. Adds `/F-anonymisation-fallback` as a key under the page's `/Resources/Font` dictionary, pointing at the new object via an indirect reference.
3. Sets `$fallbackFontInjected = true` for the page so subsequent matches reuse the same resource.

The resource name `/F-anonymisation-fallback` is namespaced enough to avoid collisions with the document's existing font resource names (which are typically `/F1`, `/F2`, etc.). If a document happens to have a real `/F-anonymisation-fallback` (vanishingly unlikely), we collide-detect and pick `/F-anonymisation-fallback-2`, `-3`, ...

### D2 — Fallback wraps the placeholder in `q/Q`

The splicer emits `q\n/F-anonymisation-fallback 12 Tf\n(placeholder) Tj\nQ`. The `q` pushes the graphics state; the `Q` pops it. Anything that comes after the `Q` operates under the original font/size, so the rest of the line (or the rest of the TJ array's surviving fragments) renders correctly.

### D3 — Inherited resources require placeholder-page-level injection

If the page being modified doesn't have its own `/Resources` (it inherits from a parent), we have to promote the resources dictionary to the page level so we can add our fallback font without affecting siblings. The promotion is: copy the inherited resources dict to the page's `/Resources`, then add our fallback font there. Sibling pages continue to use the original inherited resources unchanged.

### D4 — Fallback ONLY fires when the active font can't encode

We do NOT use the fallback as the default. The active-font-first rule from upstream-PR #06 is still primary; the fallback is only the recovery path. This minimises the visual disruption (a placeholder in the document's actual font is preferable to a placeholder in Helvetica when both are options).

### D5 — `subset_font_fallbacks_used` is the diagnostic counter

The returned diagnostic surface gains `subset_font_fallbacks_used: int` — counts the number of placeholder emissions that used the fallback. Combined with `font_encoding_misses` (which now ONLY contains matches that BOTH the active font AND the fallback couldn't encode — very rare, requires the placeholder to use characters outside Helvetica's WinAnsi encoding), this gives operators a clear picture of how the substitution played out.

### D6 — `replaceTextInDocument` parameter validation

The polished API rejects:

- Empty-string needles (would match everywhere, almost certainly a caller bug)
- Placeholders containing the bytes `\x00` through `\x1F` (control characters — PDF 1.7 string-literal rules make these awkward)
- Placeholders containing `(`, `)`, or `\` (require PDF-string-escape handling — out of scope for this PR; can re-enable later if a use case demands)

Rejection emits `p_error` with the offending entry and skips that single substitution; other substitutions in the same call proceed.

### D7 — Final API documentation

Full PHPDoc on `replaceTextInDocument` including:

- Parameter types and constraints
- All diagnostic keys with their semantics
- Worked example
- Reference to the OpenSpec changes that built up the contract (`feat-filter-chain-dispatch` through `feat-text-replacement-api`)
- PDF 1.7 spec section references

## Risks / Trade-offs

- **Risk**: Adding a font resource to an existing PDF could trigger validation failures in strict readers. → **Mitigation**: the standard 14 fonts don't require font programs and have been a PDF spec promise since PDF 1.0. Every reader handles this case. Add a real-world-reader spot-check task.

- **Risk**: Multiple `replaceTextInDocument` calls on the same `PDFDoc` instance must not inject the resource twice. → **Mitigation**: D1's per-page idempotency boolean handles this. Add a regression test for "call replaceTextInDocument twice".

- **Trade-off**: Promoting inherited resources to the page level (D3) duplicates resource dict bytes for affected pages. → **Mitigation**: only fires when a fallback is actually used; for pages without subset-font encoding misses (the common case once OpenRegister documents start being modern), the promotion never happens.

## Migration Plan

For the upstream contribution: the public API contract this PR sets is what gets submitted to `dealfonso/sapp`. The upstream PR description references each of the 8 OpenSpec changes as the contract chain.

For OpenRegister: the existing `pdf-anonymisation` change starts producing fully-redacted output across all Woo fixtures once this PR lands. The diagnostic surface stays backwards-compatible — consumers that read only the PoC's original keys continue to work.

Rollback: revert the commit. Helvetica fallback feature unavailable; matcher still works but skips subset-font-encoding-miss cases.

## Open Questions

- **OQ1**: Should we expose font choice (`Helvetica` vs `Courier`) as an option in the public API? **Provisional**: no. One sensible default keeps the API tight. Internal call sites that want a different font can edit the synthesiser directly.

- **OQ2**: Placeholder size — hard-coded 12pt or auto-detected from surrounding text? **Provisional**: hard-coded 12pt. Auto-detection requires parsing the current Tf state from the operator history, which is doable but adds complexity for marginal visual benefit on an explicitly synthetic marker.
