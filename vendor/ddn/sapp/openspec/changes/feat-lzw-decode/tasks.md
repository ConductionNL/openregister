## 1. Pre-flight

- [x] 1.1 Confirm `feat-filter-chain-dispatch` is merged into `work/text-replacement`
- [x] 1.2 Branch off as `feat/lzw-decode`
- [x] 1.3 Re-read PDF 1.7 §7.4.4 + §7.4.4.3 (`EarlyChange`) + §7.4.4.4 (predictor)
- [x] 1.4 Find or create a reference LZW-encoded fixture decodable by Adobe Reader to lock the EarlyChange behaviour

## 2. Refactor: lift PNG predictor out of FlateDecode

- [x] 2.1 Add `protected static function applyPngPredictor(string $bytes, array $params): string|false` in `src/PDFObject.php` containing the existing predictor logic (docblock fixed to `string|false`; method never returned `null` despite earlier docblock claim)
- [x] 2.2 Update `FlateDecode` to call `applyPngPredictor` instead of inlining the row-filter loop
- [x] 2.3 Verify `examples/poc-replace-text.php` still exits 0 (predictor path unchanged for FlateDecode callers)
- [x] 2.4 Fix pre-existing PNG Sub-filter string-arithmetic bug — `$data[$i] = ($data[$i] + $data[$i-1]) % 256` coerced single-char strings to numeric and produced broken output. Now uses `chr((ord(...) + ord(...)) % 256)` mirroring the Up filter

## 3. Bit-stream helpers

- [x] 3.1 Add `protected static function lzw_read_code(string $stream, int &$bitPos, int $codeWidth): int` — MSB-first variable-width code reader
- [x] 3.2 Add a symmetric write helper for the encoder

## 4. Decode helper

- [x] 4.1 Add `protected static function LZWDecode($_stream, $params)`
- [x] 4.2 Initialise dictionary (256 single-byte entries + reserved 256/257)
- [x] 4.3 Implement the state machine: clear code, EOD, user codes, KwKwK special case
- [x] 4.4 Honour `EarlyChange` (default 1; honour 0 if specified) — D5
- [x] 4.5 Cap dictionary growth at 4096; on overflow / KwKwK overflow / truncation / out-of-range code → `p_error()` + `return false` (matching chain dispatcher's `=== false` short-circuit contract; downstream filters MUST NOT see partial output)
- [x] 4.6 After LZW decode, call `applyPngPredictor` if `Predictor >= 10`; propagate `false` upward unchanged (note: `applyPngPredictor` docblock previously said `null` but always returned `string|false` — fixed in 2.x scope)

## 5. Encode helper

- [x] 5.1 Add `protected static function LZWEncode($_stream, $params)`
- [x] 5.2 Emit clear code at stream start (OQ2 — Acrobat-compatible)
- [x] 5.3 Standard LZW encode state machine; emit clear code on dictionary overflow
- [x] 5.4 Emit EOD code at the end
- [x] 5.5 Honour `EarlyChange` symmetrically with the decoder

## 6. Chain-dispatch integration

- [x] 6.1 Add `case 'LZWDecode'` arms in both `apply_filter_chain_decode` and `apply_filter_chain_encode` (note: dispatcher strips leading `/` — case label is the bare name without slash)

## 7. Tests / verification

- [x] 7.1 Round-trip fixture `examples/poc-filter-roundtrip-lzw.php`: 2048-byte buffer, default params
- [x] 7.2 Round-trip fixture with `EarlyChange = 0`
- [x] 7.3 Round-trip fixture with PNG predictor (`Predictor = 12, Columns = 4`) — filter byte 0 (None) AND filter byte 1 (Sub) coverage (Sub path exercises the previously-broken string-arithmetic loop)
- [x] 7.4 Adobe-compat test: decode a reference fixture produced by Adobe Distiller (or Ghostscript with `-sFilter=LZW`) and assert the expected plaintext
- [x] 7.5 Verify `examples/poc-replace-text.php` still exits 0
- [x] 7.6 Negative tests (each MUST return `false`): dictionary overflow; truncated bit stream; out-of-range code (300, no entries assigned yet); predictor rejection (`Predictor=15` + `Colors=3` triggers `applyPngPredictor` → `false`)
- [x] 7.7 Chain test `[/LZWDecode /FlateDecode]` round-trip + chain-failure-propagation test (outer truncated LZW MUST short-circuit before inner Flate runs)

## 8. Upstream-PR draft

- [x] 8.1 Update `docs/upstream-prs/04-lzw-decode/{proposal,design,tasks}.md` — include the predictor-refactor note for reviewers
- [x] 8.2 Leave `Posted at: <pending>` placeholder

## 9. Quality gate

- [x] 9.1 PHP 7.4 compatibility — no bitwise operations that depend on 64-bit ints (LZW max code width is 12 bits → safe on 32-bit)
- [x] 9.2 No new composer dependencies
- [x] 9.3 snake_case discipline on the bit-stream helpers; PascalCase on filter helper names (matches `FlateDecode`)

## 10. Commit + PR

- [x] 10.1 Commit on `feat/lzw-decode`
- [x] 10.2 Open PR `feat/lzw-decode → work/text-replacement` — flag the predictor refactor as a noteworthy review point
