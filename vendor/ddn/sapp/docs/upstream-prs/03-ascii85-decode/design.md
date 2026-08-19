# Design — `/ASCII85Decode`

## Decisions

### D1. Base-85 arithmetic, byte-by-byte

ASCII85 encodes 4-byte groups as 5 ASCII characters. The decoder accumulates a base-85 integer from each 5-char group, then `pack('N', $value)` produces the 4 output bytes. PHP's native int is 64-bit on all supported platforms (≥ PHP 7.4 per `composer.json`), so the maximum value `85^5 - 1` fits comfortably.

### D2. `z` shorthand

A literal `z` character (outside any 5-char group) expands to 4 zero bytes. The decoder MUST check for `z` before grouping; once inside a group, the bytes are interpreted as base-85 digits regardless.

### D3. Short final group

If the input ends with a partial 5-char group (e.g. 3 chars), pad it with `'u'` (the highest valid char, value 84). After decoding the padded group, trim `(5 - chunk_len)` bytes off the resulting 4-byte output. Spec-mandated; the encoder uses the inverse rule.

### D4. EOD marker is `~>` not `>`

ASCII85's EOD marker is two characters (`~>`), distinct from ASCIIHex's single `>` marker. The decoder MUST handle this correctly — searching for `~>` as a string. (PDF readers tolerate streams without the EOD marker, treating EOS as the terminator; we do the same.)

### D5. Encode path

Mirror the decoder: 4 input bytes → 5-char ASCII85 group. Final partial group (1–3 bytes) → ASCII85 group of (1 + len) chars, no padding emitted. Optimisation: 4 zero bytes → single `z` character (encoder-optional optimisation; we'll do it for size).

### D6. Architectural choices same as #01, #02

`protected static` helper on `PDFObject`, dispatch by per-string switch, no registry abstraction yet. PR #05 (filter chaining) is the dispatch refactor moment.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Int overflow on 32-bit PHP builds | Very low | composer.json requires PHP ≥ 7.4; 64-bit ints required on supported PHP versions |
| Invalid char in input (e.g. `~` mid-stream not part of `~>`) | Low | Unit-test the `~` followed by non-`>` case; expected behaviour: `p_error` "invalid char" |
| Empty final group misinterpreted as `z` | Low | Tokenise `z` explicitly BEFORE 5-char grouping; can't be confused |


---

> **Implementation note**: canonical design + decision log live in `openspec/changes/feat-ascii85-decode/design.md` (D1–D5). Key as-shipped facts: D4 ships as `return false` on spec violation (illegal char, `z` mid-group, `~` not paired with `>`, 1-char partial group per §7.4.3, overflow beyond `2^32-1`, or PCRE failure); encoder requires 64-bit PHP ints; the spec-imposed maximum 5-char group is `s8W-!` (NOT `uuuuu`, which exceeds the cap).
