# Design — `/RunLengthDecode`

## Decisions

### D1. Spec-faithful decoder

PDF 1.7 §7.4.5 defines the format byte-for-byte:

- Length byte 0–127: copy the next (length + 1) bytes literally.
- Length byte 128: end-of-data marker; stop.
- Length byte 129–255: repeat the next byte (257 − length) times.

The decoder reads bytes off the input in a single linear pass and accumulates output. ~20 lines including the EOD check.

### D2. Encode path is simple

We don't search for repeating runs. The encoder emits literal-run blocks of up to 128 bytes each, followed by the 128 EOD marker. This is not compression-optimal but it IS spec-valid, and the use case (round-tripping content streams during text replacement) doesn't benefit from RLE compression — the data we write is usually one-shot output, not designed to be re-read with RLE savings in mind. If a downstream consumer cares about output size, they can re-encode via FlateDecode later.

### D3. Same architectural choices as #01

The decoder lives as a `protected static` helper on `PDFObject`, dispatch is the per-string `switch`, no registry abstraction yet. PR #05 (filter chaining) is the moment to discuss restructuring the dispatch.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Off-by-one on the literal-run length (the spec's "length + 1" / "257 - length" inverse) | Medium | Three explicit unit tests covering minimum (`length=0` → 1 byte literal), middle, and maximum (`length=127` → 128-byte literal) boundary cases |
| Missing EOD marker in input — decoder runs past the buffer | Low | The decoder MUST tolerate truncated input gracefully; route through `p_error` on overrun |
| Encode path produces larger output than the FlateDecode equivalent | Acceptable | Documented in `proposal.md` §What Changes; RLE is not for compression here |


---

> **Implementation note**: canonical design lives in `openspec/changes/feat-runlength-decode/design.md` (D1–D6). The truncation fail-safe ships as `return false` per the chain dispatcher's `=== false` short-circuit contract; this matches upstream `p_error()`'s default return and ensures downstream filters never see a partially-decoded buffer.
