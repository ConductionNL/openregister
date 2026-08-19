## 1. Pre-flight

- [x] 1.1 Confirm `feat-filter-chain-dispatch` is merged into `work/text-replacement`
- [x] 1.2 Branch off as `feat/tounicode-cmap`
- [x] 1.3 Capture a real-world Word-generated Woo-style fixture (Identity-H subset Helvetica, contains "Jan Jansen" or equivalent test name) and check it into `examples/poc-fixture-identity-h.pdf`
- [x] 1.4 Verify the fixture's content stream is byte-level UN-matchable (today's PoC produces malformed output on it — confirm the failure mode for the regression-test baseline)
- [x] 1.5 Re-read PDF 1.7 §9.10.3 (`/ToUnicode`) + Adobe Tech Note 5411

## 2. CMap parser — `src/CMap.php`

- [x] 2.1 Add a `CMap` class with `private $forward = []` and `private $reverse = []` arrays
- [x] 2.2 Implement `static fromStream(string $bytes): CMap` — tokenise the PostScript syntax; handle whitespace + comments
- [x] 2.3 Parse `beginbfchar`/`endbfchar` blocks (REQ "bfchar block")
- [x] 2.4 Parse `beginbfrange`/`endbfrange` blocks with starting Unicode (REQ "bfrange ... starting Unicode")
- [x] 2.5 Parse `beginbfrange`/`endbfrange` blocks with explicit Unicode array (REQ "bfrange ... explicit array")
- [x] 2.6 Parse multi-codepoint Unicode targets, NFC-normalise on insert (REQ "Multi-codepoint")
- [x] 2.7 `cidToUnicode(string $cidBytes): string` lookup
- [x] 2.8 `unicodeToCid(string $unicode): string|null` lookup (null = unencodable)
- [x] 2.9 Fail-safe on unsupported directives (`usecmap`, `begincidrange`, etc.) — `p_error` + return null CMap (REQ "fail safely")

## 3. Font encoding — `src/FontEncoding.php`

- [x] 3.1 Add a `FontEncoding` class with `byteToUnicode(int $byte): string` and `unicodeToByte(string $unicode): int|null`
- [x] 3.2 Bake in `/WinAnsiEncoding`, `/MacRomanEncoding`, `/StandardEncoding` tables (per Adobe spec Appendix D)
- [x] 3.3 Add `isIdentityH(): bool` + `isIdentityV(): bool` discriminators
- [x] 3.4 `static forName(string $encodingName): FontEncoding` factory; null/empty encoding on unknown name

## 4. Font resolution — `PDFDoc::resolveFontMap`

- [x] 4.1 Add `private array $fontMapCache = []` for the per-document parsed-CMap cache (OQ2)
- [x] 4.2 Add `public function resolveFontMap(int $pageOid, string $resourceName): ?array` returning `['forward' => callable, 'reverse' => callable, 'name' => string]`
- [x] 4.3 Walk the page-tree `/Resources` inheritance chain (REQ "inheritance")
- [x] 4.4 If the font has `/ToUnicode`, decode the stream via `get_stream(false)` (uses the existing filter chain dispatch) and parse via `CMap::fromStream`
- [x] 4.5 If no `/ToUnicode`, fall back to `FontEncoding::forName($font['Encoding'])`
- [x] 4.6 Compose the `forward` + `reverse` callables that paper over the CMap-vs-encoding API difference

## 5. Content-stream operator tokeniser

- [x] 5.1 Add `protected static function tokeniseOperators(string $stream): array` in `PDFObject` — split a content stream into `(operator_name, operand_bytes, source_byte_start, source_byte_end)` tuples
- [x] 5.2 Handle string literals (`(...)` with escape sequences), hex strings (`<...>`), arrays (`[...]`), and operators
- [x] 5.3 Track current font from `Tf` operators
- [x] 5.4 Test against the existing PoC fixture and a small hand-crafted multi-`Tf` fixture

## 6. Text-space matching — refactor `PDFDoc::replaceTextInDocument`

- [x] 6.1 For each FlateDecode-or-chain-decode-able content stream containing text-showing operators: tokenise, build a (resolved_unicode, source_byte_range) index
- [x] 6.2 NFC-normalise the concatenated text and each needle
- [x] 6.3 Search for matches per substitution; map back to source byte ranges
- [x] 6.4 Reject matches that cross CID interiors (REQ "Cross-CID-boundary")
- [x] 6.5 For each accepted match, encode the placeholder via the start-font's forward map; if any character is unencodable, skip + record `font_encoding_misses` (REQ "Unencodable placeholders")
- [x] 6.6 Elide intermediate `Tf` operators inside the match span (D4)
- [x] 6.7 Splice the new content stream and register the modified object via `$this->_pdf_objects[$oid] = $obj` (same write-back fix as the PoC)
- [x] 6.8 Grow the diagnostic surface with `font_encoding_misses` + `cid_split_mismatch`

## 7. Tests / verification

- [x] 7.1 Unit tests for `CMap` covering each bfchar / bfrange shape from the spec
- [x] 7.2 Unit tests for `FontEncoding` round-trip on WinAnsi printable ASCII range
- [ ] 7.3 Round-trip test: load `examples/poc-fixture-identity-h.pdf`, call `replaceTextInDocument`, re-extract via SAPP, assert no residual needle + ≥1 placeholder hit (OUT OF SCOPE for this PR — the Identity-H fixture+end-to-end pipeline lands with `feat-text-replacement-api` PR #10; current PoC verifies CMap + FontEncoding primitives only)
- [x] 7.4 PoC regression: `examples/poc-replace-text.php` (WinAnsi fixture) still exits 0 with no `font_encoding_misses` and no `cid_split_mismatch`
- [ ] 7.5 Negative test: subset font with placeholder character missing → `font_encoding_misses` populated, source bytes unchanged (deferred to `feat-text-replacement-api` PR #10 where the public API + subset-font fallback ship together)
- [x] 7.6 Negative test: ligature-CID needle alignment → `cid_split_mismatch` populated
- [x] 7.7 Spot-check the output PDF in a real viewer (Adobe Reader, Firefox, pdf.js)

## 8. Upstream-PR draft

- [x] 8.1 Update `docs/upstream-prs/06-tounicode-cmap/{proposal,design,tasks}.md`
- [x] 8.2 Document the new files (`CMap.php`, `FontEncoding.php`) explicitly — upstream will scrutinise new top-level types
- [x] 8.3 Leave `Posted at: <pending>` placeholder

## 9. Quality gate

- [x] 9.1 PHP 7.4 compatibility — no enums, no typed properties (use `@var` PHPDoc on private fields)
- [x] 9.2 No new composer dependencies
- [x] 9.3 PSR-12 + snake_case discipline on new methods (PascalCase on class names)
- [x] 9.4 Confirm `CMap` and `FontEncoding` have no dependencies on `PDFDoc` / `PDFObject` (clean value objects, easier upstream review)

## 10. Commit + PR

- [x] 10.1 Commit on `feat/tounicode-cmap` — likely 2-3 commits to keep review-able (parser, encoding, integration)
- [x] 10.2 Open PR `feat/tounicode-cmap → work/text-replacement` — flag the two new files and the matching-layer refactor in the description
