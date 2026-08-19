## Why

`RunLengthDecode` is the PDF 1.7 §7.4.5 filter used to compress streams with simple run-length encoding — primarily monochrome image data, but also occasionally seen on small content streams emitted by older toolchains. Upstream sapp doesn't implement it; without it, any chain containing `/RunLengthDecode` falls through to the no-op path and the text-replacement pipeline produces corrupted output.

## What Changes

- Add `RunLengthDecode` encode + decode primitives in `PDFObject`.
- Wire `/RunLengthDecode` into the chain dispatcher introduced by `feat-filter-chain-dispatch`.
- Spec-faithful semantics per §7.4.5:
    - Length byte `L`: if `0 ≤ L ≤ 127`, copy `L + 1` literal bytes that follow. If `129 ≤ L ≤ 255`, repeat the next single byte `257 - L` times. If `L == 128`, EOD marker — stop decoding.
    - No `/DecodeParms` (Table 5).
- Encode: emit greedy run-length encoding. Single-byte literals are wasteful; runs of 2+ identical bytes use the repeat form.

## Capabilities

### New Capabilities

- `runlength-decode-filter`: PDF 1.7 §7.4.5 RunLengthDecode encode + decode plugged into the filter chain dispatcher.

### Modified Capabilities

- `filter-chain-dispatch`: extend the `case` table with `/RunLengthDecode`.

## Impact

- **Touched files**: `src/PDFObject.php` (+RunLength helpers, +dispatch arms; ~70 LOC).
- **Public API**: none.
- **Depends on**: `feat-filter-chain-dispatch`.
- **Upstream-PR draft**: `docs/upstream-prs/02-runlength-decode/`.
