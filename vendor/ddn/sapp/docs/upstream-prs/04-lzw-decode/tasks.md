# Tasks — `/LZWDecode`

## 1. Refactor (REQ-03)

- [ ] 1.1 Extract the PNG-predictor logic from the existing `FlateDecode` method into `protected static function ApplyPngPredictor($data, $params): string` in `src/PDFObject.php`. Verbatim move; no behaviour change.
- [ ] 1.2 Update `FlateDecode` to call `self::ApplyPngPredictor` after `gzuncompress`. Verify the existing FlateDecode test (decoding `examples/testdoc.pdf`) produces byte-identical output.

## 2. LZW decoder (REQ-01, REQ-02)

- [ ] 2.1 Add `protected static function LZWDecode($_stream, $params)` to `src/PDFObject.php`. Algorithm per `design.md` D1; honour `EarlyChange` parameter (default 1).
- [ ] 2.2 After LZW decompression, apply `ApplyPngPredictor` if `/Predictor` is set in `$params`.

## 3. Dispatch + encode (REQ-04)

- [ ] 3.1 Add `case '/LZWDecode'` to `get_stream()`'s switch — pass `$DecodeParams` through to `LZWDecode`.
- [ ] 3.2 Add `case '/LZWDecode'` to `set_stream()`'s switch — basic LZW encoder, EarlyChange=1 only.

## 4. Fixtures + verification

- [ ] 4.1 Fixture: a small LZW-encoded PDF stream. Sources:
    - Reference encoder output (PHP-side, written by us)
    - OR an actual legacy PDF using LZW (if we have one)
- [ ] 4.2 Fixture for boundary-crossing: LZW stream that grows past 511 entries (forces 9→10-bit transition).
- [ ] 4.3 Fixture for LZW + PNG predictor: synthetic combination, validate against ImageMagick reference output.
- [ ] 4.4 Verification script: decode + assert each fixture; round-trip via `set_stream` + `get_stream`.

## 5. Issue + PR

- [ ] 5.1 Post the issue body from `issue.md`. Record URL in frontmatter.
- [ ] 5.2 Decide on the single-PR vs. two-PR split based on maintainer's preference (asked in the issue).
- [ ] 5.3 Branch `feat/lzw-decode` (or `feat/png-predictor-extract` + `feat/lzw-decode` if split) off upstream `main`.
- [ ] 5.4 Open the PR(s) referencing the issue.
- [ ] 5.5 Squash-merge into `work/text-replacement`.

## 6. Quality

- [ ] 6.1 FlateDecode tests pass byte-identical post-refactor (REQ-03).
- [ ] 6.2 No regression in ASCIIHexDecode / RunLengthDecode / ASCII85Decode paths.
- [ ] 6.3 No new dependencies.
- [ ] 6.4 LZW round-trip on every fixture.
- [ ] 6.5 REQ-01 through REQ-06 each have a passing verification step.


---

> **Implementation note**: canonical task list lives in `openspec/changes/feat-lzw-decode/tasks.md` (kept up to date with the `return false` failure-path semantics, the `applyPngPredictor` docblock fix, and the new Sub-filter / dict-overflow / predictor-rejection test fixtures).
