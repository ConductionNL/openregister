## Context

`DocumentProcessingHandler::anonymizeDocument` (`lib/Service/File/DocumentProcessingHandler.php:365`) builds one `needle => placeholder` map and hands it to four format branches. Three of them (`:918` office container, `:1033` docx, `:1216` plain text) consume it with `str_ireplace` in map order; the fourth (`:1285` PDF) delegates to SAPP via `PdfTextReplacer::replaceInPdf`.

The map is ordered longest-first by `uksort` (`:489-495`, comparator `[mb_strlen($right), $left] <=> [mb_strlen($left), $right]`) and PDF re-asserts the same ordering defensively (`PdfTextReplacer.php:177-184`). That ordering is a real fix for cross-entity **containment** and this change does not weaken it — it makes it a consequence of a stronger rule rather than the mechanism itself.

What ordering cannot fix, and what motivates this change:

- Sequential `str_ireplace` can only match needles that appear **contiguously** in a single string it is handed. The docx branch hands it one PhpWord element at a time (`:1030-1037`), so entities split across `<w:r>` runs are unreachable — documented in-tree at `PdfTextReplacer.php:288-292`.
- A single sort order cannot resolve **partial overlap** (`Jan de Vries` vs `Vries-Bakker`), only containment.
- `str_ireplace` has no notion of **word boundaries**, so free-text names over-redact (`Jan` → `Januari`).
- Only PDF implements the **residual report** the canonical spec already requires (`openspec/specs/pdf-anonymisation/spec.md:165-188`); docx/odt/txt return `[]` from `getLastResidualEntities()` (`:211`) whether or not they missed anything.

Positional (per-occurrence) replacement was validated with users on 2026-08-03 and found to have no real use case; it is explicitly out of scope. Value-based replacement — one entity text, one decision — is what makes range planning tractable.

## Goals / Non-Goals

**Goals**

- One place that decides what gets replaced, shared by every format, unit-testable without a document.
- Resolve containment **and** partial overlap, deterministically.
- Make an entity split across a format's internal segment boundaries matchable (the docx cross-run case).
- Make it impossible for an inserted placeholder to be rescanned by a later needle.
- Stop over-redacting free-text names.
- Report unmatched entities on every path, in the shape the canonical residual contract already defines.
- Put the ordering guarantee under a requirement and a test instead of a comment.

**Non-Goals**

- Positional / per-occurrence replacement — dropped after user validation, do not re-propose.
- Changing the placeholder format `[<TYPE>: <id>]`, the scope-local numbering, or the localized type label (owned by `anonymisation-placeholder-id-scope`).
- Replacing SAPP as the PDF matcher.
- OCR, image redaction, or anything about documents with no text layer.
- Entity *recognition* — the planner consumes the map it is given and never adds, splits or re-types entities.
- New tables, migrations or persisted state.

## Decisions

### Decision 1: A planner service, separate from application

New `lib/Service/File/Anonymisation/ReplacementPlanner.php`. Input: the immutable original text plus the `needle => placeholder` map. Output: a `ReplacementPlan` value object carrying accepted `ReplacementRange`s (`start`, `end`, `needle`, `placeholder`) and the list of needles that matched nothing. The planner performs no mutation and touches no I/O or Nextcloud interfaces, so every rule below is testable against a plain string.

Rejected: keeping the logic inline as a better comparator. A comparator cannot express overlap resolution, cannot report what failed to match, and cannot be tested without constructing a document.

### Decision 2: Maximum-coverage claiming, not longest-first

Enumerate **every** occurrence of every needle on the original text as a candidate range, then select the non-overlapping subset that maximises total covered codepoints — weighted interval scheduling, solved by DP in O(n log n) after sorting by end offset.

This subsumes longest-first: a contained needle can never beat its container, because the container covers strictly more characters. `robert@rjzondervan.nl` (EMAIL, 21 codepoints) therefore beats `rjzondervan` (PERSON, 11) without a length rule existing anywhere. It additionally handles cases longest-first gets wrong, e.g. two short non-overlapping needles that together cover more than one long needle overlapping both — greedy longest-first takes the long one and leaks both short ones.

