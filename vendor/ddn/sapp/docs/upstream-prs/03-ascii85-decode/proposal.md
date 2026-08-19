# Proposal — `/ASCII85Decode` filter support

## Why

Continues the filter-decoder series (see `01-asciihex-decode/proposal.md`). `/ASCII85Decode` is the base-85 binary-to-text wrapper from PDF 1.7 §7.4.3, almost always paired with `/FlateDecode` in chains (`/Filter [/ASCII85Decode /FlateDecode]`). Frequency in real-world Woo PDFs is low single digits, but the chains make it disproportionately impactful — when a PDF uses ASCII85 + Flate, the outer ASCII85 must decode first or the inner FlateDecode call sees garbage. Without this filter we'd lose access to a small but real set of PDFs.

Posted after #01 and #02 so the maintainer's review pattern is established.

## What Changes

- **NEW:** Static helper `PDFObject::ASCII85Decode($stream)`. Implements §7.4.3:
  - Strip whitespace.
  - Stop at the `~>` EOD marker.
  - Decode each 5-byte ASCII85 group (`'!'` through `'u'`) to 4 raw bytes via base-85 arithmetic.
  - Expand the `z` shorthand (single `z` → 4 zero bytes).
  - Pad short final group with `u` (the highest valid ASCII85 char) and trim the corresponding padding bytes off the decoded output.
- **NEW case** in `PDFObject::get_stream()` switch.
- **NEW case** in `PDFObject::set_stream()` — encode via base-85 with `~>` EOD marker. No whitespace insertion (legal under the spec).
- **NO change** to the existing FlateDecode / ASCIIHexDecode / RunLengthDecode paths.

## Impact

- **Spec target:** PDF 1.7 §7.4.3 (ASCII85Decode Filter).
- **Why this filter matters more than its raw frequency suggests:** typically paired with FlateDecode in chains. Single-filter handling won't help these chained cases — those wait on PR #05 (filter chaining). But the underlying decoder MUST be in place first, so this PR ships independently.
- **Out of scope:** filter chaining (PR #05 unlocks the pairing with FlateDecode), other filters in the series.


---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-ascii85-decode/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/ascii85-decode-filter/spec.md`). Key as-shipped facts: spec violations (illegal char, 1-char partial group, overflow beyond `s8W-!` = `2^32-1`, PCRE failure) → `p_error()` + `return false` per the chain dispatcher's `=== false` contract. Encoder requires 64-bit PHP ints (defensive 32-bit masking removed — it was both dead on 64-bit and broken on 32-bit).
