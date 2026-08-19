# Design — `/ASCIIHexDecode`

## Decisions

### D1. Match the existing FlateDecode pattern

`PDFObject::get_stream()` currently has a single `switch ($this->_value['Filter'])` dispatching by string. Add one more `case` for `/ASCIIHexDecode`. Add the corresponding `case` to `set_stream()` for round-trip integrity.

The static-helper-per-filter pattern stays — even though we'll eventually want a dispatch table when the series grows (filter chaining, PR #05 introduces it). For this first PR, mirroring the established shape keeps the diff minimal and the review easy.

### D2. Spec-faithful decoder

PDF 1.7 §7.4.2 defines:

- Whitespace within the hex stream MUST be ignored.
- `>` is the EOD marker; content after it is ignored.
- Odd-length input is interpreted as if a trailing `0` were appended.
- Invalid hex characters MUST be reported (we route through `p_error`).

The decoder handles all four. The `@hex2bin` (error-suppressed) call catches PHP's own validation; we wrap that in a `p_error` route to keep the failure path quiet on the upstream pattern.

### D3. Encode path mirrors decode

Set-side: `bin2hex($stream) . '>'`. No whitespace insertion (legal under the spec — readers tolerate any whitespace pattern), no line wrapping. Keeps the output minimal and the round-trip byte-stable.

### D4. No filter chaining in this PR

PDF 1.7 allows `/Filter [/ASCII85Decode /FlateDecode]` (apply in order). The current `get_stream()` doesn't handle that array form; this PR doesn't either. Chaining lives in PR #05 once the decoder set is in place — that's the right time to refactor the dispatch.

### D5. No filter registration abstraction (yet)

A nicer design would be a `FilterRegistry` with one class per filter. We don't introduce it here — small diffs land faster, and refactoring later is easy once we know what the dispatch surface needs to do. PR #05 (filter chaining) is the natural moment to discuss registry-vs-switch.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Maintainer rejects the spot-add approach, prefers a registry abstraction up-front | Low | Discuss in the issue (the "ask" section) before opening the PR |
| Edge-case input not caught by `@hex2bin` (e.g. valid hex but unexpected length pattern) | Low | Unit test odd-length, whitespace-rich, and invalid-character inputs |
| Round-trip diff vs. original byte layout (whitespace stripped, EOD position shifted) | Acceptable | PDF readers parse the same logical content; byte-identical round-trip is not promised by the spec |


---

---

## Implementation note

See `openspec/changes/feat-asciihex-decode/design.md` (the canonical artefact) for the shipped-implementation note. This file keeps the original proposal/design/tasks content for the eventual upstream submission to dealfonso/sapp; implementation specifics are tracked in the OpenSpec change to avoid duplicate-source drift.