**Determinism.** Candidates are first sorted into a total order: start ascending, then span descending, then type rank ascending (structured identifiers before free text), then needle bytewise ascending. Each candidate takes its index in that order. When two DP branches yield an equal total coverage, the branch including the lower-indexed candidate wins. Equal-coverage ties are therefore broken identically on every run, which preserves the byte-identical-re-run property that `anonymisation-placeholder-id-scope` established (`design.md` Decision 4).

Rejected: greedy longest-first with a `$consumed` map. Simpler, and it is what the project branch's ODF path already does, but it is only optimal for containment; the equal-length overlap case resolves arbitrarily and the two-short-vs-one-long case resolves wrongly.

### Decision 3: Cover the residue of rejected candidates

A candidate rejected for overlapping an accepted range may still have uncovered codepoints. After selection, redact each rejected candidate's remaining uncovered subranges. `Jan de Vries-Bakker` with `Jan de Vries` accepted yields a second range over `-Bakker`, producing `[PERSOON: 1][PERSOON: 2]`.

Rationale: leaving identifying text in the output is a worse outcome than an adjacent placeholder pair, and adjacency is already handled cosmetically by `PdfTextReplacer::collapseAdjacentDuplicatePlaceholders` (`:477`). The residue is attributed to the rejected candidate's entity, so the audit trail names the entity that actually caused the second placeholder.

A residue consisting only of whitespace or a single punctuation codepoint is dropped rather than redacted — it carries no information and would produce noise. Any residue containing a letter or digit is always covered.

The affected needle is reported as `partial` in the plan: it *was* matched, but not as a single span.

**Decided 2026-08-03: a `partial` finding sets `complete: false`.** Redaction-wise `complete: true` would be defensible — a split-matched needle's text is entirely absent — but the conservative reading was chosen: an overlap usually means recognition produced competing or mis-typed entities, which a human should see, and the output reads as two placeholders where one name is expected. The accepted cost is that `complete: false` no longer implies PII remains. It now means "a human should review this", and consumers must consult the finding *kind* (`unmatched` vs `partial`) to decide publishability. `residual_count` keeps counting only `unmatched`, so existing consumers are not silently re-pointed at a different quantity.

### Decision 4: Per-type boundary policy — free text bounded, structured literal

Resolved from the canonical constants in `EntityRecognitionHandler` (`:64-73`; PERSON, ORGANIZATION, LOCATION, EMAIL, PHONE, ADDRESS, DATE, IBAN, SSN, IP_ADDRESS). Three policies, settled 2026-08-03:

| Policy | Types | Why |
|---|---|---|
| **Word-bounded** | `PERSON`, `ORGANIZATION`, `LOCATION`, `ADDRESS`, and any unenumerated type | Short free-text values collide with ordinary words — `Jan` inside `Januari`, `Bas` inside `Bassin`. |
| **Delimited-token** | `DATE`, `SSN`, `PHONE`, `IP_ADDRESS` | Word-bounded, plus the match may not be a proper substring of a longer numeric token. A numeric needle inside a longer number does not merely over-redact, it silently rewrites a **different value**: `192.168.1.1` matches inside `192.168.1.10`, `123456789` inside `1234567890`, `2026` inside the case number `2026-0012`. |
| **Literal** | `EMAIL`, `IBAN` | Long, alphanumeric and distinctive, so substring false positives are negligible and there is no risk to weigh. That buys tolerance for unseparated concatenation (`IBANNL91ABNA…`), where any boundary rule would reject a genuine match and leave PII behind. |

**The deciding factor is numeric embeddability, not "structured vs free-text".** That was the first draft's error: it grouped all five structured types under `literal` on the strength of the concatenated-label argument, which only actually holds for the long alphanumeric ones. `IP_ADDRESS` is the sharpest counter-example — two adjacent addresses where one is a prefix of the other is an everyday occurrence in logs, and literal matching emits `[IP-ADRES: 1]0` for the second one: a corrupted, different address with a digit of it leaking.

The cost is accepted knowingly: an unseparated `BSN123456789` is now rejected and reported as `unmatched`. Visible miss beats silent corruption.

A boundary is "not adjacent to a word codepoint" — letter, combining mark, decimal digit or underscore — Unicode-aware, because `\b` in a non-`/u` pattern is byte-oriented and mis-fires on any accented Dutch name.

