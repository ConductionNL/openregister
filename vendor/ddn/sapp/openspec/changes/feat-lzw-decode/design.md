## Context

LZW decode (§7.4.4.2):

- Initial dictionary has 256 entries (one per byte value) plus the two reserved codes (256 = clear, 257 = EOD). Code width starts at 9 bits.
- Read variable-width codes from the input bit stream MSB-first.
- On clear code (256): reset the dictionary to its initial 258-entry state, set code width back to 9 bits, continue.
- On EOD code (257): stop.
- On a user code (≥ 258): look up the string in the dictionary, emit it, append `prev_string + first_byte_of_current_string` to the dictionary, advance code width when the dictionary fills.
- On the special "code is exactly one past the last added code" case: the entry doesn't exist yet — emit `prev_string + first_byte_of_prev_string`.

`EarlyChange` parameter:

- `1` (default per §7.4.4.3 Table 8): bit width increases when the dictionary index reaches `2^width - 1` (the LAST entry of the current width is unused before advancing).
- `0`: bit width increases when the dictionary index reaches `2^width` (the full current width is used).

PNG predictor support is identical to `FlateDecode` (§7.4.4.4) — same predictor algorithm, applied after decompression.

## Goals / Non-Goals

**Goals:**

- Lossless round-trip on arbitrary binary input.
- Correct handling of clear codes and dictionary overflow at 12 bits.
- `EarlyChange` parity with both values (`0` and `1`).
- PNG predictor support matching `FlateDecode`.

**Non-Goals:**

- TIFF-style differencing predictor (PDF 1.7 §7.4.4.4 lists it but it's rare in PDFs — implement only if a real-world fixture demands it).
- Streaming / incremental decode.

## Decisions

### D1 — Mirror upstream's filter helper shape

`protected static function LZWDecode($_stream, $params)` and `protected static function LZWEncode($_stream, $params)` as static helpers on `PDFObject`.

### D2 — Bit-stream helpers as private static methods

Add `protected static function lzw_read_code(string $stream, int $bitPos, int $codeWidth): int` and a corresponding write helper. These are LZW-specific because the MSB-first packing order matters for compatibility with Acrobat-emitted streams.

### D3 — Dictionary as a flat PHP array indexed by code

Code `c` maps to string `$dict[$c]`. Init with `chr(0)` through `chr(255)` for the first 256 entries; reserve indices 256–257 for clear/EOD; user entries start at 258. Cap dictionary growth at 4096 entries (the 12-bit code-width ceiling).

### D4 — Reuse `FlateDecode`'s PNG predictor implementation

Refactor the predictor logic in the existing `FlateDecode` method into a private `protected static function applyPngPredictor(string $bytes, array $params): string|false` helper, then call it from both `FlateDecode` and `LZWDecode`. Pure refactor, no behaviour change for FlateDecode callers (validated by the existing PoC verify).

### D5 — `EarlyChange = 1` default, honour `EarlyChange = 0` if specified

The default per spec is `1`; we honour the value passed via `/DecodeParms` and fall back to `1` when unset. Encode emits with the same `EarlyChange` value to make the round-trip lossless.

### D6 — Fail-safe on dictionary overflow

If the bit stream demands codes after the dictionary is full at 4096 entries without a clear code, `p_error()` is called and the raw input is returned. This is the recovery path for malformed streams — Acrobat handles this gracefully but corrupted streams hit it.

## Risks / Trade-offs

- **Risk**: LZW patent expired in 2003 but lingering FUD might make upstream sapp reviewers cautious. → **Mitigation**: link to the patent-expiry references in the upstream PR description; PDF 1.7 §7.4.4 is the normative reference.

- **Risk**: `EarlyChange = 1` is subtle — bit-width advances happen one code earlier than the naive "fill the width" rule. Easy to get wrong; affects compatibility with every other PDF reader. → **Mitigation**: lock the behaviour with a fixture decoded by Adobe Reader; the round-trip test ensures we encode and decode using the same rule.

- **Trade-off**: ~180 LOC is large for a single change. → **Mitigation**: the LZW state machine doesn't decompose into smaller PRs without breaking — the encoder, decoder, and predictor reuse all need to land together for a usable round-trip.

## Open Questions

- **OQ1**: TIFF predictor (`Predictor` values 2 and 3) — implement now or defer? **Provisional**: defer until a real-world fixture demonstrates the need. PNG predictor (values 10-15) covers the modern case.

- **OQ2**: Should the encoder emit a clear code at the start of every stream (Acrobat-compatible) or only on overflow (minimal-output)? **Provisional**: emit on the start (Acrobat-style). Adds ~2 bytes but maximises reader compatibility.

## Migration Plan

Strictly additive change. No migration required for consumers — string-form callers (and unaware-of-this-change callers) observe byte-for-byte identical behaviour after merge. Rollback path is a clean revert of the commit.

