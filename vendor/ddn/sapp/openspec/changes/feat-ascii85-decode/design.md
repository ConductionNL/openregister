## Context

ASCII85 base-85 encoding maps 4 binary bytes (interpreted as a 32-bit unsigned big-endian integer `n`) to 5 ASCII characters via `n = c0 * 85^4 + c1 * 85^3 + c2 * 85^2 + c3 * 85 + c4` where each `ci` is in `[0, 84]` and emitted as `ci + 33` (codepoint range `!..u`).

Special cases:

- Single character `z` decodes to 4 zero bytes (compact form for runs of zeros — common in image data).
- EOD marker `~>` terminates decoding.
- Whitespace anywhere is ignored.
- Trailing partial groups: a group of `k` ASCII chars (where `2 ≤ k ≤ 4`) decodes to `k - 1` binary bytes, padded with `u` (codepoint 117 = value 84) to a full 5-char group before computing.

## Goals / Non-Goals

**Goals:**

- Lossless round-trip on arbitrary binary input.
- Honour the `z` shortcut on both encode and decode.
- Tolerate whitespace anywhere in the encoded stream.

**Non-Goals:**

- The alternative "btoa version 4.2" syntax with `<~` start marker — PDF 1.7 §7.4.3 doesn't use it and Adobe Acrobat doesn't emit it.
- ASCII85 in other contexts (PostScript level-2 has a slightly different EOD convention).

## Decisions

### D1 — Mirror upstream's filter helper shape

`protected static function ASCII85Decode($_stream, $params)` and `protected static function ASCII85Encode($_stream, $params)` as static helpers on `PDFObject`.

### D2 — Use PHP's native arithmetic, not `gmp` or `bcmath`

The 32-bit big-endian integer never exceeds `2^32 - 1`. PHP's `int` type is at least 32-bit (64-bit on 64-bit platforms, which sapp's `composer.json` requires via the PHP 7.4 floor). No external math extension needed.

### D3 — Encode emits `z` for runs of 4 zero bytes (greedy, aligned)

The encoder emits `z` only when 4 consecutive zero bytes are aligned on a group boundary. Unaligned zero runs go through the standard 5-char encoding. This matches Adobe's de-facto encoding behaviour and keeps the implementation simple.

### D4 — Decode rejects spec violations via `p_error` + `false` return

Spec-violations — illegal character (codepoint outside `!..u`), `z` mid-group, `~` not paired with `>` as EOD, 1-char trailing partial group (§7.4.3 requires `2 ≤ k ≤ 4`), overflow beyond `2^32 - 1`, or PCRE compile failure on the whitespace-strip regex — cause `p_error()` and a return of `false`. This matches the chain dispatcher's `=== false` short-circuit contract (so downstream filters never see partial output), and aligns with the same fix applied to ASCIIHexDecode and RunLengthDecode in the predecessor PRs.

### D5 — Decode tolerates missing EOD (Adobe-compatible)

If the stream ends without a `~>` marker, the decoder finishes the last partial group (treating trailing `u`s as padding) and returns the result. Adobe Reader does this. Spec is mildly ambiguous on whether EOD is mandatory; the lenient interpretation is reader-compatible.

## Risks / Trade-offs

- **Risk**: A 5-char group might overflow `2^32 - 1`. → **Mitigation**: the spec-imposed maximum is `s8W-!` = `0xFFFFFFFF` = `2^32 - 1`. Many other 5-char strings in the `!..u` alphabet compute higher and are spec-illegal — notably `uuuuu` arithmetically yields `84*(85^4 + 85^3 + 85^2 + 85 + 1)` = 4,437,053,124, which is 142,085,829 above the cap. The decoder rejects any group whose computed value exceeds `2^32 - 1` via the `p_error` + `return false` path. PHP's 64-bit `int` (guaranteed by the upstream composer constraint on any supported platform) handles the arithmetic without overflow.

- **Trade-off**: Greedy z-encoding leaves a few bytes on the floor when zero runs straddle group boundaries. → **Mitigation**: documented; in practice this matters only for image data which we don't anonymise.

## Open Questions

- **OQ1**: Some readers tolerate the `<~` start marker (btoa convention). PDF 1.7 §7.4.3 doesn't mention it. Reject or tolerate? **Provisional**: tolerate on decode (strip the leading `<~` if present); never emit on encode. Maximum reader-compatibility on input; spec-faithful on output.

## Migration Plan

Strictly additive change. No migration required for consumers — string-form callers (and unaware-of-this-change callers) observe byte-for-byte identical behaviour after merge. Rollback path is a clean revert of the commit.

