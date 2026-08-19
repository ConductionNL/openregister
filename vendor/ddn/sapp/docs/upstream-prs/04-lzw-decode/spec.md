# Spec — `/LZWDecode`

## Requirements

### REQ-01. `PDFObject::get_stream(false)` MUST decode `/LZWDecode` streams

The decoder MUST follow PDF 1.7 §7.4.4:

- Read codes from the input bit-stream starting at 9-bit width.
- Code table seeded with literals 0–255, clear (256), EOD (257).
- On clear-code: reset table + bit width.
- On EOD: stop.
- Otherwise: emit table[code], extend table with `(previous + first_char(table[code]))`, grow bit width at thresholds per `EarlyChange`.

**Scenario: simple LZW stream decodes**

GIVEN a stream LZW-encoding `Hello World` with default parameters (`EarlyChange = 1`, no predictor)
WHEN `get_stream(false)` is called with `/Filter /LZWDecode`
THEN the result is `Hello World`

**Scenario: clear-code mid-stream resets the table**

GIVEN an LZW stream containing a clear-code (256) at position N
WHEN decoded
THEN positions before N use the pre-clear table; positions after N use the freshly-reset table

**Scenario: `EarlyChange = 0` mode decodes correctly**

GIVEN an LZW stream with `/DecodeParms << /EarlyChange 0 >>`
WHEN decoded
THEN the bit-width transitions occur one code later than the default

**Scenario: variable-bit-width transition across the 9-10-bit boundary**

GIVEN an LZW stream that requires 510+ code-table entries (forces a 9→10-bit transition)
WHEN decoded
THEN the byte-equal output matches the reference decoder (e.g. ImageMagick's `coders/lzw.c` output for the same input)

### REQ-02. `PdfObject::get_stream(false)` MUST apply `/Predictor` after LZW decompression

When `/DecodeParms` includes a `/Predictor` value of 10–15 (PNG predictors), the decoder MUST apply row reconstruction via the shared `ApplyPngPredictor` helper after LZW decompression.

**Scenario: LZW + PNG predictor decodes correctly**

GIVEN a stream with `/Filter /LZWDecode` and `/DecodeParms << /Predictor 12 /Columns 100 >>`
WHEN decoded
THEN LZW decompression runs first; the result is passed through PNG predictor reconstruction; the output matches a reference decoder

### REQ-03. The PNG-predictor refactor MUST preserve FlateDecode byte-equivalence

The extraction of PNG-predictor logic into `ApplyPngPredictor` MUST NOT change the behaviour of `/Filter /FlateDecode` streams. Every existing FlateDecode + predictor input MUST produce byte-identical output before and after the refactor.

**Scenario: existing FlateDecode fixtures pass post-refactor**

GIVEN `examples/testdoc.pdf` (FlateDecoded, possibly with predictor)
WHEN re-extracted via `get_stream(false)` after the refactor
THEN the output is byte-identical to pre-refactor

### REQ-04. `PDFObject::set_stream($bytes, false)` MUST encode under `/LZWDecode`

The encoder MUST produce a spec-valid byte stream that decodes back to the input via `LZWDecode`. Performance / compression-ratio optimisation is NOT required; correctness + decodability ARE.

**Scenario: encode round-trips**

GIVEN a PDFObject with `/Filter /LZWDecode`
WHEN `set_stream("Hello World", false)` followed by `get_stream(false)`
THEN the result is `Hello World`

### REQ-05. Existing filter paths are unchanged

This feature MUST NOT modify behaviour for `/FlateDecode`, `/ASCIIHexDecode`, `/RunLengthDecode`, `/ASCII85Decode`. (Excepting the byte-identical PNG-predictor refactor on FlateDecode, which is REQ-03.)

### REQ-06. Filter chaining stays out of scope

Same as PRs #01–#03 — array-form `/Filter [/X /Y]` is NOT handled here. PR #05 introduces it.
