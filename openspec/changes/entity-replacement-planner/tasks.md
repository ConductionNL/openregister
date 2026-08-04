## 1. Planner core (pure, no I/O)

- [x] 1.1 Add `lib/Service/File/Anonymisation/ReplacementRange.php` — value object carrying `start`, `end` (exclusive, codepoint offsets), `needle`, `placeholder`, `entityType`, and an `isResidue` flag distinguishing a directly-matched range from residue coverage (task 3).
- [x] 1.2 Add `lib/Service/File/Anonymisation/ReplacementPlan.php` — value object carrying the accepted ranges (ascending by `start`), the unmatched needles, and the `partial` needles. Expose `getRanges()`, `getUnmatchedNeedles()`, `getPartialNeedles()`. No mutation after construction.
- [x] 1.3 Add `lib/Service/File/Anonymisation/ReplacementPlanner.php` with `plan(string $text, array $substitutions, array $entityTypes): ReplacementPlan`. Enumerate every occurrence of every needle against the **unmodified** `$text` using a `mb_strpos` scan loop; never mutate `$text`. Needles MUST be cast to string on every read — PHP coerces purely-numeric array keys to `int` (see the caveat at `lib/Service/File/DocumentProcessingHandler.php:485-488`).
- [x] 1.4 Implement the candidate total order: start ascending, span descending, type rank ascending (structured before free-text per the table in `design.md` Decision 4), needle bytewise ascending. Assign each candidate its index in this order; the index is what breaks DP ties in 1.5.
- [x] 1.5 Implement maximum-coverage selection as weighted interval scheduling: sort candidates by end offset, `dp[i] = max(dp[i-1], span_i + dp[p(i)])` where `p(i)` is the last candidate ending at or before `start_i` (binary search). On equal totals, choose the branch containing the lower-indexed candidate from 1.4 so the result is deterministic.
- [x] 1.6 Record every needle with zero accepted ranges as unmatched in the plan.
- [x] 1.7 Unit tests: containment (`robert@rjzondervan.nl` beats `rjzondervan`), the two-short-beats-one-long case, no accepted range overlaps another, ranges are ascending by start, determinism across shuffled `$substitutions` insertion order, numeric-needle handling, empty map and empty text.

## 2. Boundary policy and case folding

- [x] 2.1 Add `lib/Service/File/Anonymisation/BoundaryPolicy.php` resolving one of THREE policies per entity type from the canonical constants (`lib/Service/TextExtraction/EntityRecognitionHandler.php:64-73`). Word-bounded: `PERSON`, `ORGANIZATION`, `LOCATION`, `ADDRESS`, and any unenumerated type. Delimited-token: `DATE`, `SSN`, `PHONE`, `IP_ADDRESS`. Literal: `EMAIL`, `IBAN` ONLY. Two notes that reverse the first draft: the unknown-type default is **bounded, not literal** (a boundary miss is reported, a literal false positive is silent), and the split is decided by **numeric embeddability**, not by "structured vs free-text".
- [x] 2.2 Implement the boundary test as "not adjacent to a word codepoint", where word means Unicode letter, combining mark, decimal digit or underscore. Use a `/u` pattern or explicit codepoint classification — a non-`/u` `\b` is byte-oriented and mis-fires on accented names.
- [x] 2.3 Apply the policy during candidate enumeration (1.3): a word-bounded needle only yields a candidate at positions satisfying the boundary test on both sides.
- [x] 2.3b Implement the delimited-token rule for `DATE`, `SSN`, `PHONE` and `IP_ADDRESS`: reject a match that is a proper substring of a longer numeric token, where a numeric token is a digit run optionally joined by single `-`/`/`/`.`/`:` separators EACH IMMEDIATELY FOLLOWED BY A DIGIT. A separator not followed by a digit does not extend the token, so `1980` matches in `in 1980.` but not in `2026-0012` or `03.08.2026`. Needles may be internally separated (`03-08-2026`, `192.168.1.1`, `06-12345678`) — the rule constrains only what surrounds the match.
- [x] 2.4 Implement case-insensitive matching by folding both haystack and needle with `mb_strtolower`. Keep a codepoint-offset mapping from the folded text back to the original so accepted ranges address the ORIGINAL text — required because case folding can change string length for some codepoints.
- [x] 2.5 Unit tests: `Jan` does not match inside `Januari`, `Bas` does not match inside `Bassin`, standalone `Jan` does match, an `IBAN` matches when flanked by word characters, a differing-case occurrence matches, an accented needle folds correctly, and a needle whose folded length differs from its original length still yields correct original-text offsets.
- [x] 2.5b Delimited-token tests: `2026` rejected in `Zaaknummer 2026-0012` and in `03.08.2026`, accepted before a sentence-final period; `20260803` and `03-08-2026` accepted as whole needles. **`192.168.1.1` rejected inside `192.168.1.10`** (the case literal matching gets actively wrong) and accepted standalone. `123456789` rejected inside `1234567890`, accepted standalone, and rejected-with-`unmatched`-report in `BSN123456789`. `IBAN` still matches inside `IBAN:NL91ABNA0417164300x`. Unknown-type test: `1234567` matches standalone but not inside `12345678`.

