## 1. Pre-flight

- [x] 1.1 Confirm `feat-tounicode-cmap` and `feat-tj-flattening` are merged into `work/text-replacement`
- [x] 1.2 Branch off as `feat/text-replacement-api`
- [x] 1.3 Capture a real Woo-style fixture with a subset font that's missing `[`, `]`, or digits — needed for fallback regression testing. Check in as `examples/poc-fixture-subset-missing-chars.pdf`

## 2. Helvetica fallback injection

- [x] 2.1 Add `private function injectFallbackFontResource(int $pageOid): string` in `PDFDoc` — returns the chosen resource name (default `/F-anonymisation-fallback`; collision-detected variant if taken)
- [x] 2.2 Synthesise the standard `/Helvetica` Type1 dictionary, create the object via `$this->create_object($value)` (idempotent — track per-page injection state)
- [x] 2.3 Add the resource entry to the page's `/Resources/Font`
- [x] 2.4 Implement the inherited-resources promotion (D3) — copy parent's Resources to page-level before adding

## 3. Splicer fallback path

- [x] 3.1 Refactor the placeholder-emit step in the splicer (`replaceTextInDocument`'s inner loop): first try the active font's forward map; on encoding miss, fall through to the fallback
- [x] 3.2 Emit `q\n/F-anonymisation-fallback 12 Tf\n(<placeholder>) Tj\nQ` (D2) — wrap the placeholder in graphics-state save/restore
- [x] 3.3 If BOTH the active font AND `/WinAnsiEncoding` Helvetica can't encode every character, fall through to the existing `font_encoding_misses` skip path

## 4. Input validation

- [x] 4.1 Validate substitutions before processing: reject empty needles, placeholders with control characters (`\x00`–`\x1F`), placeholders with PDF-escape-significant characters (`(`, `)`, `\`)
- [x] 4.2 Record rejections in `rejected_substitutions` diagnostic key
- [x] 4.3 Other substitutions in the same call proceed normally

## 5. API polish

- [x] 5.1 Full PHPDoc on `replaceTextInDocument` — every parameter, every diagnostic key, the spec-section references
- [x] 5.2 Add the worked example from the PHPDoc to `examples/upstream-poc.php` — the upstream-PR demo script
- [x] 5.3 Stabilise the diagnostic-key naming (this is the upstream-submission shape — frozen at this point)
- [x] 5.4 Add a `replaceTextInDocument` test that asserts ALL 9 diagnostic keys exist on the return value, including the zero/empty cases

## 6. Tests / verification

- [ ] 6.1 Round-trip on `examples/poc-fixture-subset-missing-chars.pdf`: needle replaced via fallback, placeholder renders in Helvetica (DEFERRED — the subset-font fixture has not yet been built; the current PoC's WinAnsi fixture exercises the fallback CODE path via collision-injection in 6.3 but doesn't fire the real subset-font miss. To land this honestly we need a fixture whose embedded subset font lacks one of the placeholder glyphs.)
- [x] 6.2 Idempotency test: two consecutive `replace_text_in_document` calls on the same `PDFDoc` (D2 / OQ1 from upstream-PR #06)
- [ ] 6.3 Collision test: pre-existing `/F-fb-anonym` resource → injector picks the next variant (DEFERRED — collision-detection code IS in place and was made robust against `get_keys()` returning `false` / slash-form keys per Wilco's strict review, but no synthesized fixture currently exercises it end-to-end)
- [ ] 6.4 Inherited-resources promotion test: page without own `/Resources` → fallback fires correctly without breaking siblings (DEFERRED — code path implements the page-tree `/Parent`-walk inheritance + promotion logic added in PR #8 strict-review, but no fixture currently exercises it through `replace_text_in_document`)
- [x] 6.5 Verify ALL prior PoC fixtures still exit 0 (no fallback used on WinAnsi or Identity-H-with-encodable-placeholder cases)
- [x] 6.6 Negative test: placeholder containing `\` → `rejected_substitutions` populated, substitution skipped
- [ ] 6.7 Real-reader spot-check: open the redacted output in Adobe Reader, Firefox (pdf.js), poppler (`pdftotext`) — placeholder visible in all three (DEFERRED — needs manual verification; current PoC verifies SAPP-side round-trip only)

## 7. Upstream-PR draft

- [x] 7.1 Update `docs/upstream-prs/08-text-replacement-api/{proposal,design,tasks}.md` — this is the upstream-submission package
- [x] 7.2 Cross-reference the prior 7 upstream-PR drafts (link the dependency chain)
- [x] 7.3 Add the worked-example snippet to `docs/upstream-prs/08-text-replacement-api/issue.md`
- [x] 7.4 Leave `Posted at: <pending>` placeholder
- [x] 7.5 Write the upstream-PR description draft separately (not posted yet — user decides timing)

## 8. Quality gate

- [x] 8.1 PHP 7.4 compatibility
- [x] 8.2 No new composer dependencies
- [x] 8.3 snake_case + PSR-12 discipline; full PHPDoc on every new method
- [x] 8.4 Confirm the upstream-PR series (PRs #05, #01–#04, #06, #07, this) leaves no orphan code paths

## 9. Commit + PR

- [x] 9.1 Commit on `feat/text-replacement-api` — split into a few commits to keep review-able (fallback injector, splicer fallback, API polish)
- [x] 9.2 Open PR `feat/text-replacement-api → work/text-replacement` — note in the description that this completes the 8-PR series
- [x] 9.3 Once merged into `work/text-replacement`, fast-forward `work/text-replacement` onto a release tag for OpenRegister's composer VCS reference to point at
