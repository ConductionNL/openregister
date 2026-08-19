---
status: draft
target_repo: dealfonso/sapp
suggested_title: Add /ASCII85Decode filter support
suggested_labels: enhancement
relates_to: openregister/pdf-anonymisation
prereq: 01-asciihex-decode, 02-runlength-decode
---

# Upstream issue draft — `/ASCII85Decode`

**Intended workflow:** post AFTER 01 and 02 — by this point the maintainer's pattern for filter additions is clear.

**Posted at:** _(fill in once posted)_

---

## Issue body (copy from here)

## Summary

Add `/ASCII85Decode` to the supported stream filters in `PDFObject::get_stream()` + `PDFObject::set_stream()`. PDF 1.7 §7.4.3. Third in the small filter-decoder series; see the `/ASCIIHexDecode` issue for the broader plan.

## Why this filter

`/ASCII85Decode` is a base-85 binary-to-text wrapper. Frequency in Woo PDFs is low single digits in our sample, but it's often paired with `/FlateDecode` in chains (`/Filter [/ASCII85Decode /FlateDecode]`) — adding it independently keeps the per-PR scope small. A separate issue for filter chaining will follow once the individual decoders are in.

## Proposed API

Mirror the established pattern from PRs #N/#M:

```php
protected static function ASCII85Decode($_stream) {
    // PDF 1.7 §7.4.3:
    //   - Bytes are grouped into 5-character ASCII85 chunks decoding to 4 bytes each
    //   - 'z' shorthand for 0x00 0x00 0x00 0x00
    //   - '~>' is the EOD marker
    //   - Whitespace within the stream is allowed and must be stripped

    $cleaned = preg_replace('/\s+/', '', $_stream);
    $eod = strpos($cleaned, '~>');
    if ($eod !== false) {
        $cleaned = substr($cleaned, 0, $eod);
    }

    $decoded = '';
    $len = strlen($cleaned);
    $i = 0;
    while ($i < $len) {
        if ($cleaned[$i] === 'z') {
            $decoded .= "\x00\x00\x00\x00";
            $i++;
            continue;
        }
        $chunk = substr($cleaned, $i, 5);
        $chunk_len = strlen($chunk);
        if ($chunk_len < 2) {
            return p_error('ASCII85Decode: incomplete final group');
        }
        // Pad short final group with 'u' (the highest valid ASCII85 char).
        $chunk = str_pad($chunk, 5, 'u');
        $value = 0;
        for ($j = 0; $j < 5; $j++) {
            $c = ord($chunk[$j]) - 33; // ASCII85 charset starts at '!' (0x21).
            if ($c < 0 || $c > 84) {
                return p_error('ASCII85Decode: invalid character in stream');
            }
            $value = $value * 85 + $c;
        }
        $bytes = pack('N', $value);
        // Trim the padding bytes from the final group.
        $decoded .= substr($bytes, 0, $chunk_len - 1);
        $i += 5;
    }
    return $decoded;
}
```

The encode path emits `'~>'` EOD; for empty final groups it emits no trailing chars.

## Acceptance test

- Decode a fixture stream with `/Filter /ASCII85Decode` → correct bytes.
- Round-trip via `set_stream`/`get_stream`.
- Edge cases: `z` shorthand expands to four zero bytes; whitespace within the stream is correctly stripped; short final group decodes correctly; invalid character returns `p_error`.

## Out of scope

- Filter chaining (`/Filter [/X /Y]`) — separate refactor.
- Other decoders — separate issues.

## (copy ends)
