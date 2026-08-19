# Design — `/LZWDecode`

## Decisions

### D1. Variable-bit-width LZW codes

PDF's LZW format starts with 9-bit codes and grows to 12 bits as the code table fills. The decoder MUST:

- Initialise the code table with entries 0–255 (literal byte values), 256 (clear code), 257 (EOD code).
- Read codes via bit-by-bit accumulation (PHP doesn't have a native bit-stream reader; we shift bytes into a buffer and mask).
- Track current bit width: start at 9, grow at table-size thresholds (511, 1023, 2047 — at code table position $2^{width} - 1$ when `EarlyChange = 1`, or $2^{width}$ when `EarlyChange = 0`).
- On clear-code (256): reset the code table to its initial state, reset bit width to 9.
- On EOD-code (257): stop decoding.
- Otherwise: emit the code's string, append `(previous_string + first_char_of_current)` to the table, advance.

The full algorithm is ~80 lines of PHP. Reference implementations:

- zen-pdf's LZW decoder
- pdfly's pure-Python implementation
- ImageMagick's `coders/lzw.c`

### D2. `EarlyChange` parameter

PDF 1.7 §7.4.4 specifies the `/EarlyChange` parameter in `/DecodeParms`:

- `EarlyChange = 1` (default): bit width grows one code EARLIER than would otherwise be expected
- `EarlyChange = 0`: bit width grows at the natural code-table size boundary

The decoder MUST honour the parameter. Default is 1 per spec, matching the existing predictor's parameter defaults.

### D3. PNG predictor extraction

The current `FlateDecode` method has the PNG-predictor row-reconstruction baked in. LZWDecode needs the same predictor support (per §7.4.4, `/Predictor` applies to LZW too). Inlining a copy would duplicate ~50 lines; extracting to a helper is cleaner.

**Extracted function:** `protected static function ApplyPngPredictor($data, $params): string`. Inputs: the decoded byte stream + the predictor parameters (`Columns`, `Predictor`, `BitsPerComponent`, `Colors`). Output: row-reconstructed bytes. Verbatim algorithm from the current FlateDecode body; the only change is moving it.

**Callers after refactor:**

- `FlateDecode` calls `ApplyPngPredictor` after `gzuncompress`.
- `LZWDecode` calls `ApplyPngPredictor` after LZW decompression.

Both unchanged at the public level.

### D4. Two-PR split (offered, optional)

The refactor + the new decoder COULD ship as one PR or two:

- **One PR**: refactor + new decoder + new tests, single review.
- **Two PRs**: (a) refactor PNG predictor (zero behaviour change, FlateDecode tests prove it), (b) LZWDecode consuming the helper.

The two-PR path is safer (the refactor lands first; the new code is reviewed against a known-stable helper) but doubles the round-trip. The PR opens the offer; the maintainer chooses.

### D5. Encode path is technically optional

We won't typically write LZW back to a document — the consumer's text-replacement output is usually FlateDecoded. But the encode path is included for round-trip completeness and to match the existing decoder pattern (`set_stream` cases match `get_stream` cases). The encoder is ~30 LOC; defer it to a follow-up if the maintainer prefers PR scope minimisation.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Off-by-one in the variable-bit-width transition (the `EarlyChange = 1` "grow early" semantics) | Medium-High | Fixture: a stream that crosses the 9→10-bit boundary; assert byte-equal decode |
| PNG-predictor refactor introduces a regression in FlateDecode | Low (verbatim move) | The existing FlateDecode test fixtures MUST decode byte-equal post-refactor; one verification step in the PR |
| LZW encoder produces non-decodable output | Low | Round-trip every fixture (encode then decode); discrepancy fails the PR |
| Patent concerns | None (expired 2003) | LZW patents expired worldwide by 2004; PDF spec uses the algorithm without restriction |


---

> **Implementation note**: canonical design + D1–D6 decision log live in `openspec/changes/feat-lzw-decode/design.md`. Key as-shipped facts: D6 fail-safe ships as `return false` (not raw stream) on all 4 LZW failure paths — truncated bit stream, dict overflow, KwKwK overflow, code out of range; `applyPngPredictor` returns `string|false` (the original `null` docblock was wrong — it never returned null); the `+1` in `($nextCode + 1) >= $threshold` is the decoder-lag correction, orthogonal to EarlyChange (both EarlyChange=0 and EarlyChange=1 work with it). Pre-existing upstream PNG Sub-filter bug (`$data[$i] = ($data[$i] + $data[$i-1]) % 256` doing string-arithmetic on single-character strings) fixed inline since LZW now reaches the same code path. Carried-over Flate limitations (Predictor=2 TIFF rejected, Colors≠1 rejected, Paeth/Average filter bytes rejected) now apply to LZW too — Predictor=15 (auto-select) image streams may silently fail.