## 3. Residue coverage for rejected candidates

- [x] 3.1 After selection (1.5), compute for each rejected candidate the subranges not covered by any accepted range.
- [x] 3.2 Drop a residue consisting solely of whitespace and/or a single punctuation codepoint; cover any residue containing a letter or decimal digit. Emit covered residue as a `ReplacementRange` with `isResidue: true`, attributed to the rejected candidate's needle and placeholder.
- [x] 3.3 Report the rejected candidate's needle as `partial` in the plan (not unmatched) when at least one of its residue subranges was covered. A `partial` finding sets `complete: false` (decided 2026-08-03, conservative) even though the needle's text is fully absent from the output.
- [x] 3.4 Unit tests: `Jan de Vries` / `Vries-Bakker` in `Jan de Vries-Bakker` leaves neither `Vries` nor `Bakker` and reports `Vries-Bakker` as `partial`; whitespace-only residue emits no placeholder and preserves the whitespace; a residue containing a digit is always covered.

## 4. Single-pass application and the segment abstraction

- [x] 4.1 Add `lib/Service/File/Anonymisation/PlanApplier.php` with `applyToString(string $text, ReplacementPlan $plan): string` — walk accepted ranges ascending and BUILD the output by alternating original slices and placeholders. Never mutate a working copy.
- [x] 4.2 Add `lib/Service/File/Anonymisation/SegmentMap.php` — an ordered list of segment values plus each segment's codepoint offset in their concatenation, with `flatten(): string` and `scatter(ReplacementPlan): array` returning the new segment values.
- [x] 4.3 Implement `scatter()` per `design.md` Decision 7: the placeholder goes entirely into the segment holding the range's **start**; every subsequent overlapped segment has its covered portion removed; a segment left empty is retained as `''` and never removed.
- [x] 4.4 Unit tests: a range wholly inside one segment; a range spanning two segments; a range spanning three or more segments (middle segment fully consumed); adjacent ranges in the same segment; a range ending exactly on a segment boundary; segments containing multibyte text.
- [x] 4.5 Unit test that an emitted placeholder is never matched: a map containing both `"Jan Jansen"` → `[PERSOON: 1]` and an entity whose text is `"1"`, asserting the `1` inside the emitted placeholder survives while a standalone `1` is replaced per its boundary policy.

## 5. Route the office and text branches through the planner

- [x] 5.1 Plain text (`lib/Service/File/DocumentProcessingHandler.php:1204-1284`): replace the `str_ireplace` loop at `:1216` with plan + `applyToString`. Single segment, no `SegmentMap` needed.
- [x] 5.2 Sanitised office container (`:897-978`): replace the whole-XML `str_ireplace` at `:918` with a segment adapter over the sanitised XML's **text nodes**. This also removes the current risk of a needle matching inside XML markup rather than document content.
- [x] 5.3 docx (`:979-1203`): replace the recursive `$replaceInElements` closure (`:1027-1060`) with a two-phase walk — first collect PhpWord elements exposing `getText()`/`setText()` into a `SegmentMap` in document order (body, headers, footers, table cells, list items, nested elements), then `scatter()` the plan back via `setText()`. Preserve the existing traversal coverage exactly; the only change is collect-then-write instead of write-in-place.
- [x] 5.4 Verify the PhpWord segment order is true document order, since both DP determinism and residue attribution are defined on concatenation offsets.
- [ ] 5.5 Keep the `uksort` at `:489-495` as a defensive ordering for direct map consumers; add a comment stating the overlap guarantee now comes from the planner so a future reader does not delete the wrong one. **[N/A ON THIS BRANCH]** — targets `development`'s shape; see the commit for why.
- [x] 5.6 Fixture tests: an entity split across two `<w:r>` runs is redacted and the placeholder lands in the first run; entities in headers, footers, table cells and list items are all redacted; all text not covered by an accepted range is byte-identical to the input; document structure is unchanged.

## 6. Residual reporting on every format

