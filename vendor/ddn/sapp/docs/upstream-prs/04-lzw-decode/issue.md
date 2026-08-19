---
status: draft
target_repo: dealfonso/sapp
suggested_title: Add /LZWDecode filter support
suggested_labels: enhancement
relates_to: openregister/pdf-anonymisation
prereq: 01-asciihex-decode, 02-runlength-decode, 03-ascii85-decode
---

# Upstream issue draft — `/LZWDecode`

**Intended workflow:** post LAST among the four decoder issues — it's the largest of the four (LZW algorithm + PNG predictor parity with the existing FlateDecode handling) and benefits most from social proof of the prior three landing cleanly.

**Posted at:** _(fill in once posted)_

---

## Issue body (copy from here)

## Summary

Add `/LZWDecode` to the supported stream filters in `PDFObject::get_stream()` + `PDFObject::set_stream()`. PDF 1.7 §7.4.4. Fourth in the filter-decoder series; see the `/ASCIIHexDecode` issue for the broader plan.

## Why this filter

`/LZWDecode` was the standard PDF compression filter pre-1.4 (1996-ish); modern PDFs use FlateDecode but legacy government-archive PDFs (and some scanning tools) still emit LZW-compressed streams. Frequency in our Woo PDF sample is <2%, but the discovery committed to comprehensive filter coverage.

LZW is bigger than the prior three decoders — variable-bit-width codes, a code table that grows during decompression — but the algorithm is well-defined and the implementation is ~80 lines of PHP. Several FOSS reference implementations exist (e.g. zen-pdf, `imagick`'s LZW path) we can cross-reference for correctness.

## Proposed API

Mirror the established pattern:

```php
protected static function LZWDecode($_stream, $params) {
    // PDF 1.7 §7.4.4:
    //   - Variable-bit-width codes starting at 9 bits, growing to 12 bits
    //   - Clear code (256) resets the table; EOD code (257) terminates
    //   - Initial table: 0-255 (literals), 256 (clear), 257 (EOD)
    //   - EarlyChange parameter (default 1) affects when the bit-width increases

    $early_change = ($params['EarlyChange']->get_int() ?? 1);
    
    // ... LZW decompression loop, returns the decoded bytes
    
    // After LZW: if the Predictor parameter is set, apply PNG predictor
    // row reconstruction (identical to the existing FlateDecode predictor path).
    // Factor that predictor logic into a shared helper or call into FlateDecode's
    // existing implementation directly.
    
    return $decoded;
}
```

A clean factoring: extract the PNG-predictor logic from `FlateDecode` into a shared `protected static function ApplyPngPredictor($data, $params)` helper, so both `FlateDecode` and `LZWDecode` reuse it. That refactor is part of this PR.

Encode path (set_stream): for parity, accept LZW-encoded output. This is less commonly needed (writes default to FlateDecode in modern code) but worth including for round-trip integrity.

## Acceptance test

- Decode an LZW-encoded fixture stream → correct bytes.
- Decode an LZW + PNG-predictor stream → byte-equal to the predictor-only path applied to the LZW output.
- Round-trip via `set_stream`/`get_stream`.
- Edge cases: clear code mid-stream resets the table; EOD code terminates; variable-width code transition at the table-full boundaries.

## Out of scope

- Filter chaining — separate refactor.
- Other decoders — already in earlier issues.
- `EarlyChange = 0` mode (rare; we can support it but `EarlyChange = 1` is the standard).

## Refactor note

This PR likely extracts the PNG-predictor logic from `FlateDecode` into a shared helper as part of the change. Happy to split into two PRs if you prefer: (a) refactor PNG predictor into a helper (no behaviour change), (b) add `LZWDecode` consuming the helper. Lower-risk staging, slightly more review overhead.

## (copy ends)
