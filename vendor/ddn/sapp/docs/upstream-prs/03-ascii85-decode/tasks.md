# Tasks — `/ASCII85Decode`

## 1. Implementation

- [ ] 1.1 Add `protected static function ASCII85Decode($_stream)` to `src/PDFObject.php` per the spec REQ-01 algorithm.
- [ ] 1.2 Add `case '/ASCII85Decode'` to `get_stream()`'s switch.
- [ ] 1.3 Add `case '/ASCII85Decode'` to `set_stream()`'s switch with the encoder per the design D5 algorithm.

## 2. Fixtures + verification

- [ ] 2.1 Fixture: a PDF with one `/Filter /ASCII85Decode` content stream. Contents include a `z` shorthand to exercise that path.
- [ ] 2.2 Verification script: decode + assert; round-trip via `set_stream` + `get_stream`; exercise `z` shorthand, short final group, whitespace tolerance, invalid char.

## 3. Issue + PR

- [ ] 3.1 Post the issue body from `issue.md`. Record URL in frontmatter.
- [ ] 3.2 Branch `feat/ascii85-decode` off upstream `main`. Independent of #01 and #02.
- [ ] 3.3 Open PR referencing the issue.
- [ ] 3.4 Squash-merge into `work/text-replacement`.

## 4. Quality

- [ ] 4.1 No regression in FlateDecode / ASCIIHexDecode / RunLengthDecode paths.
- [ ] 4.2 No new dependencies.
- [ ] 4.3 REQ-01 through REQ-04 each have a passing verification step.


---

> **Implementation note**: canonical task list lives in `openspec/changes/feat-ascii85-decode/tasks.md` (kept up to date with the spec-violation `return false` paths, the new boundary tests, and the dispatcher's bare `'ASCII85Decode'` case-label note).
