# Proposal — `/LZWDecode` filter support

## Why

Closes the four-decoder series (see `01-asciihex-decode/proposal.md` for the parent context). `/LZWDecode` was THE standard PDF compression filter pre-version-1.4 (1996-ish era). Modern PDFs use `/FlateDecode`, but legacy government-archive PDFs and some still-active scanning tools emit LZW. Sample rate in real-world Woo PDFs: <2%, but the discovery committed to comprehensive filter coverage so the downstream text-replacement consumer doesn't lose access to legacy documents.

LZW is bigger than the prior three decoders (~80 LOC for the algorithm + a small predictor-helper refactor). Posting it last in the decoder series lets the maintainer review the smaller PRs first; the bigger one arrives with the contribution flow already established.

## What Changes

- **NEW:** Static helper `PDFObject::LZWDecode($stream, $params)` implementing LZW decompression with the variable-bit-width code table per PDF 1.7 §7.4.4.
- **REFACTOR (no behaviour change):** Extract the PNG-predictor logic from the existing `FlateDecode` method into a new shared `protected static function ApplyPngPredictor($data, $params)` helper. Both `FlateDecode` and `LZWDecode` call into it. The refactor preserves byte-for-byte output on existing FlateDecode + predictor inputs.
- **NEW case** in `PDFObject::get_stream()` switch — calls `LZWDecode`, then `ApplyPngPredictor` if a predictor is configured.
- **NEW case** in `PDFObject::set_stream()` — LZW-encode for round-trip parity. Practical use is rare (we don't typically write LZW back) but spec-complete.

## Impact

- **Spec target:** PDF 1.7 §7.4.4 (LZWDecode Filter).
- **Refactor risk:** small — the extracted PNG-predictor function is byte-identical to the current FlateDecode predictor logic; existing FlateDecode tests must still pass.
- **Two-PR split option:** if the maintainer prefers, split into (a) PNG-predictor refactor as a no-behaviour-change PR, (b) LZWDecode consuming the helper. Lower-risk staging at a small review-overhead cost. See `issue.md` § "Refactor note" — open offer in the upstream issue.
- **Out of scope:** filter chaining (PR #05), the rare `EarlyChange = 0` LZW mode (`EarlyChange = 1` is the standard; we support that path).


---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-lzw-decode/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/lzw-decode-filter/spec.md`). Key as-shipped facts: all four failure paths (truncated bit stream, dict overflow, KwKwK overflow, out-of-range code) return `false` per the chain dispatcher's `=== false` contract; `applyPngPredictor` returns `string|false` (docblock previously said `null` but that was wrong); the `+1` in `($nextCode + 1) >= $threshold` is the decoder-lag correction (orthogonal to EarlyChange — both work with it).
