## Why

`ASCIIHexDecode` is the PDF 1.7 §7.4.2 filter that wraps binary stream bytes in `0..9A..Fa..f` characters terminated by `>`. It's used by sanitisers, older toolchains, and a small but real fraction of in-the-wild PDFs as the outer envelope of a filter chain (e.g. `[/ASCIIHexDecode /FlateDecode]`). Upstream sapp does not implement this filter — the dispatcher in `PDFObject` rejects it via the `p_error('unknown compression method ...')` path. Without it, any chain-encoded content stream that uses an ASCIIHex outer wrapper falls through to the no-op path and the text-replacement pipeline produces corrupted output on those PDFs.

## What Changes

- Add `ASCIIHexDecode` encode + decode primitives in `PDFObject`, mirroring the shape of the existing `FlateDecode` static helper.
- Wire the filter name `/ASCIIHexDecode` into the chain dispatcher introduced by `feat-filter-chain-dispatch` (upstream-PR #05).
- Spec-faithful behaviour per §7.4.2:
    - Decode: accept `0..9A..Fa..f`, ignore whitespace + EOL anywhere, terminate at `>` (EOD marker), tolerate odd-length input by treating the trailing hex digit as if followed by `0`.
    - Encode: emit uppercase hex pairs with a trailing `>` EOD marker; line-wrap at 80 columns (Adobe's de-facto convention; not normative but matches reader expectations).
- No `/DecodeParms` support — ASCIIHexDecode has no parameters (Table 5).

## Capabilities

### New Capabilities

- `asciihex-decode-filter`: PDF 1.7 §7.4.2 ASCIIHexDecode encode + decode, plugged into the filter chain dispatcher.

### Modified Capabilities

- `filter-chain-dispatch`: extend the dispatcher's `case` table to recognise `/ASCIIHexDecode` and route encode/decode calls to the new helper.

## Impact

- **Touched files**: `src/PDFObject.php` (+ASCIIHexDecode static helpers, +chain-dispatch `case` arm; ~60 LOC).
- **Public API**: none. Pure dispatch-table extension.
- **Depends on**: `feat-filter-chain-dispatch` (upstream-PR #05).
- **Unblocks**: real-world PDFs whose content streams use `[/ASCIIHexDecode /FlateDecode]`.
- **Upstream-PR draft**: `docs/upstream-prs/01-asciihex-decode/`.
- **No new composer dependencies.** PHP ≥ 7.4. snake_case method names preserved.