- [x] 6.1 Add a verification pass that re-checks the produced text for every needle using the SAME case folding and boundary policy as matching (tasks 2.2 and 2.4).
- [x] 6.2 Populate `lastResidualEntities` (`:1393`) from the plan's unmatched needles plus the verification pass, for the plain-text, office-container and docx branches. Reuse the existing `{text, type, id}` record construction and placeholder-parsing regex (`:1379-1394`) rather than duplicating it — extract it to a private helper (ADR-011).
- [x] 6.3 Report findings in TWO kinds — `unmatched` (text may remain) and `partial` (split-matched, text gone). Both set `complete: false`; `residual_count` counts ONLY `unmatched`, preserving its meaning for existing consumers. Ensure nothing reads `complete: false` as "PII remains".
- [x] 6.4 Confirm the anonymise response surface reports `complete: false` with a matching `residual_count` for a non-PDF file with residuals, per the contract at `openspec/specs/pdf-anonymisation/spec.md:165-188`. Logs stay PII-free (ADR-005); residual text is carried only in the authenticated response.
- [x] 6.5 Tests: a docx with an unreachable occurrence reports an `unmatched` record and `complete: false`; a cleanly redacted plain-text file reports an empty list and `complete: true`; a split-matched document reports a `partial` finding with `complete: false` and `residual_count: 0`. The first two are currently impossible to fail because those paths never populate the list.

## 7. PDF path reconciliation

- [ ] 7.1 In `lib/Service/File/Pdf/PdfTextReplacer.php::validateOutput` (`:320`), replace the case-sensitive comparison (`:411-418`) with the shared case folding and boundary policy so a differing-case residual is reported. **[N/A ON THIS BRANCH]** — targets `development`'s shape; see the commit for why.
- [x] 7.2 Merge SAPP's `rejected_substitutions` stat (`:276`) into the residual list so a rejected substitution can never be reported as a clean redaction.
- [ ] 7.3 Keep `replaceInPdf`'s defensive `uksort` (`:177-184`) and SAPP delegation unchanged; update its comment to reference the planner as the upstream source of the ordering guarantee. **[N/A ON THIS BRANCH]** — targets `development`'s shape; see the commit for why.
- [ ] 7.4 Correct the stale comment at `lib/Service/File/Pdf/PdfTextReplacer.php:362` which states "Fail CLOSED in strict mode" for a path that only logs and falls back. Do NOT implement the behaviour it describes. Also correct `validateOutput`'s `@param bool $strict` docblock (`:301-305`), which still documents residual text as failing closed with `REASON_VALIDATION_FAILED`. Both make a reader believe blocking exists where it does not. **[N/A ON THIS BRANCH]** — targets `development`'s shape; see the commit for why.
- [x] 7.5 Assert non-blocking explicitly: a test that a PDF with residuals — including one detected only by the new case-folded comparison — still produces and persists its output and returns `success: true` with `complete: false`.
- [x] 7.6 Tests: a residual differing only in case is now reported; a SAPP-rejected substitution appears in the residual list; all existing PDF tests still pass unchanged.

## 8. Traceability, docs and quality gates

- [x] 8.1 Add `@spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md` annotations to every new class and to each modified method in `DocumentProcessingHandler` and `PdfTextReplacer` (ADR-003 / gate-16 spec-coverage).
- [x] 8.2 SPDX + `@license`/`@copyright` PHPDoc headers on every new file (gate-1, gate-28 — value must agree with `composer.json`).
- [x] 8.3 Document the boundary-policy table and the residual-report semantics in the anonymisation section of the project docs, including that non-PDF formats can now legitimately return `complete: false`.
- [x] 8.4 Review existing anonymisation fixtures under `tests/Unit/Service/File/` for intentional expected-output drift (over-redaction removed, residue now covered). Each diff needs review, not blind re-baselining.
- [x] 8.5 Run `composer check:strict` and `./scripts/run-hydra-gates.sh --scope-to-diff` and clear every finding. **Caveat:** `scripts/run-hydra-gates.sh` is not present in this repo and there is no container copy, so the gate suite could not be run — gates 1/2/16/21/28 were checked by hand instead. gate-28 FAILED and was fixed (see commit).
- [x] 8.6 Spot-check performance on a document with pathologically many detections (order 1000 candidates) to confirm the DP does not regress against the current per-needle full-document `str_ireplace` passes. **Result: the planner is 3-13x SLOWER, not cheaper** — corrected in design.md Risks with the measured table.

## 9. Cross-app and sequencing follow-ups

- [ ] 9.1 Flag to DocuDesk that docx/odt/txt anonymisations can now return a non-empty `residual_entities` list, and that this is a partial-success state rather than an error.
- [ ] 9.2 Decide whether DocuDesk's grondslagen-summary must distinguish `unmatched` from `partial` residuals (design.md Open Questions).
- [ ] 9.3 Coordinate with `openspec/changes/text-extraction-eml/`: whichever lands second, the EML redaction path consumes the planner rather than adding a fifth `str_ireplace` loop.
- [ ] 9.4 Port to `test/anonimiseren-bij-de-bron-or` as a **semantic port, not a cherry-pick** (`design.md` "Cherry-pick / Project-branch Port"). Delete that branch's ODF range implementation (`computeOdfReplacementRanges`, `rebuildOdfSegmentValues`, `extractOdfConcatenatedText`) in favour of the planner, and route its EML `redactText()` path through the planner too.
