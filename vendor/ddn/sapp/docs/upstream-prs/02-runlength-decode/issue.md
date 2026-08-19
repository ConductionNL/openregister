---
status: draft
target_repo: dealfonso/sapp
suggested_title: Add /RunLengthDecode filter support
suggested_labels: enhancement
relates_to: openregister/pdf-anonymisation
prereq: 01-asciihex-decode
---

# Upstream issue draft — `/RunLengthDecode`

**Intended workflow:** post AFTER the asciihex PR (#01) has been opened (or merged) — that confirms the maintainer's preferred shape for new filter decoders. Same body, with the title / decoder swapped.

**Posted at:** _(fill in once posted)_

---

## Issue body (copy from here)

## Summary

Add `/RunLengthDecode` to the supported stream filters in `PDFObject::get_stream()` + `PDFObject::set_stream()`. PDF 1.7 §7.4.5. Second of a small series of filter additions tracked under the same downstream use case (text replacement for GDPR anonymisation); see the `/ASCIIHexDecode` issue for the broader plan.

## Why this filter

`/RunLengthDecode` is a trivial RLE format defined in the PDF spec. It's rare in Woo PDFs (<1% in our sample), but the discovery work upstream of this issue committed to "all five text-relevant filters MUST be supported" — even a tiny minority of files shouldn't silently fail anonymisation. Adding it now (small, fast review) closes the gap before we hit it in production.

## Proposed API

Mirror the `/ASCIIHexDecode` pattern (or your preferred shape from PR #N — happy to align):

```php
// New static helper, sibling of FlateDecode() / ASCIIHexDecode():
protected static function RunLengthDecode($_stream) {
    $decoded = '';
    $i = 0;
    $len = strlen($_stream);
    while ($i < $len) {
        $length_byte = ord($_stream[$i++]);
        if ($length_byte === 128) {
            // EOD marker.
            break;
        }
        if ($length_byte < 128) {
            // Copy the next (length_byte + 1) bytes literally.
            $count = $length_byte + 1;
            $decoded .= substr($_stream, $i, $count);
            $i += $count;
        } else {
            // Repeat the next byte (257 - length_byte) times.
            if ($i >= $len) {
                return p_error('RunLengthDecode: unexpected end of stream after RLE count');
            }
            $count = 257 - $length_byte;
            $decoded .= str_repeat($_stream[$i], $count);
            $i++;
        }
    }
    return $decoded;
}

// New cases in get_stream() / set_stream() switch statements.
```

The encode path (for `set_stream`) is the inverse: scan for runs of >= 2 identical bytes, emit the repeat marker; emit literal-run markers between runs. ~20 lines of code total.

## Acceptance test

- Decode a fixture stream with `/Filter /RunLengthDecode` → produces correct bytes.
- Round-trip: parse, modify with `set_stream(..., false)`, re-parse → byte-equal to expected.
- Edge cases: EOD marker (0x80) terminates correctly; truncated stream produces `p_error` (no crash).

## Out of scope

- Combining with other filters (chaining) — separate refactor issue planned.
- All other filter decoders — separate issues planned in this series.

## Ask

Same scope question as the asciihex PR: extending the existing `get_stream` / `set_stream` switch or extracting filters into a registry — whichever you prefer. Defaulting to "switch case" for consistency with the existing FlateDecode.

## (copy ends)
