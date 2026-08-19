# Tasks — `/RunLengthDecode`

## 1. Implementation

- [ ] 1.1 Add `protected static function RunLengthDecode($_stream)` to `src/PDFObject.php` per the spec REQ-01 algorithm. Use a simple while-loop with cursor advancement.
- [ ] 1.2 Add `case '/RunLengthDecode'` to `get_stream()`'s switch.
- [ ] 1.3 Add `case '/RunLengthDecode'` to `set_stream()`'s switch — emit literal-run blocks of up to 128 bytes each, terminate with 0x80.

## 2. Fixtures + verification

- [ ] 2.1 Synthesise a fixture PDF with a `/Filter /RunLengthDecode` stream: a mix of literal and repeat runs in the body of a single object. Save under `examples/` matching the maintainer's preferred location.
- [ ] 2.2 Verification script: load fixture, assert decoded bytes match expectation; round-trip via `set_stream` + `get_stream`; trigger the truncated-input path.

## 3. Issue + PR

- [ ] 3.1 Post the issue body from `issue.md` to `dealfonso/sapp`. Record URL in frontmatter.
- [ ] 3.2 Branch `feat/runlength-decode` off upstream `main` (NOT `feat/asciihex-decode` — the two features are independent).
- [ ] 3.3 Open the PR referencing the issue.
- [ ] 3.4 Squash-merge into `work/text-replacement` for downstream integration.

## 4. Quality

- [ ] 4.1 No regression in FlateDecode or ASCIIHexDecode paths.
- [ ] 4.2 No new dependencies.
- [ ] 4.3 Spec REQ-01 through REQ-04 each have a passing verification step.


---

> **Implementation note**: canonical task list lives in `openspec/changes/feat-runlength-decode/tasks.md` (kept up to date with the chain-failure-propagation test, the boundary tests, and the dispatcher's bare `'RunLengthDecode'` case-label note).
