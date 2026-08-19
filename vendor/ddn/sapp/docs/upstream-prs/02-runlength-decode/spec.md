# Spec — `/RunLengthDecode`

## Requirements

### REQ-01. `PDFObject::get_stream(false)` MUST decode `/RunLengthDecode` streams

When `$this->_value['Filter']` equals `/RunLengthDecode`, the decoder MUST follow PDF 1.7 §7.4.5:

- Read length byte L.
- If L = 128: stop.
- If L < 128: copy the next L+1 bytes literally to output, advance cursor.
- If L > 128: read one byte B, write B repeated (257 − L) times to output, advance cursor.

Truncated input (length byte at end of stream with no following data byte / data run) MUST route through `p_error` rather than crashing.

**Scenario: literal run decodes**

GIVEN bytes `\x04 H e l l o \x80` (length=4 → 5 literals "Hello", then EOD)
WHEN `get_stream(false)` is called with `/Filter /RunLengthDecode`
THEN the result is `Hello`

**Scenario: repeat run decodes**

GIVEN bytes `\xFE X \x80` (length=254 → repeat byte 'X' 3 times, then EOD)
WHEN `get_stream(false)` is called
THEN the result is `XXX`

**Scenario: mixed runs decode**

GIVEN bytes `\x02 H i \xFD ! \x80` (literal "Hi", then repeat '!' 4 times)
WHEN `get_stream(false)` is called
THEN the result is `Hi!!!!`

**Scenario: missing EOD is tolerated via `p_error`**

GIVEN bytes `\x04 H e l l o` (no EOD marker, then end-of-input)
WHEN `get_stream(false)` is called
THEN the function routes through `p_error` with an "unexpected end of stream" message
AND does not crash

### REQ-02. `PDFObject::set_stream($bytes, false)` MUST encode under `/RunLengthDecode`

The encoder MUST produce a spec-valid byte stream:

- Emit literal-run blocks of up to 128 bytes each (length byte followed by the literal bytes).
- Terminate with the 128 EOD marker.

The encoder MAY also use repeat-runs when economical, but the implementation is NOT required to optimise (a simple all-literal-runs encoder is spec-valid and acceptable for this PR).

**Scenario: encode produces a stream that decodes to the original**

GIVEN a PDFObject with `/Filter /RunLengthDecode`
WHEN `set_stream("Hello World", false)` is called, followed by `get_stream(false)`
THEN the result is `Hello World` byte-for-byte

### REQ-03. Existing filter paths are unchanged

This feature MUST NOT modify the FlateDecode path or the ASCIIHexDecode path (added in PR #01). All existing scenarios for those filters MUST continue to pass.

### REQ-04. Filter chaining stays out of scope

Same as PR #01 REQ-04 — array-form `/Filter [/X /Y]` is NOT handled by this PR.
