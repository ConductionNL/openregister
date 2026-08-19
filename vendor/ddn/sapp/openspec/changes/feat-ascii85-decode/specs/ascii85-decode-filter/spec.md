**Status**: planned
**Scope**: change `feat-ascii85-decode` (delta spec)
**OpenSpec changes**:
- `feat-ascii85-decode` (in-progress)

## Purpose

Capability contract for `ascii85-decode-filter` — the normative SHALL/MUST
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
`openspec/specs/ascii85-decode-filter/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: ASCII85Decode SHALL decode per PDF 1.7 §7.4.3

The decoder MUST interpret 5-character groups in the range `!..u` (codepoints 33..117) as base-85 integers, emit 4 bytes per group in big-endian order, recognise the single-character shortcut `z` (only at a group boundary) as 4 zero bytes, terminate at the `~>` EOD marker, ignore whitespace anywhere, and tolerate an optional leading `<~` (Adobe btoa-style start marker).

#### Scenario: Standard 5-char group decode

- GIVEN the input is `87cUR~>` (canonical encoding of `"Hell"`; verified `ASCII85Encode("Hell") === "87cUR~>"`)
- WHEN `ASCII85Decode` is invoked
- THEN the decoder MUST return the 4-byte string `Hell`

#### Scenario: Multi-group decode

- GIVEN the input is `87cURD]j7BEbo80~>` (canonical encoding of `"Hello world!"`; verified `ASCII85Encode("Hello world!") === "87cURD]j7BEbo80~>"`)
- WHEN `ASCII85Decode` is invoked
- THEN the decoder MUST return `Hello world!`

#### Scenario: `z` shortcut at group boundary

- GIVEN the input is `z~>`
- WHEN `ASCII85Decode` is invoked
- THEN the decoder MUST return `\x00\x00\x00\x00` (4 zero bytes)

#### Scenario: Whitespace ignored

- GIVEN the input is `87cU\n R~>` (whitespace mid-group)
- WHEN `ASCII85Decode` is invoked
- THEN the decoder MUST return `Hell` (whitespace stripped)

#### Scenario: Adobe-tolerant leading marker

- GIVEN the input is `<~87cUR~>`
- WHEN `ASCII85Decode` is invoked
- THEN the decoder MUST strip the leading `<~` and return `Hell`

#### Scenario: Trailing partial group (k=2 → 1 byte)

- GIVEN the input is `87cURDZ~>` (canonical encoding of `"Hello"`; the 2-char trailing partial `DZ` encodes the single byte `o` per §7.4.3 padding rules — verified `ASCII85Encode("Hello") === "87cURDZ~>"`)
- WHEN `ASCII85Decode` is invoked
- THEN the decoder MUST return the 5-byte string `"Hello"`

#### Scenario: Empty payload between markers

- GIVEN the input is `<~~>` (empty payload between Adobe markers) OR `~>` (bare EOD)
- WHEN `ASCII85Decode` is invoked
- THEN the decoder MUST return the empty string

### REQ-002: ASCII85Encode SHALL produce round-trip-compatible output

The encoder MUST emit 5 ASCII chars per 4 input bytes, use the `z` shortcut for aligned 4-zero-byte groups, terminate with `~>`, and handle trailing partial groups by padding with `u` and emitting only the relevant prefix.

#### Scenario: Basic encode

- GIVEN the input is the string `Hello world!`
- WHEN `ASCII85Encode` is invoked
- THEN the encoder MUST return a stream ending in `~>` that round-trips to the input

#### Scenario: Aligned zero run uses `z`

- GIVEN the input is `\x00\x00\x00\x00` (4 zero bytes)
- WHEN `ASCII85Encode` is invoked
- THEN the encoder MUST return `z~>`

#### Scenario: Empty input

- GIVEN the input is the empty string
- WHEN `ASCII85Encode` is invoked
- THEN the encoder MUST return `~>` (EOD only)

### REQ-003: ASCII85Decode round-trip MUST be lossless

For any input byte string `$P`, `ASCII85Decode(ASCII85Encode($P, null), null)` MUST equal `$P` byte-for-byte.

#### Scenario: Binary round-trip

- GIVEN the input is `random_bytes(1024)`
- WHEN `ASCII85Decode` is invoked
- THEN the round-trip MUST equal the input byte-for-byte

### REQ-004: ASCII85Decode SHALL fail safely on spec violations

The decoder MUST call `p_error()` and MUST return `false` (matching the chain dispatcher's `=== false` short-circuit contract; downstream filters MUST NOT see partial output) on any of the following spec-violation conditions:

1. A character outside `!..u` (33..117) appears in the data region — including `~` not paired with `>` as the EOD marker, and `z` appearing mid-group rather than at a group boundary.
2. A trailing partial group of exactly 1 character (§7.4.3 partial-group rule is `2 ≤ k ≤ 4`).
3. A 5-char group whose arithmetic decoded value exceeds `2^32 - 1` (the spec-imposed maximum). Note: `uuuuu` arithmetically computes to 4,437,053,124 which exceeds the cap; the maximum valid 5-char group is `s8W-!` = 2^32 - 1.
4. PCRE compile failure / limit hit on the whitespace-strip regex.

#### Scenario: Illegal character

- GIVEN the input is `87c{R~>` (contains `{`, codepoint 123, outside `!..u`)
- WHEN `ASCII85Decode` is invoked
- THEN the decoder MUST call `p_error()`
- AND the return value MUST be `false`

#### Scenario: `~` mid-stream (not part of `~>` EOD)

- GIVEN the input is `87c~XR~>` (contains a `~` that is not the start of the EOD pair)
- WHEN `ASCII85Decode` is invoked
- THEN the return value MUST be `false`

#### Scenario: `z` mid-group

- GIVEN the input is `8z~>` (`z` appears after `8` has begun a new group — not at a boundary)
- WHEN `ASCII85Decode` is invoked
- THEN the return value MUST be `false`

#### Scenario: 1-char trailing partial group is spec-illegal

- GIVEN the input is `87cURD~>` (5-char group `87cUR` plus a stray 1-char partial `D`; §7.4.3 requires partial groups to satisfy `2 ≤ k ≤ 4`)
- WHEN `ASCII85Decode` is invoked
- THEN the return value MUST be `false`

#### Scenario: Overflow guard fires

- GIVEN the input is `tttt~>` (4-char partial padded with `u` arithmetically yields 4,384,231,064, which exceeds `2^32 - 1`)
- WHEN `ASCII85Decode` is invoked
- THEN the return value MUST be `false`

#### Scenario: Chain-failure propagation on outer-ASCII85 layer

- GIVEN an object with `/Filter [/ASCII85Decode /FlateDecode]` and a raw `_stream` whose ASCII85 layer contains an illegal character
- WHEN `get_stream(false)` is invoked
- THEN the chain dispatcher MUST short-circuit at the ASCII85 arm and return `false`; the inner `FlateDecode` arm MUST NOT see the malformed bytes

## MODIFIED Requirements

### REQ-001: Array-form `/Filter` SHALL decode in forward chain order

The dispatcher MUST recognise `/ASCII85Decode` and route to the ASCII85Decode helper.

#### Scenario: ASCII85 outer + Flate inner

- WHEN an object has `/Filter [/ASCII85Decode /FlateDecode]` and `_stream` is `ASCII85Encode(gzcompress("BT...ET"))`
- THEN `get_stream(false)` MUST return `"BT...ET"` byte-for-byte

### REQ-002: Array-form `/Filter` SHALL encode in reverse chain order

The dispatcher MUST recognise `/ASCII85Decode` on the encode path and route to the ASCII85Encode helper.

#### Scenario: Two-filter encode round-trip with ASCII85

- WHEN `set_stream($P, false)` is called on `/Filter [/ASCII85Decode /FlateDecode]`, then `get_stream(false)` is called
- THEN the value returned by `get_stream(false)` MUST equal `$P`
