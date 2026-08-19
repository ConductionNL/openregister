**Status**: planned
**Scope**: change `feat-runlength-decode` (delta spec)
**OpenSpec changes**:
- `feat-runlength-decode` (in-progress)

## Purpose

Capability contract for `runlength-decode-filter` — the normative SHALL/MUST
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
`openspec/specs/runlength-decode-filter/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: RunLengthDecode SHALL decode per PDF 1.7 §7.4.5

The decoder MUST interpret each length byte `L` as: copy `L + 1` literal bytes if `0 ≤ L ≤ 127`, repeat the next byte `257 - L` times if `129 ≤ L ≤ 255`, halt at EOD if `L == 128`. Bytes after EOD MUST be ignored.

#### Scenario: Literal run

- GIVEN the input is `\x04Hello\x80` (length byte 4 = "copy 5 literals", then `Hello`, then EOD)
- WHEN `RunLengthDecode` is invoked
- THEN the decoder MUST return the 5-byte string `Hello`

#### Scenario: Repeat run

- GIVEN the input is `\xFAX\x80` (length byte 250 = "repeat next byte `257 - 250 = 7` times", then `X`, then EOD)
- WHEN `RunLengthDecode` is invoked
- THEN the decoder MUST return `XXXXXXX` (7 X's)

#### Scenario: Mixed literal + repeat

- GIVEN the input is `\x02ABC\xFEY\x80` (3 literal bytes `ABC`, then 3 `Y`s, then EOD)
- WHEN `RunLengthDecode` is invoked
- THEN the decoder MUST return `ABCYYY`

#### Scenario: EOD halts decoding

- GIVEN the input is `\x02ABC\x80garbage`
- WHEN `RunLengthDecode` is invoked
- THEN the decoder MUST return `ABC` and MUST NOT raise an error on the trailing `garbage`

### REQ-002: RunLengthEncode SHALL produce a valid round-trip-compatible stream

The encoder MUST emit a sequence of length-prefixed blocks (literal or repeat) terminated by the EOD byte `\x80`. The output MUST decode back to the exact input via the §7.4.5 decoder.

#### Scenario: Literal-only input

- GIVEN the input is a 5-byte string with no adjacent duplicates (e.g. `"abcde"`; note: `"Hello"` does NOT qualify — its `ll` pair is a 2-byte run that the greedy encoder flushes as a separate repeat block)
- WHEN `RunLengthEncode` is invoked
- THEN the encoder output MUST begin with `\x04` (length byte = literalLen - 1 = 4) followed by the 5 literal bytes and MUST end with `\x80` (EOD)

#### Scenario: Repeat-only input

- GIVEN the input is the 7-byte string `XXXXXXX`
- WHEN `RunLengthEncode` is invoked
- THEN the encoder output MUST contain a single repeat block `\xFAX` followed by `\x80` (EOD)

#### Scenario: Empty input

- GIVEN the input is the empty string
- WHEN `RunLengthEncode` is invoked
- THEN the encoder MUST return the single byte `\x80` (EOD only)

### REQ-003: RunLengthDecode round-trip MUST be lossless

For any input byte string `$P`, `RunLengthDecode(RunLengthEncode($P, null), null)` MUST equal `$P` byte-for-byte.

#### Scenario: Binary round-trip

- GIVEN the input is a 1024-byte buffer with a mix of runs and literals (e.g. 200 zero bytes, followed by random bytes, followed by 100 `\xFF` bytes)
- WHEN `RunLengthDecode` is invoked
- THEN the round-trip MUST equal the input byte-for-byte

### REQ-004: RunLengthDecode SHALL fail safely on truncation

If the decoder encounters end-of-input before delivering all bytes for a literal block, before delivering the repeat byte for a repeat block, or before encountering EOD — it MUST call `p_error()` and MUST return `false` (matching the chain dispatcher's `=== false` short-circuit contract; downstream filters MUST NOT observe a partially-decoded buffer).

#### Scenario: Truncated literal block

- GIVEN the input is `\x05ABC` (length byte says "copy 6 literals" but only 3 follow)
- WHEN `RunLengthDecode` is invoked
- THEN the decoder MUST call `p_error()`
- AND the return value MUST be `false`

#### Scenario: Truncated repeat block

- GIVEN the input is `\xFE` (length byte says "repeat next byte 3 times" but no byte follows)
- WHEN `RunLengthDecode` is invoked
- THEN the decoder MUST call `p_error()`
- AND the return value MUST be `false`

#### Scenario: Missing EOD treated as truncation

- GIVEN the input is `\x02ABC` (3-byte literal block delivered, no `\x80` follows)
- WHEN `RunLengthDecode` is invoked
- THEN the decoder MUST call `p_error()`
- AND the return value MUST be `false`

#### Scenario: Chain-failure propagation on outer-RunLength layer

- GIVEN an object with `/Filter [/RunLengthDecode /FlateDecode]` and a raw `_stream` whose RunLength layer is truncated (missing EOD or incomplete block)
- WHEN `get_stream(false)` is invoked
- THEN the chain dispatcher MUST short-circuit at the RunLength arm and return `false`; the inner `FlateDecode` arm MUST NOT see the partial buffer

## MODIFIED Requirements

### REQ-001: Array-form `/Filter` SHALL decode in forward chain order

The dispatcher MUST recognise `/RunLengthDecode` as a valid filter name and route to the RunLengthDecode helper.

#### Scenario: RunLength single-filter chain

- WHEN an object has `/Filter /RunLengthDecode` and `_stream` equal to `RunLengthEncode("plaintext")`
- THEN `get_stream(false)` MUST return `"plaintext"` byte-for-byte

#### Scenario: RunLength outer + Flate inner chain

- WHEN an object has `/Filter [/RunLengthDecode /FlateDecode]`
- THEN the chain decode MUST be applied in forward order (RunLength first, then Flate)

### REQ-002: Array-form `/Filter` SHALL encode in reverse chain order

The dispatcher MUST recognise `/RunLengthDecode` on the encode path and route to the RunLengthEncode helper.

#### Scenario: RunLength encode + Flate inner chain

- WHEN `set_stream("plaintext", false)` is called on an object with `/Filter [/RunLengthDecode /FlateDecode]`
- THEN the resulting `_stream` MUST equal `RunLengthEncode(gzcompress("plaintext"))` and `_value['Length']` MUST equal `strlen($_stream)`
