## 1. Pre-flight

- [x] 1.1 Confirm `feat-filter-chain-dispatch` is merged into `work/text-replacement`
- [x] 1.2 Branch off as `feat/runlength-decode`
- [x] 1.3 Re-read PDF 1.7 §7.4.5 — confirm length-byte semantics

## 2. Decode helper

- [x] 2.1 Add `protected static function RunLengthDecode($_stream, $params)` in `src/PDFObject.php`
- [x] 2.2 Implement the state machine: literal blocks (`0..127`), repeat blocks (`129..255`), EOD (`128`)
- [x] 2.3 Defensive bounds checks before `substr()` calls — `p_error()` + return `false` on underflow / missing-EOD (D3; chain dispatcher's `=== false` short-circuit contract — match upstream `p_error`'s default return)

## 3. Encode helper

- [x] 3.1 Add `protected static function RunLengthEncode($_stream, $params)` — greedy run detection (D2)
- [x] 3.2 Cap literal blocks at 128 bytes; cap repeat blocks at 128 bytes
- [x] 3.3 Emit trailing `\x80` EOD marker (always, including empty-input case)

## 4. Chain-dispatch integration

- [x] 4.1 Add `case 'RunLengthDecode'` to `apply_filter_chain_decode` (note: the dispatcher strips the leading `/` before the switch — case label is the bare name without slash)
- [x] 4.2 Add symmetric `case 'RunLengthDecode'` arm to `apply_filter_chain_encode` routing to `self::RunLengthEncode(...)`

## 5. Tests / verification

- [x] 5.1 Round-trip fixture `examples/poc-filter-roundtrip-runlength.php`: 1024-byte mixed-run input
- [x] 5.2 Verify `examples/poc-replace-text.php` still exits 0
- [x] 5.3 Negative test: truncated literal / truncated repeat / missing EOD → `p_error()` + return `false`
- [x] 5.4 Chain test `[/RunLengthDecode /FlateDecode]` round-trip
- [x] 5.5 Chain-failure-propagation test: outer truncated RLE layer MUST short-circuit and return `false` (matches ASCIIHexDecode's PR #04 pattern)
- [x] 5.6 Encoder boundary tests: 128-byte and 256-byte repeat-block boundaries

## 6. Upstream-PR draft

- [x] 6.1 Update `docs/upstream-prs/02-runlength-decode/{proposal,design,tasks}.md`
- [x] 6.2 Leave `Posted at: <pending>` placeholder

## 7. Quality gate

- [x] 7.1 PHP 7.4 compatibility
- [x] 7.2 No new composer dependencies
- [x] 7.3 snake_case discipline on new helpers

## 8. Commit + PR

- [x] 8.1 Commit on `feat/runlength-decode`
- [x] 8.2 Open PR `feat/runlength-decode → work/text-replacement`
