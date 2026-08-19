## 1. Pre-flight

- [x] 1.1 Branch off `work/text-replacement` as `feat/filter-chain-dispatch`
- [x] 1.2 Re-read PDF 1.7 §7.4.1 ¶3 (chain ordering) + Table 5 (`/DecodeParms`) and confirm the dispatcher's contract matches the spec word-for-word
- [x] 1.3 Capture the baseline: `php examples/poc-replace-text.php` exits 0 with `residual_needles=0, placeholder_hits=1, streams_modified=1` against today's `src/PDFObject.php`

## 2. Dispatcher helpers in `src/PDFObject.php`

- [x] 2.1 Add `private static function normalise_filter_chain($filterValue): array` — coerces a `PDFValue` for `/Filter` (string-form, array-form, missing, or empty) into a plain PHP array of filter-name strings
- [x] 2.2 Add `private static function normalise_decode_parms_chain($decodeParmsValue, int $chainLength): array` — coerces `/DecodeParms` (single dict, array of dicts/null, missing) into a fixed-length array indexed by chain position (D3)
- [x] 2.3 Add `private static function apply_filter_chain_decode(string $bytes, array $filters, array $params)` — applies filters in FORWARD order; on unknown filter emits `p_error()` and returns `false` (D4)
- [x] 2.4 Add `private static function apply_filter_chain_encode(string $bytes, array $filters, array $params)` — applies filters in REVERSE order; same failure semantics

## 3. Refactor `get_stream` / `set_stream`

- [x] 3.1 Refactor `PDFObject::get_stream($raw)` (`src/PDFObject.php`) to call `apply_filter_chain_decode()` when `$raw === false` and the object has a `/Filter` entry; preserve the existing pass-through behaviour when `/Filter` is missing or coerces to an empty chain
- [x] 3.2 Refactor `PDFObject::set_stream($stream, $raw)` to call `apply_filter_chain_encode()` when `$raw === false`; preserve the existing `_value['Length']` update and the unchanged-on-failure semantics (D4)
- [x] 3.3 Implement the string-shape preservation rule from D2 — do not flip `/Filter` from string to array on write-back when input was string-form

## 4. Tests / verification fixtures

- [x] 4.1 Add `examples/poc-filter-chain-roundtrip.php` exercising the two-filter scenario from spec REQ-1 / REQ-2 (`[/ASCIIHexDecode /FlateDecode]`), using a hand-crafted ASCII-hex envelope around a `gzcompress`'d payload — asserts the dispatcher hits the chain path even though `ASCIIHexDecode` itself is a stub `case` in this change
- [x] 4.2 Add a defensive case in the dispatcher that returns the input unchanged when the chain is empty (REQ-1 scenario "Empty array `/Filter`")
- [x] 4.3 Add an unknown-filter assertion in `examples/poc-filter-chain-roundtrip.php` per REQ-5 (`p_error` logged, `_stream` + `Length` unchanged)
- [x] 4.4 Verify `php examples/poc-replace-text.php` still exits 0 (string-form FlateDecode path unchanged, REQ-4 "Existing PoC verify gate stays green")

## 5. Upstream-PR draft

- [x] 5.1 Update `docs/upstream-prs/05-filter-chaining/proposal.md` with the dispatcher contract that landed (mirror what's in this change's `proposal.md`)
- [x] 5.2 Update `docs/upstream-prs/05-filter-chaining/design.md` with the D1–D6 decision log
- [x] 5.3 Update `docs/upstream-prs/05-filter-chaining/tasks.md` with the implementation note about upstream-PR #01–`#04` plug-in points (one `case` arm per filter)
- [x] 5.4 Carry forward the `Posted at: <pending>` placeholder in `issue.md` — do NOT post upstream yet (per the fork-and-give-back ordering)

## 6. Quality gate

- [x] 6.1 Confirm no new composer dependencies were added (`composer.json` diff empty other than possible doc-only edits)
- [x] 6.2 Confirm PHP 7.4 compatibility — no typed properties, no `readonly`, no `enum`, no first-class callable syntax
- [x] 6.3 Confirm snake_case discipline on all new method names
- [x] 6.4 Confirm no `PDFObject` / `PDFDoc` / `PDFValue*` class-boundary refactors crept in

## 7. Commit + PR

- [x] 7.1 Commit on `feat/filter-chain-dispatch` with a single message summarising the dispatcher contract
- [x] 7.2 Open PR `feat/filter-chain-dispatch → work/text-replacement` on `ConductionNL/sapp`, citing spec REQ-1 through REQ-5
- [x] 7.3 Add a note in the PR description that the four follow-up filter PRs (`#01`–`#04`) will attach via new `case` arms in `apply_filter_chain_decode` / `apply_filter_chain_encode`
