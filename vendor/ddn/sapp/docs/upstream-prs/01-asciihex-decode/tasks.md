# Tasks — `/ASCIIHexDecode`

## 1. PoC plumbing (run BEFORE this feature ships)

- [ ] 1.1 PoC for the bigger text-replacement pipeline lands on `work/text-replacement`. This feature's value is unlocked by that pipeline; the PoC milestone (see OpenRegister `pdf-anonymisation` change tasks §1) drives the urgency.

## 2. Implementation

- [ ] 2.1 Add `protected static function ASCIIHexDecode($_stream)` to `src/PDFObject.php` per the spec REQ-01 algorithm.
- [ ] 2.2 Add `case '/ASCIIHexDecode'` to `get_stream()`'s switch — calls the new helper.
- [ ] 2.3 Add `case '/ASCIIHexDecode'` to `set_stream()`'s switch — encodes via `bin2hex($stream) . '>'` and updates `_value['Length']` per the existing pattern.

## 3. Fixtures + verification

- [ ] 3.1 Synthesise a small fixture: one PDF object with `/Filter /ASCIIHexDecode` carrying a known content stream (e.g. `Hello World` encoded as hex). Save to `examples/asciihex-fixture.pdf` (or follow the maintainer's preferred location — see issue ask).
- [ ] 3.2 Verification script (`asciihex-verify.php` in repo root, matching the existing `pdfdeflate.php` / `pdfrebuild.php` idiom): load the fixture, decode the stream via `get_stream(false)`, assert the result; round-trip via `set_stream` + `get_stream`, assert byte-equal; trigger the odd-length, whitespace, and invalid-char paths.
- [ ] 3.3 If the maintainer has a preferred `tests/` location, mirror that; if no `tests/` exists (it doesn't today), keep the verify script at repo root.

## 4. Issue + PR sequence

- [ ] 4.1 Post the issue body from `issue.md` to `dealfonso/sapp`. Record the URL in the issue draft's frontmatter `Posted at:` field.
- [ ] 4.2 Branch `feat/asciihex-decode` off upstream `main`. Implement.
- [ ] 4.3 Open the PR referencing the issue. Title matches the issue's suggested title.
- [ ] 4.4 Squash-merge the PR branch into `work/text-replacement` for downstream integration testing — even before upstream merges.
- [ ] 4.5 When upstream merges the PR, the squashed commit on `work/text-replacement` becomes redundant; rebase/drop on the next integration-branch maintenance.

## 5. Quality

- [ ] 5.1 No regressions in the existing FlateDecode path — verify against `examples/testdoc.pdf` (the existing example PDF, which is FlateDecoded).
- [ ] 5.2 No new dependencies — pure PHP only, no Composer additions.
- [ ] 5.3 Spec-validity: every Requirement in `spec.md` has a passing verification step.


---

---

## Implementation note

See `openspec/changes/feat-asciihex-decode/design.md` (the canonical artefact) for the shipped-implementation note. This file keeps the original proposal/design/tasks content for the eventual upstream submission to dealfonso/sapp; implementation specifics are tracked in the OpenSpec change to avoid duplicate-source drift.
