## Why

Upstream sapp's `PDFObject::set_stream` / `PDFObject::get_stream` hard-code a single string-typed `/Filter` value (only the literal `'/FlateDecode'` branch is wired up). PDF 1.7 §7.4 explicitly defines an array form for `/Filter` so a stream can be decoded by a chain of filters (e.g. `[/ASCIIHexDecode /FlateDecode]` — outer ASCII hex envelope wrapping a Flate-compressed payload). Real-world content streams produced by sanitisers, older toolchains, and some forensic pipelines use this array form regularly, and every downstream filter PR in the upstream-PR series (`#01` ASCIIHex / `#02` ASCII85 / `#03` RunLength / `#04` LZW) needs a place to plug in. Without chain dispatch first, the four follow-up filter PRs have nowhere to attach, and our text-replacement feature (`replaceTextInDocument` on `work/text-replacement`) can't operate on chain-encoded content streams at all.

## What Changes

- Refactor `PDFObject::set_stream($stream, $raw)` to walk `/Filter` when `$raw === false`:
    - **String form** (e.g. `/FlateDecode`) — behaviour unchanged (one-shot encode through that filter).
    - **Array form** (e.g. `[/ASCIIHexDecode /FlateDecode]`) — encode by applying filters in REVERSE order (innermost-first, PDF 1.7 §7.4.1 ¶3); update `/Length` to the final encoded byte count.
- Refactor `PDFObject::get_stream($raw)` symmetrically: array form decodes in FORWARD order (outermost-first).
- Handle the parallel `/DecodeParms` array shape (one entry per filter; `null` permitted as a placeholder for filters without parameters per PDF 1.7 §7.4.1 Table 5).
- Unknown filter names in the chain emit a `p_error()` warning (matches upstream's existing convention for unsupported predictors / colours / bit-counts) and leave the stream unchanged so the caller can detect the failure mode.
- No new filter implementations land in this change — that's upstream-PR #01–`#04` territory. This change is purely the dispatch surface.

## Capabilities

### New Capabilities

- `filter-chain-dispatch`: PDF 1.7 §7.4 array-form `/Filter` chain processing on encode + decode, with parallel `/DecodeParms` array handling and defensive logging for unknown filter names. Provides the plug-in surface that the per-filter PRs (`#01`–`#04`) attach to.

### Modified Capabilities

<!-- None — this is a brand-new dispatch capability; the previous string-only handling lives entirely inside the new capability's scope. -->

## Impact

- **Touched files**: `src/PDFObject.php` (the `set_stream` and `get_stream` switches; ~40 LOC delta).
- **Public API**: No changes. Existing string-form `/Filter` callers continue to work unmodified. The array-form input path is additive.
- **Downstream PRs**: Unblocks the upstream-PR series `#01` (ASCIIHex), `#02` (ASCII85), `#03` (RunLength), `#04` (LZW) — each of those just appends a `case` to the new chain-aware dispatcher.
- **Consumer (OpenRegister `pdf-anonymisation`)**: The PoC's `replaceTextInDocument()` on `work/text-replacement` already detects FlateDecode-only streams; once chain dispatch lands, the heuristic can extend to "any chain that contains FlateDecode" without further public-API churn.
- **Validation gate**: `examples/poc-replace-text.php` remains green (single FlateDecode round-trip).
- **Upstream-PR draft**: Final implementation notes land in `docs/upstream-prs/05-filter-chaining/` for eventual submission to `dealfonso/sapp`.
- **No external dependencies added.** Stays PHP ≥ 7.4 compatible. snake_case method names preserved (upstream convention).
