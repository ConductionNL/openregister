**Status**: planned
**Scope**: change `feat-asciihex-decode` (delta spec)
**OpenSpec changes**:
- `feat-asciihex-decode` (in-progress)

## Purpose

Capability contract for `asciihex-decode-filter` — the normative SHALL/MUST
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
`openspec/specs/asciihex-decode-filter/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: ASCIIHexDecode SHALL decode per PDF 1.7 §7.4.2

The decoder MUST accept input over the alphabet `0..9 A..F a..f` plus whitespace plus the EOD marker `>`. It MUST ignore whitespace, terminate at `>`, treat an odd trailing digit as if followed by `0`, and emit raw binary bytes corresponding to the hex pairs.

#### Scenario: Even-length decode

- GIVEN the encoded stream is `48656C6C6F>` (encoding `"Hello"`)
- WHEN `ASCIIHexDecode` is invoked
- THEN the decoder MUST return the 5-byte string `Hello`

#### Scenario: Odd-length decode pads with zero

- GIVEN the encoded stream is `41B>` (3 hex digits, then EOD)
- WHEN `ASCIIHexDecode` is invoked
- THEN the decoder MUST return the 2-byte string `\x41\xB0` (the trailing `B` paired with implicit `0`)

#### Scenario: Whitespace is ignored

- GIVEN the encoded stream is `48 65\n6C\t6C\r\n6F>`
- WHEN `ASCIIHexDecode` is invoked
- THEN the decoder MUST return `Hello` byte-for-byte

#### Scenario: Lowercase hex digits are accepted

- GIVEN the encoded stream is `48656c6c6f>`
- WHEN `ASCIIHexDecode` is invoked
- THEN the decoder MUST return `Hello` (case-insensitive)

#### Scenario: Trailing bytes after EOD are ignored

- GIVEN the encoded stream is `48656C6C6F>garbage`
- WHEN `ASCIIHexDecode` is invoked
- THEN the decoder MUST return `Hello` and MUST NOT raise an error

### REQ-002: ASCIIHexEncode SHALL emit uppercase hex pairs with EOD

The encoder MUST emit one uppercase hex pair per input byte, terminated by `>`, with `\n` line wraps inserted at 80 columns (deterministic; readers tolerate any width per §7.4.2).

#### Scenario: Basic encode

- GIVEN the input is the 5-byte string `Hello`
- WHEN `ASCIIHexEncode` is invoked
- THEN the encoder MUST return `48656C6C6F>`

#### Scenario: Line wrap at 80 columns

- GIVEN the input is 50 bytes (which encode to 100 hex characters)
- WHEN `ASCIIHexEncode` is invoked
- THEN the encoder output MUST contain at least one `\n` and no line MUST exceed 80 characters

#### Scenario: Empty input

- GIVEN the input is the empty string
- WHEN `ASCIIHexEncode` is invoked
- THEN the encoder MUST return just `>` (the EOD marker)

### REQ-003: ASCIIHexDecode round-trip MUST be lossless

For any input byte string `$P`, `ASCIIHexDecode(ASCIIHexEncode($P, null), null)` MUST equal `$P` byte-for-byte.

#### Scenario: Binary round-trip

- GIVEN the input is a 1024-byte stream of pseudo-random bytes (PHP `random_bytes(1024)`)
- WHEN `ASCIIHexDecode` is invoked
- THEN the round-trip MUST equal the input byte-for-byte

### REQ-004: ASCIIHexDecode SHALL fail safely on illegal characters

When the decoder encounters a character outside the alphabet plus whitespace plus EOD, it MUST call `p_error()` with a message identifying the failure and MUST return `false` (matching upstream `p_error`'s default return + the chain dispatcher's `=== false` short-circuit contract). It MUST NOT return the raw input bytes — that would let downstream filters in a chain see corrupted bytes.

#### Scenario: Illegal character fails safely

- GIVEN the encoded stream is `48656C!6C6F>` (contains `!`)
- WHEN `ASCIIHexDecode` is invoked
- THEN the decoder MUST call `p_error()` with a message naming the illegal character
- AND the return value MUST be `false` (so the chain dispatcher's `if ($decoded === false) return false;` arm fires)
- AND no exception MUST be thrown

#### Scenario: Chain-failure propagation on outer-ASCIIHex layer

- GIVEN an object with `/Filter [/ASCIIHexDecode /FlateDecode]` and a raw `_stream` whose ASCIIHex layer contains an illegal character
- WHEN `get_stream(false)` is invoked
- THEN the chain dispatcher MUST short-circuit at the ASCIIHex arm and return `false`; the inner `FlateDecode` arm MUST NOT see the malformed bytes

## MODIFIED Requirements

### REQ-001: Array-form `/Filter` SHALL decode in forward chain order

When `PDFObject::get_stream($raw = false)` is called on an object whose `/Filter` is an array, the dispatcher MUST apply the named filter decoders in FORWARD order (outermost first, innermost last), per PDF 1.7 §7.4.1 ¶3. The dispatcher MUST recognise `/ASCIIHexDecode` as a valid filter name and route to the ASCIIHexDecode helper.

#### Scenario: Two-filter chain decode with ASCIIHex outer + Flate inner

- WHEN an object has `/Filter [/ASCIIHexDecode /FlateDecode]` and `_stream` equal to `ASCIIHexEncode(gzcompress("BT...ET"))`
- THEN `get_stream(false)` MUST return `"BT...ET"` byte-for-byte

#### Scenario: Single-element array form is semantically identical to string form

- WHEN an object has `/Filter [/FlateDecode]` (1-element array)
- THEN `get_stream(false)` MUST return the same plaintext as it would for `/Filter /FlateDecode` (string form) on identical stream bytes

#### Scenario: Empty array `/Filter` MUST be treated as no filtering

- WHEN an object has `/Filter []` (empty array)
- THEN `get_stream(false)` MUST return `$this->_stream` unchanged

#### Scenario: ASCIIHex-only single-filter chain

- WHEN an object has `/Filter /ASCIIHexDecode` (string form) and `_stream` equal to `ASCIIHexEncode("plaintext")`
- THEN `get_stream(false)` MUST return `"plaintext"` byte-for-byte

### REQ-002: Array-form `/Filter` SHALL encode in reverse chain order

When `PDFObject::set_stream($plaintext, $raw = false)` is called on an object whose `/Filter` is an array, the dispatcher MUST apply the named filter encoders in REVERSE order (innermost first, outermost last), per PDF 1.7 §7.4.1 ¶3, then update `/Length` to the final encoded byte count. The dispatcher MUST recognise `/ASCIIHexDecode` as a valid filter name on the encode path and route to the ASCIIHexEncode helper.

#### Scenario: Two-filter chain encode with ASCIIHex outer

- WHEN `set_stream("plaintext", false)` is called on an object with `/Filter [/ASCIIHexDecode /FlateDecode]`
- THEN the resulting `_stream` MUST equal `ASCIIHexEncode(gzcompress("plaintext"))` and `_value['Length']` MUST equal `strlen($_stream)` after encoding

#### Scenario: Encode-decode round-trip is lossless

- WHEN `set_stream($P, false)` is called on an array-form-`/Filter` object that includes `/ASCIIHexDecode`, then `get_stream(false)` is called
- THEN the value returned by `get_stream(false)` MUST equal `$P` byte-for-byte
