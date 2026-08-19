# Tasks — Filter chaining

## 1. Refactor `get_stream`

- [ ] 1.1 Add `protected function normaliseFilterNames($filter): ?array` — handles both `PDFValueType` (single name → 1-element array) and `PDFValueList` (array form → list).
- [ ] 1.2 Add `protected function normaliseDecodeParms($params, int $expected_count): array` — parallel-array normaliser, tolerates `null` entries and short arrays.
- [ ] 1.3 Add `protected function decodeOne(string $filter_name, string $data, array $params)` — per-filter dispatch. Inner switch on filter name.
- [ ] 1.4 Refactor `get_stream(false)` body to call the three helpers in sequence. Preserve the early-return for `$raw = true` and the no-filter path.

## 2. Refactor `set_stream`

- [ ] 2.1 Add `protected function encodeOne(string $filter_name, string $data, array $params)` — per-filter encoder dispatch.
- [ ] 2.2 Refactor `set_stream($stream, false)` to apply the filter list in REVERSE order via `encodeOne`. Update `_value['Length']` after the chain.

## 3. Byte-equivalence proof (REQ-01)

- [ ] 3.1 Pre-refactor: decode every object in `examples/testdoc.pdf` via `get_stream(false)`. Save the outputs.
- [ ] 3.2 Post-refactor: re-decode. Diff against the pre-refactor outputs. MUST be byte-identical.

## 4. Fixtures + verification (REQ-02 through REQ-07)

- [ ] 4.1 Fixture: PDF with `/Filter [/ASCII85Decode /FlateDecode]`. Verify chain decoding.
- [ ] 4.2 Fixture: PDF with `/Filter [/ASCIIHexDecode /ASCII85Decode /FlateDecode]` (three-filter chain).
- [ ] 4.3 Fixture: PDF with `/Filter [/FlateDecode]` and `/DecodeParms [<</Predictor 12 /Columns 100>>]`. Verify parallel-array params.
- [ ] 4.4 Fixture: PDF with `/Filter [/FlateDecode]` and no `/DecodeParms`. Verify default-params path.
- [ ] 4.5 Fixture: PDF with `/Filter [/ASCII85Decode /UnknownFilter /FlateDecode]`. Verify pipeline abort at unknown filter.
- [ ] 4.6 Fixture: PDF object with `/Filter []`. Verify pass-through.
- [ ] 4.7 Fixture: PDF object with `/Filter /DCTDecode` (text-replacement code never encounters this in practice, but the spec REQ-07 says we route through `p_error`).
- [ ] 4.8 Verification script: round-trip each fixture via `set_stream` + `get_stream`. Byte-equal output.

## 5. Issue + PR

- [ ] 5.1 Post the issue body from `issue.md`. Record URL in frontmatter.
- [ ] 5.2 Branch `feat/filter-chaining` off upstream `main`. Note: this PR is best opened AFTER #01–#04 have at least appeared (so the decode functions exist that the chain dispatches into).
- [ ] 5.3 Open the PR referencing the issue.
- [ ] 5.4 Squash-merge into `work/text-replacement`.

## 6. Quality

- [ ] 6.1 REQ-01 byte-equivalence check (testdoc.pdf round-trip).
- [ ] 6.2 No regression in any single-name filter case.
- [ ] 6.3 No new dependencies.
- [ ] 6.4 REQ-02 through REQ-07 each have a passing verification step.


---

---

## Implementation note

See `openspec/changes/feat-filter-chain-dispatch/design.md` (the canonical artefact) for the shipped-implementation note, decisions D1-D6, and the method-name listing. This file intentionally keeps the original proposal/design/tasks content for upstream submission to dealfonso/sapp; the implementation specifics are tracked in the OpenSpec change to avoid duplicate-source drift across four files.
