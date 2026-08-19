## 1. Pre-flight

- [x] 1.1 Confirm `feat-filter-chain-dispatch` is merged into `work/text-replacement`
- [x] 1.2 Branch off as `feat/ascii85-decode`
- [x] 1.3 Re-read PDF 1.7 §7.4.3 — confirm `z` shortcut + EOD + partial-group padding semantics

## 2. Decode helper

- [x] 2.1 Add `protected static function ASCII85Decode($_stream, $params)` in `src/PDFObject.php`
- [x] 2.2 Strip whitespace, optionally strip leading `<~` (OQ1 — Adobe-tolerant)
- [x] 2.3 Process `z` shortcut as 4 zero bytes
- [x] 2.4 Process 5-char groups as base-85 integers, big-endian to 4 bytes
- [x] 2.5 Handle trailing partial group with `u` padding
- [x] 2.6 Fail-safe via `p_error()` + `return false` on any spec violation: illegal character (outside `!..u` plus `z` plus whitespace plus `~>` EOD), `z` mid-group, `~` not paired with `>`, 1-char trailing partial group (§7.4.3 requires `2 ≤ k ≤ 4`), overflow beyond `2^32 - 1` (max valid 5-char group is `s8W-!` — NOT `uuuuu` which exceeds the cap), or PCRE compile failure

## 3. Encode helper

- [x] 3.1 Add `protected static function ASCII85Encode($_stream, $params)`
- [x] 3.2 Walk input 4 bytes at a time; emit `z` for aligned 4-zero-byte groups (D3)
- [x] 3.3 Emit standard 5-char groups otherwise
- [x] 3.4 Handle trailing partial group (pad with `\x00` bytes, emit only `k+1` chars where `k` is the partial length)
- [x] 3.5 Emit trailing `~>` EOD marker

## 4. Chain-dispatch integration

- [x] 4.1 Add `case 'ASCII85Decode'` arms in both `apply_filter_chain_decode` and `apply_filter_chain_encode` (note: dispatcher strips leading `/` from filter names — case label is the bare name without slash)

## 5. Tests / verification

- [x] 5.1 Round-trip fixture `examples/poc-filter-roundtrip-ascii85.php`: 1024-byte buffer including 4-zero-byte runs (exercises `z` shortcut)
- [x] 5.2 Verify `examples/poc-replace-text.php` still exits 0
- [x] 5.3 Negative tests (each MUST return `false`): illegal char `{`, `~` mid-stream, `z` mid-group, 1-char trailing partial group `87cURD~>`, overflow guard `tttt~>` (yields 4,384,231,064 > 2^32-1)
- [x] 5.4 Chain test `[/ASCII85Decode /FlateDecode]` round-trip + chain-failure-propagation test (outer ASCII85 illegal char MUST short-circuit before inner Flate runs)
- [x] 5.5 Adobe-tolerance test: input with leading `<~` decodes correctly; also `<~~>` and bare `~>` decode to empty string
- [x] 5.6 Boundary coverage: round-trip for every partial-group residue n=1..9 (covers (4k, 4k+1, 4k+2, 4k+3) boundary between full-group and partial-group paths)

## 6. Upstream-PR draft

- [x] 6.1 Update `docs/upstream-prs/03-ascii85-decode/{proposal,design,tasks}.md`
- [x] 6.2 Leave `Posted at: <pending>` placeholder

## 7. Quality gate

- [x] 7.1 PHP 7.4 compatibility; requires 64-bit PHP ints (composer constraint effectively guarantees this on supported platforms; defensive 32-bit masking removed — dead on 64-bit and broken on 32-bit)
- [x] 7.2 No new composer dependencies
- [x] 7.3 snake_case discipline

## 8. Commit + PR

- [x] 8.1 Commit on `feat/ascii85-decode`
- [x] 8.2 Open PR `feat/ascii85-decode → work/text-replacement`
