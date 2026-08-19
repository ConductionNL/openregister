**Status**: planned
**Scope**: change `feat-lzw-decode` (delta spec)
**OpenSpec changes**:
- `feat-lzw-decode` (in-progress)

## Purpose

Capability contract for `lzw-decode-filter` — the normative SHALL/MUST
behaviour the change must deliver. Scenarios below are the
acceptance criteria; tasks under the change's `tasks.md` reference
these requirements by REQ-NNN id.

## Non-Functional Requirements

- PHP >= 7.4 compatibility per upstream sapp's composer constraint.
- Zero new composer dependencies.
- snake_case method names on new utility helpers; PascalCase on
  filter names (matches the existing `FlateDecode` convention).
- All round-trip scenarios MUST be lossless byte-for-byte unless
  the spec explicitly documents a deviation.

## Acceptance Criteria

- Every requirement below MUST be exercised by at least one scenario
  in the change's verification gate under `examples/`.
- The change's `tasks.md` MUST cite each REQ-NNN it implements.
- Existing verification gates (PoC and prior changes) MUST remain
  green after the change lands.

## Notes

This is a delta spec — the canonical spec will be at
`openspec/specs/lzw-decode-filter/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: LZWDecode SHALL decode per PDF 1.7 §7.4.4

The decoder MUST read variable-width codes (9–12 bits) MSB-first from the input bit stream, maintain a dictionary starting with the 256 single-byte entries plus reserved codes 256 (clear) and 257 (EOD), and emit the dictionary string for each code per the standard LZW state machine.

#### Scenario: Standard LZW round-trip

- GIVEN the input is a 256-byte buffer encoded by `LZWEncode(...)` with `EarlyChange = 1` (default)
- WHEN `LZWDecode` is invoked
- THEN `LZWDecode(...)` MUST return the original 256-byte buffer byte-for-byte

#### Scenario: Clear code resets the dictionary

- GIVEN the encoded stream contains a clear code (256) mid-stream
- WHEN `LZWDecode` is invoked
- THEN the decoder MUST reset the dictionary to its initial 258-entry state and set the code width back to 9 bits before reading the next code

#### Scenario: EOD code halts decoding

- GIVEN the encoded stream contains the EOD code (257)
- WHEN `LZWDecode` is invoked
- THEN the decoder MUST stop and return the output accumulated so far
- AND any trailing bits MUST be ignored

#### Scenario: KwKwK special case

- WHEN the next code in the bit stream equals the dictionary's next-to-be-assigned index (the case where the dictionary entry doesn't exist yet at lookup time)
- THEN the decoder MUST emit `prev_string + first_byte_of_prev_string` per the standard LZW rule

### REQ-002: LZWDecode SHALL honour the `EarlyChange` parameter

The decoder MUST honour the `EarlyChange` entry from `/DecodeParms`. Default value is `1` per PDF 1.7 §7.4.4.3 Table 8.

#### Scenario: EarlyChange = 1 (default)

- WHEN the dictionary index reaches `2^width - 1` (one less than the full width capacity)
- THEN the decoder MUST increase the code width by 1 bit before reading the next code

#### Scenario: EarlyChange = 0

- WHEN `/DecodeParms` specifies `EarlyChange = 0` and the dictionary index reaches `2^width` (full width capacity)
- THEN the decoder MUST increase the code width by 1 bit before reading the next code

### REQ-003: LZWDecode round-trip MUST be lossless

For any input byte string `$P` and any valid `$params`, `LZWDecode(LZWEncode($P, $params), $params)` MUST equal `$P` byte-for-byte.

#### Scenario: Binary round-trip

- GIVEN the input is `random_bytes(2048)` and `$params` is `null`
- WHEN `LZWDecode` is invoked
- THEN the round-trip MUST equal the input byte-for-byte

#### Scenario: Round-trip with EarlyChange = 0

- GIVEN the input is `random_bytes(2048)` and `$params['EarlyChange']` is `0`
- WHEN `LZWDecode` is invoked
- THEN the round-trip MUST equal the input byte-for-byte

### REQ-004: LZWDecode SHALL support the PNG predictor scheme

When `/DecodeParms` specifies `Predictor >= 10` (PNG predictors), the decoder MUST apply the same PNG row-filter algorithm used by `FlateDecode`, parameterised by `Colors`, `BitsPerComponent`, and `Columns`.

#### Scenario: PNG predictor unchanged from FlateDecode

- WHEN `LZWDecode` is invoked with `Predictor = 12` and the same `Colors` / `BitsPerComponent` / `Columns` parameters as a `FlateDecode` call
- THEN the predictor output MUST match `FlateDecode`'s output byte-for-byte for an equivalent decompressed stream

### REQ-005: LZWDecode SHALL fail safely on malformed input

On any of the following malformed-input conditions, the decoder MUST call `p_error()` and MUST return `false` (matching `p_error()`'s default return + the chain dispatcher's `=== false` short-circuit contract; downstream filters MUST NOT observe a partially-decoded buffer):

1. Truncated bit stream (`lzw_read_code` exhausts the input before reading the EOD code 257).
2. Dictionary overflow at index 4096 without an intervening clear code (standard path).
3. Dictionary overflow at index 4096 during the KwKwK special case.
4. Out-of-range code (code is not yet assigned and is not `$nextCode` either).
5. Predictor rejection — `applyPngPredictor` returns `false` for an unsupported `Predictor` / `Colors` / `BitsPerComponent` setting, OR an unsupported per-row PNG filter byte (Paeth = 4 / Average = 3 currently rejected, mirroring the upstream `FlateDecode` limitation).

#### Scenario: Dictionary overflow

- GIVEN the input is a synthetic LZW stream crafted to produce more than 4096 distinct codes without a clear code
- WHEN `LZWDecode` is invoked
- THEN the decoder MUST call `p_error()`
- AND the return value MUST be `false`

#### Scenario: Truncated bit stream

- GIVEN the encoded stream ends before an EOD code (257) is read
- WHEN `LZWDecode` is invoked
- THEN the return value MUST be `false`

#### Scenario: Out-of-range code

- GIVEN the encoded stream contains a code beyond `$nextCode` (not yet assigned and not the KwKwK special case)
- WHEN `LZWDecode` is invoked
- THEN the return value MUST be `false`

#### Scenario: Predictor parameter rejection propagates

- GIVEN `/DecodeParms` specifies `Predictor = 15` (PNG auto) and `Colors = 3` (RGB — not supported in this version; the upstream `applyPngPredictor` allows only `Colors = 1`)
- WHEN `LZWDecode` is invoked
- THEN `applyPngPredictor` MUST return `false` and `LZWDecode` MUST propagate the `false` return rather than feeding the half-decoded buffer downstream

#### Scenario: Chain-failure propagation on outer-LZW layer

- GIVEN an object with `/Filter [/LZWDecode /FlateDecode]` and a raw `_stream` whose LZW layer is truncated
- WHEN `get_stream(false)` is invoked
- THEN the chain dispatcher MUST short-circuit at the LZW arm and return `false`; the inner `FlateDecode` arm MUST NOT see the partial buffer

## MODIFIED Requirements

### REQ-001: Array-form `/Filter` SHALL decode in forward chain order

The dispatcher MUST recognise `/LZWDecode` as a valid filter name and route to the LZWDecode helper, propagating `/DecodeParms` per the positional rule from `feat-filter-chain-dispatch`.

#### Scenario: LZW with PNG predictor

- WHEN an object has `/Filter /LZWDecode` and `/DecodeParms <</Predictor 12 /Columns 4>>`
- THEN `get_stream(false)` MUST apply LZW decode followed by PNG predictor inversion

#### Scenario: LZW outer + Flate inner chain

- WHEN an object has `/Filter [/LZWDecode /FlateDecode]`
- THEN the chain decode MUST apply LZW first, then Flate

### REQ-002: Array-form `/Filter` SHALL encode in reverse chain order

The dispatcher MUST recognise `/LZWDecode` on the encode path and route to the LZWEncode helper.

#### Scenario: LZW-only encode round-trip

- WHEN `set_stream($P, false)` is called on `/Filter /LZWDecode`, then `get_stream(false)` is called
- THEN the value returned by `get_stream(false)` MUST equal `$P`
