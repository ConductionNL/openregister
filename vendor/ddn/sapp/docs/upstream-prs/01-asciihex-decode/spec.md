# Spec — `/ASCIIHexDecode`

Normative requirements for the feature. Each Requirement has at least one scenario.

## Requirements

### REQ-01. `PDFObject::get_stream(false)` MUST decode `/ASCIIHexDecode` streams

When `$this->_value['Filter']` equals `/ASCIIHexDecode`, `get_stream(false)` MUST return the decoded bytes per PDF 1.7 §7.4.2. The decoder MUST:

- Strip whitespace (per §7.4.2: any whitespace character is ignored).
- Stop at the `>` EOD marker; bytes after `>` MUST be discarded.
- Pad odd-length input with a trailing `0` (per §7.4.2: "if the filter encounters the EOD marker after reading an odd number of hexadecimal digits, it shall behave as if a 0 followed the last digit").
- Reject invalid hex characters via `p_error` (NOT throw — match the existing FlateDecode error idiom).

**Scenario: clean hex stream decodes correctly**

GIVEN a stream `48656c6c6f20576f726c64>` with `/Filter /ASCIIHexDecode`
WHEN `get_stream(false)` is called
THEN the result is the bytes `Hello World` (11 bytes)

**Scenario: whitespace is stripped**

GIVEN a stream `48 65 6C 6C 6F\n20 57 6F 72 6C 64 >` with `/Filter /ASCIIHexDecode`
WHEN `get_stream(false)` is called
THEN the result is `Hello World`

**Scenario: odd-length input pads with `0`**

GIVEN a stream `4>` (one hex digit + EOD) with `/Filter /ASCIIHexDecode`
WHEN `get_stream(false)` is called
THEN the result is `\x40` (the single byte from the padded hex `40`)

**Scenario: invalid character returns via `p_error`**

GIVEN a stream `48ZZ6c6c6f>` (contains invalid hex character)
WHEN `get_stream(false)` is called
THEN `p_error` is invoked with a message naming `ASCIIHexDecode` as the source
AND the function returns the value `p_error` returns (false in the standard pattern)

### REQ-02. `PDFObject::set_stream($bytes, false)` MUST encode under `/ASCIIHexDecode`

When `$this->_value['Filter']` equals `/ASCIIHexDecode`, `set_stream($bytes, false)` MUST encode `$bytes` as a hex string suffixed with `>`. Output requirements:

- Hex digits MAY use either case; lowercase is acceptable (matches `bin2hex` default).
- The `>` EOD marker MUST be present.
- The encoded `_stream` and the `_value['Length']` MUST be updated atomically (same contract as the FlateDecode case).

**Scenario: bytes encode round-trip cleanly**

GIVEN a PDFObject with `/Filter /ASCIIHexDecode`
WHEN `set_stream("Hello World", false)` is called, followed by `get_stream(false)`
THEN the result is `Hello World` (byte-identical)

### REQ-03. Existing FlateDecode path is unchanged

This feature MUST NOT modify the behaviour of `get_stream` / `set_stream` for inputs with `/Filter /FlateDecode`. All existing tests covering FlateDecode MUST continue to pass.

**Scenario: FlateDecode unaffected**

GIVEN a content stream with `/Filter /FlateDecode` (any predictor / parameter combination supported pre-feature)
WHEN `get_stream(false)` or `set_stream` is called
THEN the result is byte-identical to the pre-feature behaviour

### REQ-04. Filter chaining is explicitly NOT introduced

The `/Filter` array form (`[/X /Y]`) MUST NOT be supported by this feature. Streams with array-form filters MUST fall through to the existing "unknown compression method" error path. Chaining lands in a separate PR (#05).

**Scenario: array-form filter remains unhandled**

GIVEN a PDFObject with `/Filter [/ASCII85Decode /FlateDecode]`
WHEN `get_stream(false)` is called
THEN `p_error` is invoked with the existing "unknown compression method" message
