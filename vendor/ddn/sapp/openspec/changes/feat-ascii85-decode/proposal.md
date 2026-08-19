## Why

`ASCII85Decode` (PDF 1.7 §7.4.3) encodes 4 binary bytes per 5 printable ASCII characters in the range `!..u`, with a single-character shortcut `z` for four zero bytes. It's used as an outer envelope in PDFs that need to pass through 7-bit-clean transports while still being more compact than ASCIIHex. Real-world appearances include certain Adobe Distiller outputs and PDFs produced by older Mac toolchains. Upstream sapp doesn't implement it; without it, any chain-encoded content stream that uses an ASCII85 outer wrapper falls through to the no-op path and the text-replacement pipeline produces corrupted output on those PDFs.

## What Changes

- Add `ASCII85Decode` encode + decode primitives in `PDFObject`.
- Wire `/ASCII85Decode` into the chain dispatcher introduced by `feat-filter-chain-dispatch`.
- Spec-faithful per §7.4.3:
    - Decode: 5-char groups in `!..u` (codepoints 33..117) decode to 4 bytes via base-85. The shortcut `z` decodes to four zero bytes. EOD marker is `~>`. Trailing groups of fewer than 5 chars decode to fewer than 4 bytes (1 → impossible; 2 → 1 byte; 3 → 2 bytes; 4 → 3 bytes), padded with `u` (codepoint 117) for the missing chars.
    - Whitespace anywhere is ignored.
- No `/DecodeParms` (Table 5).

## Capabilities

### New Capabilities

- `ascii85-decode-filter`: PDF 1.7 §7.4.3 ASCII85Decode encode + decode plugged into the filter chain dispatcher.

### Modified Capabilities

- `filter-chain-dispatch`: extend the `case` table with `/ASCII85Decode`.

## Impact

- **Touched files**: `src/PDFObject.php` (+ASCII85 helpers, +dispatch arms; ~80 LOC).
- **Public API**: none.
- **Depends on**: `feat-filter-chain-dispatch`.
- **Upstream-PR draft**: `docs/upstream-prs/03-ascii85-decode/`.
