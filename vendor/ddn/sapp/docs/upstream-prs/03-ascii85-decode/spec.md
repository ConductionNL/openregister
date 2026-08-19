# Spec — `/ASCII85Decode`

## Requirements

### REQ-01. `PDFObject::get_stream(false)` MUST decode `/ASCII85Decode` streams

The decoder MUST follow PDF 1.7 §7.4.3:

- Strip whitespace from the input.
- Locate the `~>` EOD marker; bytes after it MUST be discarded.
- Treat each `z` character (outside any 5-char group) as shorthand for 4 zero bytes.
- Decode each 5-char group as a base-85 integer (chars 33–117 = digits 0–84) into 4 raw bytes via `pack('N', $value)`.
- Pad short final group with `'u'` (value 84); trim `(5 - actual_len)` bytes off the decoded output.
- Reject invalid characters (outside `33..117` range, excluding the `z` shorthand and whitespace) via `p_error`.

**Scenario: clean ASCII85 stream decodes**

GIVEN the bytes `87cURD]j7BEbo80~>` (ASCII85 encoding of `Hello World`)
WHEN `get_stream(false)` is called with `/Filter /ASCII85Decode`
THEN the result is `Hello World`

**Scenario: `z` shorthand expands to 4 zero bytes**

GIVEN the bytes `z~>` (single `z` then EOD)
WHEN `get_stream(false)` is called
THEN the result is the 4 bytes `\x00\x00\x00\x00`

**Scenario: short final group pads correctly**

GIVEN an ASCII85 stream representing 6 raw bytes (the first 4 + a partial 2-byte tail; encoded ends as a 3-char group followed by `~>`)
WHEN `get_stream(false)` is called
THEN the result is the original 6 bytes (not 4, not 8)

**Scenario: whitespace ignored**

GIVEN the bytes `87cURD\n]j7BEbo80~>` (linebreak inside the stream)
WHEN `get_stream(false)` is called
THEN the result is `Hello World` (linebreak stripped)

**Scenario: invalid character returns via `p_error`**

GIVEN the bytes `87cURD\x01]j7BEbo80~>` (control char that's not whitespace)
WHEN `get_stream(false)` is called
THEN the function routes through `p_error` with an ASCII85-named message

### REQ-02. `PDFObject::set_stream($bytes, false)` MUST encode under `/ASCII85Decode`

The encoder MUST produce a spec-valid byte stream:

- Encode each 4-byte input group as a 5-char ASCII85 group.
- Final partial group (1–3 bytes): emit (1 + len) chars without padding.
- Terminate with `~>`.
- MAY optimise 4 zero bytes to single `z`.

**Scenario: round-trip is byte-identical**

GIVEN a PDFObject with `/Filter /ASCII85Decode`
WHEN `set_stream("Hello World", false)` followed by `get_stream(false)`
THEN the result is `Hello World`

### REQ-03. Existing filter paths are unchanged

This feature MUST NOT modify the FlateDecode / ASCIIHexDecode / RunLengthDecode paths.

### REQ-04. Filter chaining stays out of scope

Same as PR #01, PR #02 — array-form `/Filter [/X /Y]` is NOT handled here. The chaining refactor (PR #05) is the moment when ASCII85's most-common-real-world pairing (with FlateDecode) becomes useful end-to-end.
