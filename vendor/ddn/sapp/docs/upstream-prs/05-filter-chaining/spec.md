# Spec — Filter chaining

## Requirements

### REQ-01. Single-name `/Filter` continues to produce byte-identical output

The refactor MUST preserve the byte-for-byte behaviour of `get_stream(false)` for inputs where `/Filter` is a single name (e.g. `/FlateDecode`). Every existing test fixture decoded successfully pre-refactor MUST decode to the same bytes post-refactor.

**Scenario: testdoc.pdf decodes byte-equal**

GIVEN `examples/testdoc.pdf` (FlateDecoded body, no array-form filter)
WHEN `get_stream(false)` is called on each PDFObject in the document, pre-refactor and post-refactor
THEN every output is byte-identical

### REQ-02. Array-form `/Filter` decodes by applying filters in order

The implementation MUST handle `/Filter` as an array of names (PDF 1.7 §7.4.1). Filters are applied in the order they appear in the array — left to right. Each filter operates on the output of the previous one.

**Scenario: ASCII85+FlateDecode chain**

GIVEN a PDFObject with `/Filter [/ASCII85Decode /FlateDecode]` and body containing data that decodes ASCII85 to FlateDecoded bytes, which decode to the readable content
WHEN `get_stream(false)` is called
THEN the result is the readable content (both filters applied in pipeline)

**Scenario: three-filter chain**

GIVEN a PDFObject with `/Filter [/ASCIIHexDecode /ASCII85Decode /FlateDecode]` (uncommon but spec-permitted)
WHEN `get_stream(false)` is called
THEN the result is the readable content (three filters applied in order)

### REQ-03. `set_stream(.., false)` encodes by applying filters in REVERSE order

The encode path MUST invert the decode pipeline: apply each filter's encoder in reverse order.

**Scenario: encode-then-decode round-trip preserves bytes**

GIVEN a PDFObject with `/Filter [/ASCII85Decode /FlateDecode]`
WHEN `set_stream("Hello World", false)` is called
AND THEN `get_stream(false)` is called
THEN the result is `Hello World` byte-for-byte

### REQ-04. `/DecodeParms` MUST align with the filter list

When `/Filter` is an array of N filters, `/DecodeParms` (when present) MUST be either:

- An array of N dictionaries (or `null` entries for filters without parameters)
- Absent (interpreted as no parameters for any filter)

The normaliser MUST tolerate `null` entries (substituting an empty dict) and pad short arrays to length N with empty dicts.

**Scenario: parallel array form**

GIVEN `/Filter [/FlateDecode /ASCII85Decode]` and `/DecodeParms [<</Predictor 12>> null]`
WHEN `get_stream(false)` runs the chain
THEN FlateDecode receives `{Predictor: 12, ...}` parameters
AND ASCII85Decode receives no parameters

**Scenario: missing DecodeParms tolerated**

GIVEN `/Filter [/FlateDecode /ASCII85Decode]` with no `/DecodeParms` entry
WHEN decoded
THEN both filters run with empty parameter dicts (default behaviour)

### REQ-05. Unknown filter in chain routes through `p_error`

When a filter in the chain is not recognised, the decoder MUST route through `p_error` with a message naming the unknown filter. The pipeline MUST stop at that point; subsequent filters are NOT applied.

**Scenario: unknown filter aborts the chain**

GIVEN `/Filter [/ASCII85Decode /MysteryFilter /FlateDecode]`
WHEN `get_stream(false)` is called
THEN ASCII85 decodes successfully
AND `p_error` is invoked naming `/MysteryFilter`
AND FlateDecode is NOT called

### REQ-06. Empty filter array is a no-op

A `/Filter []` entry (empty array) is spec-permitted and means "no filter". `get_stream(false)` MUST return the raw stream bytes.

**Scenario: empty filter array passes through**

GIVEN `/Filter []` and a raw byte stream
WHEN `get_stream(false)` is called
THEN the result is the raw bytes

### REQ-07. Image-only filters explicitly fall through to `p_error`

Filters that apply only to image streams (`/DCTDecode`, `/CCITTFaxDecode`, `/JBIG2Decode`, `/JPXDecode`) MUST NOT be handled by this dispatch. They route through `p_error` to make it clear text-content-stream tooling does not handle them.

**Scenario: DCTDecode rejected**

GIVEN `/Filter /DCTDecode` (a JPEG image stream)
WHEN `get_stream(false)` is called
THEN `p_error` is invoked with a message about JPEG / image streams
