# Tasks — `replaceTextInDocument()` API

## 1. BaseFontFallback helper

- [ ] 1.1 Create `src/fonts/BaseFontFallback.php` with `class BaseFontFallback` under namespace `ddn\sapp\fonts`.
- [ ] 1.2 Implement `public static function register(PDFDoc $doc, string $base_font_name = '/Helvetica'): string` — adds a Type1 Helvetica (or other base font) resource to every page's `/Resources/Font` dictionary. Returns the resource name used (e.g. `/F-Replacement`).
- [ ] 1.3 Make it idempotent: if the resource already exists on a page, the method MUST NOT duplicate.
- [ ] 1.4 Validate that `$base_font_name` is one of the 14 standard fonts (Helvetica, Helvetica-Bold, Helvetica-Oblique, Helvetica-BoldOblique, Times-Roman, Times-Bold, Times-Italic, Times-BoldItalic, Courier, Courier-Bold, Courier-Oblique, Courier-BoldOblique, Symbol, ZapfDingbats); throw on unknown.

## 2. TextReplacementException

- [ ] 2.1 Create `src/Exceptions/TextReplacementException.php` (introduce the `Exceptions` namespace under `ddn\sapp`).
- [ ] 2.2 Reason constants: `REASON_ENCRYPTED_PDF`, `REASON_FONT_UNRESOLVABLE`, `REASON_INTERNAL_ERROR`. Constructor accepts the reason + an optional underlying exception.

## 3. The flagship method (REQ-01, REQ-02, REQ-03)

- [ ] 3.1 Add `public function replaceTextInDocument(array $substitutions, array $options = []): array` to `src/PDFDoc.php`.
- [ ] 3.2 Validate inputs: non-empty needles; encrypted-PDF early-return via REQ-05; option dictionary normalisation (apply defaults).
- [ ] 3.3 Resolve all fonts via `FontEncodingResolver::resolveAll($this)` — PR #06.
- [ ] 3.4 Register the replacement font via `BaseFontFallback::register($this, $options['replacement_font'])` — task §1.
- [ ] 3.5 For each content-stream object, run the per-stream replacement (task §4).
- [ ] 3.6 Collect diagnostics; return per the REQ-01 shape.

## 4. Per-stream replacement (REQ-02, REQ-03, REQ-04, REQ-08)

- [ ] 4.1 `get_stream(false)` — uses PR #01–#05 dispatch.
- [ ] 4.2 `TextContentStreamFlattener::flatten($decoded)` — PR #07.
- [ ] 4.3 Walk operators in the flattened stream. Maintain font-state stack (current Tf font + size) across q/Q, BT/ET boundaries.
- [ ] 4.4 For each Tj operator: build per-font byte-encoded substitution map (from `FontResolution::unicodeToBytes`); search the Tj string bytes for matches; rewrite Tj with font-switch insertions per design D7.
- [ ] 4.5 `collapse_adjacent_duplicates` post-pass — apply regex on each modified Tj string.
- [ ] 4.6 `set_stream($modified, false)` — inverse pipeline.

## 5. Diagnostics shape (REQ-06)

- [ ] 5.1 Track replacements per substitution + per font during the walk.
- [ ] 5.2 Track skipped fonts + their reasons.
- [ ] 5.3 Compute total bytes changed (rough — sum of `strlen(modified) - strlen(original)` per stream).
- [ ] 5.4 Return the structured diagnostic array.

## 6. Out-of-scope handling (REQ-11)

- [ ] 6.1 Filter the iteration so only content-stream objects (`/Type /Page → /Contents`) and Type 3 font glyph procedures get walked. Image streams (DCTDecode etc.) are not entered.
- [ ] 6.2 Form XObjects, annotation contents, outline titles are NOT modified. Document this in the method docblock.

## 7. Fixtures + verification

- [ ] 7.1 The PoC's primary fixture (WinAnsi-encoded body text containing "Jan Jansen") — verify REQ-02.
- [ ] 7.2 Identity-H subset font fixture — verify REQ-03.
- [ ] 7.3 Multi-page fixture — verify REQ-04 (Helvetica registered on every page, once).
- [ ] 7.4 Encrypted PDF fixture — verify REQ-05 (throws cleanly).
- [ ] 7.5 Substitution-not-found fixture — verify REQ-06 (unmatched diagnostic).
- [ ] 7.6 Composite-font-no-ToUnicode fixture — verify REQ-07 (skip vs. throw paths).
- [ ] 7.7 Adjacent-duplicate fixture — verify REQ-08.
- [ ] 7.8 Table-bearing fixture — verify REQ-09 (layout intact).
- [ ] 7.9 Image-bearing fixture — verify REQ-11 (JPEG untouched).
- [ ] 7.10 PDF-validity check on every output (REQ-10) — run `pdfinfo` + re-parse with `PDFDoc::from_string`.

## 8. Issue + PR

- [ ] 8.1 Post the issue body from `issue.md`. Frontmatter `Posted at:`.
- [ ] 8.2 Branch `feat/text-replacement-api` off upstream `main`. Note: depends on #01–#07 all being merged or at least open as PRs.
- [ ] 8.3 Open the PR; include a link to the OpenRegister `pdf-anonymisation` change's PoC as the working downstream consumer.
- [ ] 8.4 Squash-merge into `work/text-replacement`.

## 9. Quality

- [ ] 9.1 No regression in existing SAPP API.
- [ ] 9.2 No new dependencies — pure PHP.
- [ ] 9.3 REQ-01 through REQ-11 each have a passing verification step.
- [ ] 9.4 OpenRegister-side PoC test passes against this branch (the integration evidence the issue offers).

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-text-replacement-api/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/text-replacement/spec.md`). Key as-shipped facts: public method is `replace_text_in_document` (snake_case per upstream convention); Helvetica subset-font fallback q/Q-wraps placeholders the active font cannot encode (resource name `/F-fb-anonym`, collision-handled); 12-key diagnostic surface frozen and locked via `@phpstan-type ReplaceTextStats`; input validation rejects empty needles + placeholders containing control chars or `()\`.