**Why delimited-token rather than literal spaces.** The stated requirement was that a date be "separated by spaces on either side, but internally may be concatenated". Taken literally that breaks the commonest form a date takes: `op 3 augustus 2026.` has no trailing space, so a space-on-both-sides rule would miss it. A numeric token is therefore defined as a run of digits optionally joined by single separators (`-`, `/`, `.`, `:`) where each separator is **immediately followed by a digit**; a match is rejected if expanding under that rule yields something longer than the match. Sentence punctuation does not extend a token (`1980.` → token `1980`), while `2026-0012` and `03.08.2026` do. Internal concatenation (`20260803`) and internal separation (`03-08-2026`) are unaffected, since the rule constrains only what surrounds the match.

**Why unenumerated types are bounded, not literal.** This reverses the first draft of this design. The two failure modes differ in *visibility*: a boundary miss leaves the needle matching nothing, which the report surfaces as `unmatched`; a literal false positive over-redacts or corrupts a longer string and nothing detects it. Defaulting to the policy whose failures are observable is the safer choice once reporting exists on every path, and the concatenated-label rationale that earns `literal` its place does not generalise to a custom type like `POLISNUMMER`.

Rejected: a global word-boundary rule — it suppresses legitimate structured-identifier matches. Rejected: no boundary rule (status quo) — it over-redacts every short name.

**Why `DATE` is lower-stakes than it looks.** `DATE` recognition is **disabled by default as a settings convention**: only birth dates genuinely warrant anonymisation, and the date recogniser otherwise produces a great deal of clutter. This is a configuration default, so its absence from openregister's code is by design — there is nothing to find here, and the only DATE-specific logic in-tree is `RiskLevelService.php:87` classifying it `RISK_LOW` / `CATEGORY_TEMPORAL_DATA`, which is consistent with that convention.

The delimited-token rule for `DATE` is therefore **defence-in-depth for the case where an operator deliberately enables dates**, not a fix on a hot path. It still matters, because an operator who enables dates to catch birth dates gets every other date too, and that is exactly the situation where a bare year colliding with a case number becomes likely. The same rule earns its place far more urgently on `SSN`, `PHONE` and `IP_ADDRESS`, which are enabled by default.

### Decision 5: Case-insensitive matching, applied consistently to verification

Matching stays case-insensitive, preserving current `str_ireplace` behaviour — recognition backends normalise inconsistently and a case-sensitive matcher would miss `JAN JANSEN` in a heading. Folding is `mb_strtolower` on both sides so it is multibyte-correct, which `str_ireplace` is not.

The post-apply verification MUST use the **same** folding and the same boundary policy. Today it does not: `PdfTextReplacer::validateOutput` compares case-sensitively (`:411-418`), so a residual whose case differs from the stored needle is not reported even on the only path that reports. Aligning the two is what makes a `complete: true` result trustworthy.

### Decision 6: Single-pass application

The writer walks accepted ranges by ascending start and **builds** the output: text slice, placeholder, text slice, placeholder. Nothing is progressively mutated, so an inserted placeholder is never part of the text a later needle is matched against.

This closes a hazard that longest-first ordering actively worsens: because the shortest needles run last, they run after every placeholder is already in place, so a short needle can match inside `[PERSOON: 1]`. Narrow in practice — it needs an entity whose trimmed text collides with a type label or a small integer — but it costs nothing to make structurally impossible, and re-anonymising an already-anonymised document (an explicitly supported idempotent operation, `DocumentProcessingHandler.php:400-405`) is exactly where placeholder-shaped text is present in the input.

### Decision 7: flatten → plan → scatter for segmented formats

A format adapter exposes the document as an ordered list of mutable text segments and a `SegmentMap` recording each segment's offset in their concatenation. The planner runs once on the concatenation; the writer translates each accepted range back to the segments it spans:

- The placeholder is written **entirely into the segment containing the range's start**, so the formatting of the first run is what survives — matching how a reader perceives the redacted span.
- Every subsequent segment the range overlaps has its covered portion **deleted**.
- A segment left empty is kept as an empty string rather than removed, so the format's own structure (run properties, styles, table cells) is not disturbed. Structural pruning is out of scope; `office-document-sanitization` owns structural rewriting.

