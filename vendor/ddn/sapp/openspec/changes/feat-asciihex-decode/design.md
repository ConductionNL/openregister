## Context

ASCIIHexDecode is the simplest filter in PDF 1.7 §7.4. Decode rules:

- Input alphabet: `0..9 A..F a..f` (case-insensitive).
- Whitespace (`\x00 \t \n \f \r \x20`) is permitted anywhere and ignored.
- Termination: `>` (the EOD marker). Any input after `>` is ignored.
- Odd length: if EOD is reached after an odd number of hex digits, the missing trailing nibble is treated as `0`.
- Illegal characters: raise an error.

Encode rules:

- Output uppercase hex pairs, one pair per byte.
- Terminate with `>`.
- Line-wrap at 80 columns (not normative, but the de-facto convention in Adobe Distiller output; readers must tolerate any width).

Upstream sapp's `FlateDecode` static helper is the structural reference — same calling convention, same `p_error` failure mode.

## Goals / Non-Goals

**Goals:**

- Round-trip lossless: `decode(encode($x))` MUST equal `$x` for arbitrary binary input.
- Permissive decode: tolerate the full set of whitespace placements that real-world ASCIIHex streams use (Adobe Distiller emits 80-col line wraps; some sanitisers use single-line; some use 64-col).
- Spec-faithful encode: PDF 1.7 §7.4.2 doesn't mandate uppercase but explicitly allows it; we pick uppercase for determinism / diff-stability of test fixtures.

**Non-Goals:**

- ASCII85 (separate change `feat-ascii85-decode`).
- `/DecodeParms` handling — ASCIIHexDecode has no parameters per PDF 1.7 Table 5.
- Encryption (`/Crypt`).

## Decisions

### D1 — Static helpers symmetric to `FlateDecode`

Mirror upstream's existing `protected static function FlateDecode(...)` shape: add `protected static function ASCIIHexDecode($_stream, $params)` and `protected static function ASCIIHexEncode($_stream, $params)` inside `PDFObject`. Keeps the dispatch table's call sites uniform.

### D2 — Decode tolerates malformed input via `p_error` + return raw input

When the decoder encounters an illegal character (anything outside `0..9A..Fa..f \s >`), it calls `p_error()` and returns the input bytes unchanged. Consistent with the rest of sapp's filter machinery. Strictness is the caller's responsibility.

### D3 — Encode line-wraps at 80 columns

Hard-coded `80` constant in the encoder. Spec-permissive on either side — readers MUST tolerate any width — but 80 matches the most common in-the-wild emission. No knob exposed; if a future caller needs a different width, it's a one-line patch.

### D4 — Odd-length input pads with `0`

PDF 1.7 §7.4.2 ¶3 is explicit: "If the filter encounters the EOD marker after reading an odd number of hexadecimal digits, it shall behave as if a 0 (zero) followed the last digit." Implementation: track parity in a local variable, finish the partial byte on EOD.

### D5 — `>` past column boundaries is recognised

The EOD marker `>` MUST be recognised regardless of line-wrap state. The state machine treats whitespace as no-op and only acts on hex digits or `>`.

## Risks / Trade-offs

- **Risk**: A PDF in the wild uses an unusual whitespace character (vertical tab, BOM). → **Mitigation**: PDF 1.7 §7.5.1 enumerates the whitespace set; we match that set exactly. Other characters are illegal per spec.

- **Trade-off**: Hard-coding 80-column wrap means a forensic round-trip against a 64-column-wrapped source produces a byte-different (but semantically identical) re-encoded stream. → **Mitigation**: spec confirms readers MUST tolerate any width; document the choice in PHPDoc.

## Migration Plan

Strictly additive. No migration needed. Rollback = revert the commit (no public API surface to clean up).

## Open Questions

- **OQ1**: Lowercase hex on encode for compactness in the (rare) case that a downstream consumer is case-sensitive? **Provisional**: uppercase. Spec-permissive; reader behaviour identical; uppercase is more common in fixtures.
