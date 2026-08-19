---
status: draft
target_repo: dealfonso/sapp
suggested_title: TJ kerning-array flattening helper
suggested_labels: enhancement
relates_to: openregister/pdf-anonymisation
prereq: 05-filter-chaining
---

# Upstream issue draft — TJ kerning-array flattening

**Intended workflow:** post AFTER filter chaining (05) lands. This is a small PR conceptually but touches the content-stream parsing surface; helps to have the filter dispatch settled.

**Posted at:** _(fill in once posted)_

---

## Issue body (copy from here)

## Summary

Add a helper that walks PDF content streams and flattens per-character `TJ` kerning arrays into single-string `Tj` operators. PDF 1.7 §9.4.3 (Text-Showing Operators); the `TJ` operator takes an array mixing strings and numeric kerning adjustments, e.g.:

```
[(J) 5 (a) -3 (n) 10 ( ) (J) -2 (a) -3 (n) 5 (s) -1 (e) -5 (n)] TJ
```

The output of the flattening helper for the above is:

```
(Jan Jansen) Tj
```

(All numbers discarded; strings concatenated.)

## Why this matters

Word-generated PDFs encode kerned body text as per-character TJ arrays. Substitution-map matching needs to operate on the logical text, not on per-character fragments — without flattening, searching for `"Jan Jansen"` in the example above finds nothing because no single string element contains the full match.

Trade-off: typographic kerning is lost in the flattened output. For body text in Word-generated Woo PDFs this is visually imperceptible (Word's kerning of regular-weight body text is minimal). For typographic publications that rely on per-character kerning for visual quality, the difference would be visible.

For the downstream use case (GDPR anonymisation of government correspondence), this is essentially loss-free. SAPP itself doesn't currently take any opinion on text-content fidelity, so the helper is a pure utility — consumers who DO care about kerning don't have to call it.

## Proposed shape

```php
namespace ddn\sapp\helpers;

class TextContentStreamFlattener {
    /**
     * Walk a content stream and rewrite TJ operators to Tj operators
     * with concatenated string contents and kerning numbers discarded.
     *
     * Other operators (Tj, Tf, Tm, TD, ...) pass through unchanged.
     */
    public static function flatten(string $content_stream): string {
        // Tokenise; for each TJ operator:
        //   - Parse the bracketed array
        //   - Concatenate string elements (preserving the original encoding;
        //     i.e. <hex> stays <hex>, (literal) stays (literal); pick one
        //     output form by inspecting the first string in the array)
        //   - Discard numeric (kerning) elements
        //   - Emit as `(...) Tj` or `<...> Tj`
        // Return the rewritten stream.
    }
}
```

The helper is a pure function over content-stream bytes — no PDFObject coupling, no document-wide state. Consumers (including the downstream `replaceTextInDocument`) call `flatten()` before running their own search/replace logic.

## Optional refinement

Per PDF 1.7 §9.4.3, large kerning numbers (|n| > 200) may represent intentional word-break spacing in justified text rather than typographic kerning. A flag `treat_large_kerning_as_space` (default false) can emit a single space character when encountering such numbers, preserving logical word boundaries that a strict-discard approach would lose.

v1 can ship with the flag absent / always-false (the strict-discard behaviour) and add it as a follow-up if real-world cases demonstrate degradation. Open to your preference on whether to include the flag from the start.

## Acceptance test

- `flatten("[(J) 5 (a) -3 (n) 10 ( ) (J) -2 (a)] TJ")` → `"(Janj a) Tj"` (or close — the test checks specific byte sequences).
- A content stream with a mix of TJ and Tj operators: TJ operators rewrite; Tj operators pass through unchanged.
- A content stream with no TJ operators: output byte-equal to input.
- TJ with hex strings `[<004A> 5 <0061> -3]`: output `<004A0061> Tj` (hex form preserved).
- TJ inside a graphics-state save/restore block: surrounding operators preserved.
- Edge case: empty TJ array `[] TJ` → either drop the operator (it has no effect) or emit `() Tj` (visually equivalent); doesn't matter for our use case, your call.

## Out of scope

- Other text-showing operators (`'`, `"`) — these don't take kerning arrays.
- TJ with embedded escape sequences in literal strings — handle them per the spec but don't reformat.
- Font / text-state tracking — the helper only rewrites TJ-array shape; it doesn't track `Tf` / `Tm` state changes (consumers do that themselves).

## (copy ends)