This is what makes the docx cross-run case matchable: `Jan|ssen` split across two `<w:r>` elements is contiguous in the concatenation, so the planner sees one candidate.

Per format:

| Format | Segments | Notes |
|---|---|---|
| Plain text | one segment | Planner applies directly; no map needed. |
| docx | PhpWord elements exposing `getText()`/`setText()`, in document order, including headers, footers, tables and list items | Replaces the recursive closure at `:1027-1060`. The substantive adapter and the one carrying real risk. |
| Sanitised office container | text nodes of the sanitised XML | Replaces the whole-XML `str_ireplace` at `:918`, which currently also risks matching needles inside XML markup rather than content. |
| PDF | — | No adapter. SAPP owns matching; the planner supplies ordering, boundary flags and the report. |

Segment order must be document order, because the DP's determinism and the residue attribution are both defined on offsets in the concatenation.

### Decision 8: The residual report becomes format-agnostic

`getLastResidualEntities()` (`:211`) is populated on every path, from two sources: the planner's unmatched-needle list, and a post-apply verification pass over the produced text using the semantics of Decision 5. The record shape `{text, type, id}` and the placeholder-parsing regex (`:1385`) are unchanged, as is the best-effort (not fail-closed) policy and the `{success, complete, residual_count, residual_entities}` response contract from `openspec/specs/pdf-anonymisation/spec.md:165-188`.

**Reporting is never a gate.** Nothing blocks on residuals today — `validateOutput` contains no `throw` on the residual path, and the `strict` parameter no longer gates residual text despite a stale comment at `PdfTextReplacer.php:362` still describing a fail-closed policy (the code logs and falls back). That non-blocking behaviour is preserved and stated as a requirement rather than left implicit.

This matters more than it looks, because tasks 7.1 and 7.2 make detection *more* accurate — case-folded comparison catches residuals the current case-sensitive check misses, and SAPP's rejected substitutions are merged in. If residuals were ever reconnected to a fail-closed path, better detection would silently become more refused documents: precisely the wrong direction, since the operator's remedy is to iterate on the report. So the visible effect of this change is that more anonymisations report `complete: false` than before, on PDF as well as on the office paths — and none of them stop producing a file. Reconnecting `strict` to residual text is listed as an open question requiring a product decision, not a default.

### Decision 9: PDF keeps SAPP as its matcher

Extracted-text offsets are not usefully mappable back to content-stream operator positions, and SAPP already implements the cross-operator, cross-block and cross-line passes plus a claimed-range guard that assumes longest-first (`PdfTextReplacer.php:165-176`). Rebuilding that in the planner would be a large regression risk for no gain.

