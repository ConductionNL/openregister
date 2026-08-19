## Why

`LZWDecode` (PDF 1.7 §7.4.4) is the Lempel-Ziv-Welch variable-width-code compression filter inherited from TIFF/PostScript. It predates `FlateDecode` and shows up primarily on older PDFs (Adobe Acrobat 3-4 era) and PDFs produced by toolchains targeting wide reader compatibility. Like the other filters, it's a hard requirement for downstream PRs to plug into; without it, any chain containing `/LZWDecode` falls through to the no-op path and the text-replacement pipeline produces corrupted output on those PDFs.

## What Changes

- Add `LZWDecode` encode + decode primitives in `PDFObject`.
- Wire `/LZWDecode` into the chain dispatcher.
- Spec-faithful per §7.4.4:
    - Variable-width codes start at 9 bits and grow to a maximum of 12 bits as the dictionary fills.
    - Reserved codes: `256` (clear table — reset to 9-bit codes), `257` (EOD). User codes start at `258`.
    - `EarlyChange` parameter (default `1`) controls whether the bit-width advance happens before or after emitting the code that fills the current width.
- Support the PNG predictor scheme per §7.4.4.4 (parity with `FlateDecode`'s predictor handling).
- `/DecodeParms` accepts `Predictor`, `Colors`, `BitsPerComponent`, `Columns`, `EarlyChange`.

## Capabilities

### New Capabilities

- `lzw-decode-filter`: PDF 1.7 §7.4.4 LZWDecode encode + decode with `EarlyChange` + PNG predictor support, plugged into the filter chain dispatcher.

### Modified Capabilities

- `filter-chain-dispatch`: extend the `case` table with `/LZWDecode`.

## Impact

- **Touched files**: `src/PDFObject.php` (+LZW helpers + predictor reuse from `FlateDecode`; ~180 LOC — this is the largest of the four filter PRs).
- **Public API**: none.
- **Depends on**: `feat-filter-chain-dispatch`.
- **Upstream-PR draft**: `docs/upstream-prs/04-lzw-decode/`.
