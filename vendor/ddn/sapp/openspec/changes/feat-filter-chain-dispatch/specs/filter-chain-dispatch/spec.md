**Status**: planned
**Scope**: change `feat-filter-chain-dispatch` (delta spec)
**OpenSpec changes**:
- `feat-filter-chain-dispatch` (in-progress)

## Purpose

Capability contract for `filter-chain-dispatch` — the normative SHALL/MUST
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
`openspec/specs/filter-chain-dispatch/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: Array-form `/Filter` SHALL decode in forward chain order

When `PDFObject::get_stream($raw = false)` is called on an object whose `/Filter` is an array, the dispatcher MUST apply the named filter decoders in FORWARD order (outermost first, innermost last), per PDF 1.7 §7.4.1 ¶3. The output MUST be the same plaintext that would be produced by an external PDF reader (Adobe / pdf.js / poppler) consuming the stream.

#### Scenario: Two-filter chain decode

- WHEN an object has `/Filter [/ASCIIHexDecode /FlateDecode]`, `/Length` matching the encoded byte count, and an encoded `_stream` produced by the sequence `gzcompress("BT...ET") |> ascii_hex_envelope`
- THEN `get_stream(false)` MUST return the original `"BT...ET"` plaintext (ASCIIHex un-envelope, then gzuncompress)

#### Scenario: Single-element array form is semantically identical to string form

- WHEN an object has `/Filter [/FlateDecode]` (1-element array)
- THEN `get_stream(false)` MUST return the same plaintext as it would for `/Filter /FlateDecode` (string form) on identical stream bytes

#### Scenario: Empty array `/Filter` MUST be treated as no filtering

- WHEN an object has `/Filter []` (empty array)
- THEN `get_stream(false)` MUST return `$this->_stream` unchanged

### REQ-002: Array-form `/Filter` SHALL encode in reverse chain order

When `PDFObject::set_stream($plaintext, $raw = false)` is called on an object whose `/Filter` is an array, the dispatcher MUST apply the named filter encoders in REVERSE order (innermost first, outermost last), per PDF 1.7 §7.4.1 ¶3, then update `/Length` to the final encoded byte count.

#### Scenario: Two-filter chain encode

- WHEN `set_stream("BT...ET", false)` is called on an object with `/Filter [/ASCIIHexDecode /FlateDecode]`
- THEN the resulting `_stream` MUST equal `ascii_hex_envelope(gzcompress("BT...ET"))` and `_value['Length']` MUST equal `strlen($_stream)` after encoding

#### Scenario: Encode-decode round-trip is lossless (Predictor absent or = 1)

- GIVEN an array-form-`/Filter` object with `/DecodeParms` either absent OR carrying `Predictor` value 1 for every chain position (i.e. the no-op-predictor case)
- WHEN `set_stream($P, false)` is invoked on the object, then `get_stream(false)` is invoked on the same object
- THEN the value returned by `get_stream(false)` MUST equal `$P` byte-for-byte
- AND for chains that include a non-trivial predictor (`Predictor ≥ 10`), round-trip losslessness is **out of scope** of this change — the encode path applies `gzcompress` only and does not run the PNG filter encoder. A follow-up change (`feat-flate-predictor-encode`) ships the symmetric encode-side predictor application; until then, callers MUST NOT rely on byte-for-byte round-trip when a PNG predictor is in play.

### REQ-003: `/DecodeParms` array SHALL be applied positionally per filter

When `/Filter` is an array of length N, the dispatcher MUST accept a parallel `/DecodeParms` value of either: an array of length ≤ N (where each entry is either a parameter dictionary or `null` for filters with no parameters), or absent entirely (equivalent to `null` for every filter), or a single dictionary when `/Filter` has length 1 (string-form-equivalent shape).

#### Scenario: Per-filter parameters dispatched to the correct decoder

- WHEN an object has `/Filter [/FlateDecode]` and `/DecodeParms [<</Predictor 12 /Columns 4>>]`
- THEN the `FlateDecode` decoder MUST receive `Predictor=12` and `Columns=4` (per PDF 1.7 §7.4.4 PNG predictor semantics)

#### Scenario: Trailing `null` entries in `/DecodeParms` MUST be tolerated

- WHEN an object has `/Filter [/ASCIIHexDecode /FlateDecode]` and `/DecodeParms [null null]`
- THEN both decoders MUST be invoked with no parameters (equivalent to the `/DecodeParms` entry being absent)

#### Scenario: Under-length `/DecodeParms` MUST be tolerated

- WHEN an object has `/Filter [/ASCIIHexDecode /FlateDecode]` and `/DecodeParms [null]` (length 1, less than `/Filter`'s length 2)
- THEN the missing trailing entry MUST be treated as `null` (no params for the unspecified filter), matching reader behaviour in Adobe / pdf.js / poppler

### REQ-004: String-form `/Filter` callers MUST observe unchanged behaviour

Callers that pass a single-name `/Filter` (the upstream-default shape, e.g. `/FlateDecode`) MUST observe byte-for-byte identical results from `get_stream` and `set_stream` after this change as before it. The dispatcher coerces string-form to a 1-element array internally but MUST NOT change the persisted `/Filter` shape on write-back.

#### Scenario: String-form `/Filter` is preserved on write-back

- WHEN an object loaded with `/Filter /FlateDecode` (string form) has `set_stream($P, false)` called on it
- THEN after the call, `$this->_value['Filter']` MUST still serialise as `/FlateDecode` (string), NOT as `[/FlateDecode]` (array)

#### Scenario: Existing PoC verify gate stays green

- WHEN `php examples/poc-replace-text.php` is executed on this change
- THEN it MUST exit with status 0 and `residual_needles=0, placeholder_hits=1, streams_modified=1` (same as before the change)

### REQ-005: Unknown filter names in a chain MUST fail safely

If any filter name in `/Filter` is not implemented, the dispatcher MUST log via `p_error()` (consistent with sapp's existing unsupported-feature convention) and MUST leave `$this->_stream` and `$this->_value['Length']` unchanged. It MUST NOT throw an exception.

#### Scenario: Unknown filter in a chain leaves the stream untouched

- WHEN `set_stream($P, false)` is called on an object with `/Filter [/UnknownFilter /FlateDecode]`
- THEN `p_error()` MUST be called with a message identifying the unknown filter name
- AND `$this->_stream` MUST remain unchanged from its pre-call value
- AND `$this->_value['Length']` MUST remain unchanged
- AND the method MUST NOT throw

#### Scenario: Unknown filter on get_stream returns false

- GIVEN an object with `/Filter [/UnknownFilter /FlateDecode]`
- WHEN `get_stream(false)` is invoked
- THEN `p_error()` MUST be called identifying the unknown filter
- AND the return value MUST be `false` (matching upstream's pre-refactor `return p_error(...)` semantics — `p_error` defaults to returning `false`)
