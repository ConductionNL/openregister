## 1. Pre-flight

- [x] 1.1 Confirm `feat-filter-chain-dispatch` (upstream-PR #05; fork PR #3) is merged into the integration line OR available as the stack-parent branch
- [x] 1.2 Branch off `feat/filter-chain-dispatch` as `feat/asciihex-decode` (stacked PR; base is the dispatcher branch, NOT `work/text-replacement` directly — once #05 merges upstream, this PR's base auto-retargets)
- [x] 1.3 Re-read PDF 1.7 §7.4.2 and confirm decoder rules (whitespace + odd-length + EOD + alphabet)

## 2. Decode helper

- [x] 2.1 Add `protected static function ASCIIHexDecode($_stream, $params)` in `src/PDFObject.php`, modelled on the existing `FlateDecode` static helper
- [x] 2.2 Implement the decode state machine: accept hex digits + whitespace, terminate at `>`, pad odd trailing nibble with `0`, fail-safe on illegal characters via `p_error()` + return `false` (the chain dispatcher's `=== false` short-circuit contract; matches upstream `p_error`'s default return)

## 3. Encode helper

- [x] 3.1 Add `protected static function ASCIIHexEncode($_stream, $params)` — emit uppercase hex pairs, line-wrap at 80 columns, terminate with `>`
- [x] 3.2 Handle the empty-input edge case (emit just `>`)

## 4. Chain-dispatch integration

- [x] 4.1 Add a `case 'ASCIIHexDecode'` arm to `apply_filter_chain_decode` (introduced in upstream-PR #05; note the dispatcher strips the leading `/` from filter names before the switch — case label is the bare name without slash) routing to `self::ASCIIHexDecode(...)`
- [x] 4.2 Add the symmetric arm in `apply_filter_chain_encode` routing to `self::ASCIIHexEncode(...)`

## 5. Tests / verification

- [x] 5.1 Add a round-trip fixture in `examples/poc-filter-roundtrip-asciihex.php`: random 1024-byte buffer → encode → decode → assert byte-for-byte equality
- [x] 5.2 Verify the existing `examples/poc-replace-text.php` still exits 0 (FlateDecode-only path unchanged)
- [x] 5.3 Add a chain test exercising `[/ASCIIHexDecode /FlateDecode]` end-to-end (encode plaintext → assert decoded matches)
- [x] 5.4 Add a negative test asserting `p_error` on an illegal-character input and stream-unchanged behaviour

## 6. Upstream-PR draft

- [x] 6.1 Update `docs/upstream-prs/01-asciihex-decode/proposal.md` with the spec REQ references
- [x] 6.2 Update `docs/upstream-prs/01-asciihex-decode/design.md` with D1–D5 decision log
- [x] 6.3 Update `docs/upstream-prs/01-asciihex-decode/tasks.md` with the implementation summary
- [x] 6.4 Leave `Posted at: <pending>` placeholder

## 7. Quality gate

- [x] 7.1 PHP 7.4 compatibility check (no typed properties, no enums, no readonly)
- [x] 7.2 No new composer dependencies
- [x] 7.3 snake_case method discipline (`ASCIIHexDecode` / `ASCIIHexEncode` follow upstream's PascalCase-on-filter-names + snake_case-on-utility-method convention; verify against `FlateDecode`)

## 8. Commit + PR

- [x] 8.1 Commit on `feat/asciihex-decode`
- [x] 8.2 Open PR `feat/asciihex-decode → work/text-replacement`
