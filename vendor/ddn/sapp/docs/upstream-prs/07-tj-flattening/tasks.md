# Tasks — TJ flattening

## 1. Tokeniser (REQ-01, REQ-03, REQ-04, REQ-06)

- [ ] 1.1 Create `src/helpers/TextContentStreamFlattener.php` with `class TextContentStreamFlattener` under namespace `ddn\sapp\helpers`.
- [ ] 1.2 Implement a content-stream tokeniser: walk byte-by-byte, recognise operands (literal strings, hex strings, numbers, arrays, names) and operators (alpha tokens).
- [ ] 1.3 Implement the literal-string sub-parser handling balanced parens, escapes, octal codes, line continuations per PDF 1.7 §7.3.4.2.
- [ ] 1.4 Implement the hex-string sub-parser handling whitespace, odd-length-pad.
- [ ] 1.5 Implement the array operand parser.

## 2. Flatten logic (REQ-01)

- [ ] 2.1 Implement `public static function flatten(string $content_stream): string`. Tokenise; for each `TJ` operator, parse the array operand on the operand stack, concatenate strings, drop numbers, emit `Tj`.
- [ ] 2.2 First-element-determines-form logic for mixed-form arrays (REQ-04).
- [ ] 2.3 Empty-array handling (REQ-05).

## 3. Pass-through paths (REQ-02)

- [ ] 3.1 All non-TJ operators pass through with their operands byte-equal to the input.
- [ ] 3.2 Text-object boundaries (`BT`/`ET`), graphics-state changes (`q`/`Q`), text-state-setting operators (`Tf`/`Tm`/...) — verify each passes through.

## 4. Fixtures + verification

- [ ] 4.1 Fixture: a Word-generated PDF page's content stream with multiple TJ operators carrying kerned body text. Source from one of the PoC fixtures.
- [ ] 4.2 Fixture: content stream with all five literal-string escape forms.
- [ ] 4.3 Fixture: content stream with hex-string TJ arrays (Identity-H font).
- [ ] 4.4 Fixture: content stream with mixed-form TJ array.
- [ ] 4.5 Fixture: empty-TJ-array edge case.
- [ ] 4.6 Verification script: for each fixture, flatten + assert output structure; round-trip via re-tokenisation (the flattened output is itself a valid content stream); profile REQ-07 on a 100 KB stream.

## 5. Issue + PR

- [ ] 5.1 Post the issue body from `issue.md`. Frontmatter `Posted at:`.
- [ ] 5.2 Branch `feat/tj-flattening` off upstream `main`. Independent of #06 (CMap parser).
- [ ] 5.3 Open the PR. Note the optional refinement (`treat_large_kerning_as_space`) deferred.
- [ ] 5.4 Squash-merge into `work/text-replacement`.

## 6. Quality

- [ ] 6.1 No regression in existing SAPP API (the helper is additive).
- [ ] 6.2 No new dependencies — pure PHP.
- [ ] 6.3 REQ-01 through REQ-07 each have a passing verification step.
- [ ] 6.4 REQ-07 performance check (100 KB content stream).

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-tj-flattening/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/tj-flattening/spec.md`). Key as-shipped facts: D2 split shape preserved (leading TJ array + placeholder Tj + trailing TJ array per match boundary); multi-match in same TJ supported via iterative re-resolve loop; numeric tokenizer rejects scientific notation per PDF 1.7 §7.3.3; odd-length hex padded with `0` per §7.3.4.3; comments + spec-defined whitespace honoured inside TJ arrays.
