# Proposal — `/RunLengthDecode` filter support

## Why

Continues the filter-decoder series (see `01-asciihex-decode/proposal.md` for context). `/RunLengthDecode` is the second-simplest of the text-relevant filters in PDF 1.7 §7.4.5 — a byte-level RLE format with a length-prefix-per-run convention that PDF inherited from PostScript. Frequency in real-world PDFs is low single digits, but the discovery committed to comprehensive filter coverage so the downstream text-replacement consumer doesn't silently lose data on uncommon-but-spec-defined inputs.

Posting this PR after `/ASCIIHexDecode` lands (or alongside it as a parallel PR) builds the contribution cadence without compounding review surface.

## What Changes

- **NEW:** Static helper `PDFObject::RunLengthDecode($stream)`. Reads one length byte (0–127 = literal-run length+1, 129–255 = repeat-byte count, 128 = EOD), emits the corresponding output bytes, advances the cursor, repeats. ~20 lines of PHP.
- **NEW case** in `PDFObject::get_stream()` switch.
- **NEW case** in `PDFObject::set_stream()` — encode via straightforward length-prefixed literal runs (no compression-aware encoding logic needed; we don't try to find optimal run boundaries).
- **NO change** to the existing FlateDecode / ASCIIHexDecode paths.

## Impact

- **Spec target:** PDF 1.7 §7.4.5 (RunLengthDecode Filter).
- **Out of scope:** filter chaining (PR #05), other filters (other PRs in the series), compression-optimal encoding (we emit a simple length-prefixed literal stream on the encode path).


---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-runlength-decode/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/runlength-decode-filter/spec.md`). See `design.md` for the D1–D6 decisions, including the truncation fail-safe contract (`return false`) and the 64× decode-amplification trust note.