PDF therefore consumes the planner for: the ordered needle set (the existing defensive `uksort` is retained and its assumption documented as satisfied by the planner's output order), the per-needle boundary policy, and the unified report — reconciling `validateOutput`'s residuals with SAPP's `rejected_substitutions` stat (`:276`) into one list.

## Risks / Trade-offs

- **docx segment writing is the real risk.** Deleting the covered part of trailing runs can produce empty runs or, if the segment list is not in true document order, text written to the wrong place. Mitigation: the adapter is built and tested against fixtures with entities split across runs, across table cell boundaries, in headers/footers, and inside list items, and a golden-file test asserts the non-entity text of the output is unchanged.
- **Output bytes change** for any document that was previously partially anonymised, plus wherever residue coverage now applies. This is more-correct output, not a regression, but it will show up as diffs in existing fixtures and each one needs review rather than blind re-baselining.
- **Over-redaction moves, it does not vanish.** Residue coverage (Decision 3) deliberately redacts text that no single entity claimed. Combined with word boundaries reducing false positives, net utility should improve, but a document with many overlapping detections will contain adjacent placeholder pairs.
- **Boundary policy is a judgement call per type.** The table in Decision 4 is a default, not a truth. `DATE` is the least certain — a bare year is both a plausible date entity and a plausible ordinary number. It is placed under word-bounded so `1980` does not match inside `11980`.
- **Performance** is O(needles × text) enumeration plus O(n log n) DP. Cheaper than the status quo, which runs one full-document `str_ireplace` per needle, but the DP allocates per candidate; a document with pathologically many detections (thousands) should be spot-checked rather than assumed fine.
- **Numeric needles remain a footgun.** PHP coerces purely-numeric array keys to `int` (`:485-488`); every comparator, map lookup and report path must keep casting. Carried forward from the existing code, not introduced here, but the planner adds new places to get it wrong.
- **`uksort` becomes redundant but is kept.** Keeping a defensive sort whose guarantee now comes from elsewhere risks a future reader deleting the wrong one. Mitigated by the spec requirement and by comments pointing at the planner.

## Test Impact

- New unit tests for `ReplacementPlanner`: containment (the `robert@rjzondervan.nl` case), equal-length partial overlap (`Jan de Vries` / `Vries-Bakker`), two-short-beats-one-long coverage, word-boundary suppression (`Jan` / `Januari`), literal structured matching, case-folded matching, residue coverage and its whitespace-only exclusion, determinism across shuffled input order, numeric-needle handling.
- New unit tests per format adapter, with the docx cross-run fixture as the headline case.
- New tests asserting `getLastResidualEntities()` is populated for docx, odt and plain text — currently impossible to fail because those paths never populate it.
- A regression test that removing the ordering guarantee fails, which does not exist today.
- Existing PDF tests must continue to pass unchanged; `validateOutput`'s case-folding change needs a test for the differing-case residual it currently misses.
- Existing anonymisation fixtures across `tests/Unit/Service/File/` need review for expected-output drift per the Risks note.

## Seed Data

None. This change introduces no schemas, registers or objects, so `lib/Settings/openregister_register.json` is untouched.

## Cherry-pick / Project-branch Port

Lands on `development`, then ports to `test/anonimiseren-bij-de-bron-or`. `DocumentProcessingHandler` diverges heavily: the project branch still has a separate `buildEntityReplacements()` with **no** ordering at all, a per-format ODF range implementation (`computeOdfReplacementRanges`, `rebuildOdfSegmentValues`, `extractOdfConcatenatedText`) and an EML `redactText()` path that `development` does not have. This is a **semantic port, not a cherry-pick** — the same caveat as `anonymisation-placeholder-id-scope` (`proposal.md:40`).

Two port-specific notes:

1. The project branch's ODF range code is the closest existing analogue of the planner — flatten, claim by longest-first with a `$consumed` map, apply, re-sort by start. It should be **deleted in favour of the planner** during the port, not left in place alongside it.
2. The project branch's EML `redactText()` is a fifth apply site with no equivalent on `development`; it must consume the planner too, or the port leaves the EML path with the original defect.

## Open Questions

**Settled 2026-08-03**, recorded at their respective decisions above:

- Residuals never block, on any path.
- Boundary policy resolves to three classes on the basis of **numeric embeddability**: `DATE`, `SSN`, `PHONE` and `IP_ADDRESS` are delimited-token; `EMAIL` and `IBAN` stay literal; everything else, including unenumerated types, is word-bounded.
- Unenumerated types default to word-bounded, not literal, because a boundary miss is reported while a literal false positive is silent.
- A `partial` finding sets `complete: false`.
- `DATE` recognition is disabled by default as a settings convention, so its rule is defence-in-depth rather than a hot path.

Still open:

- **Does DocuDesk's grondslagen-summary distinguish `unmatched` from `partial`?** It now has to, at minimum to avoid treating a `partial`-only result as unpublishable — that document is fully redacted. Cross-app, and a blocker for the operator-facing half of this being useful.
- **Does the residue-coverage rule need a length floor?** A residue of one or two letters is redacted today if it contains a letter. `[PERSOON: 1][PERSOON: 2]` where the second placeholder covers a two-character fragment may be worse for the reader than leaving the fragment, which is not identifying on its own. No evidence either way yet.
- **Do unseparated numeric identifiers occur often enough to need a fallback?** Delimited-token rejects `BSN123456789`, reporting it rather than redacting it. If real documents (form exports, filenames pulled into text) turn out to concatenate identifiers to labels routinely, a narrow relaxation — allow a directly-adjacent *letter* run that is not itself digit-adjacent — would recover those matches without reopening the numeric-substring hole. Not built now, because the reporting makes the miss visible and there is no evidence yet on frequency.
