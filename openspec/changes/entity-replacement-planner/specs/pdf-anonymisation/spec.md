## ADDED Requirements

### Requirement: PDF text replacement MUST remain delegated to SAPP

The PDF path MUST continue to perform its matching and content-stream rewriting through SAPP's `replace_text_in_document`, which owns the cross-operator, cross-block and cross-line passes and a claimed-range guard that assumes the substitution map is ordered longest-first.

`entity-replacement-planner` MUST NOT be used to compute character ranges for PDF content streams: offsets in re-extracted text are not addressable positions in the underlying content streams, so a range plan derived from extracted text cannot be applied to PDF bytes. The planner's contribution to this path is limited to the ordered needle set, the per-type boundary policy, and the shared residual report.

The defensive longest-first ordering applied before calling SAPP MUST be retained. Its guarantee is now additionally provided upstream by the planner's coverage-maximising selection, but the local re-sort MUST stay so the SAPP contract holds for every caller and survives future SAPP version bumps.

This requirement exists to stop a future reader concluding that the planner supersedes SAPP for PDF, and to stop the defensive re-sort being deleted as redundant.

#### Scenario: The substitution map reaching SAPP is ordered longest-first

- **GIVEN** a substitution map supplied in arbitrary order containing `"Jansen"` and `"Jan Jansen"`
- **WHEN** the PDF replacer prepares the map for SAPP
- **THEN** `"Jan Jansen"` precedes `"Jansen"` in the map handed to `replace_text_in_document`

#### Scenario: PDF replacement is not re-implemented on extracted text

- **GIVEN** a PDF whose entities are known at character offsets in its re-extracted text
- **WHEN** anonymisation runs
- **THEN** replacement is performed by SAPP against content streams
- **AND** no attempt is made to apply extracted-text offsets to the PDF bytes

## MODIFIED Requirements

### Requirement: A post-replacement validation pass MUST report residual entity text (best-effort, not fail-closed)

After SAPP rewrites the PDF, the implementation MUST re-extract the output's text via `smalot/pdfparser` and detect any substitution-map entry (including variants) that survives. **The policy is best-effort, not fail-closed** (changed 2026-06-16, product-owner-approved): a partially-redacted file MUST still be produced and persisted, and the residual entities surfaced as a warning, so the operator can iterate (add manual entities, skip unselected occurrences) and re-run. When residual entity text is found, the implementation MUST:

1. Still produce/persist the output (do NOT discard it).
2. Log a PII-free diagnostic (counts + structural counters only, per ADR-005 — never the residual text in logs).
3. Return the residual needle list to the caller. The anonymise response is HTTP 200 with `{"success": true, "complete": false, "residual_count": N, "residual_entities": [...]}`. The authenticated response MAY include the residual entity *text* for the review UI (a deliberate, product-owner-approved deviation from the ADR-005 no-PII-in-responses rule; logs remain PII-free).

The validation pass remains the key safety surface — it catches every silent-failure mode of byte-replace (encoding mismatches, missed splits, font edge cases) — but it now informs rather than blocks, because some residuals (e.g. NER spans that cross table cells and are not contiguous in the PDF) cannot be redacted as a single needle and would otherwise make every such document fail.

As of `entity-replacement-planner`, this reporting contract is **no longer PDF-specific**. The record shape, the best-effort policy, the response contract and the PII-free-logs rule above are the canonical form of a report that EVERY format MUST produce (see `entity-replacement-planner`: "Every format MUST report unmatched and partially matched entities"). PDF's validation pass is one implementation of it, not the only one. Two consequences bind the PDF path specifically:

- **Matching semantics MUST agree with the apply step.** The residual comparison MUST use the same case folding and the same per-type boundary policy as the replacement it verifies. A case-sensitive residual check against a case-insensitively applied replacement is non-conforming: it reports `complete: true` for a document that still contains entity text differing only in case from the stored needle.
- **The residual list MUST reconcile both failure sources.** Needles rejected by SAPP (surfaced in its replace statistics) and needles found surviving by re-extraction MUST be merged into the one residual list, so a rejected substitution cannot be reported as a clean redaction.

**Widening detection MUST NOT introduce blocking.** Both consequences above make the residual check find *more* than it does today. That MUST change only what is reported, never whether a file is produced: an anonymisation MUST NOT fail, throw, or withhold its output because a residual was detected. As of this change `validateOutput` contains no `throw` on the residual path at all — its `strict` parameter no longer gates residual text, despite a stale comment at `lib/Service/File/Pdf/PdfTextReplacer.php:362` still describing a fail-closed policy. That comment MUST be corrected rather than re-implemented. `strict` retains its other uses (unauditable output, re-extraction failure); it MUST NOT be reconnected to residual entity text without an explicit product decision, because more accurate detection would otherwise silently turn into more documents refused.

#### Scenario: More accurate detection does not withhold output

- **GIVEN** a PDF whose only residual is an occurrence differing from the needle in case, which the previous case-sensitive check did not detect
- **WHEN** anonymisation runs on the post-change code path
- **THEN** the output file is produced and persisted exactly as before
- **AND** the response is HTTP 200 with `success: true`
- **AND** the only difference from the pre-change behaviour is that `complete` is now `false` and the residual appears in the list

#### Scenario: Validation surfaces residual entity text without discarding the file

- **GIVEN** an input PDF that produces an output containing `"Jan Jansen"` (the byte-replace missed some occurrence)
- **WHEN** the validation pass runs
- **THEN** the output is still produced and persisted
- **AND** the residual needle is returned to the caller (no exception)
- **AND** the controller surface is HTTP 200 with `success: true`, `complete: false`, and the residual list
- **AND** the log line is PII-free (count + counters only)

#### Scenario: Validation passes when the output is clean

- **GIVEN** an input PDF where all substitution-map entries were correctly replaced
- **WHEN** the validation pass runs
- **THEN** the output is returned as the anonymisation result with `complete: true` and an empty residual list
- **AND** a sanitisation report is persisted (counter of bytes replaced, fonts touched, filters decoded)

#### Scenario: A residual differing only in case is reported

- **GIVEN** a substitution map with the needle `"Jan Jansen"`
- **AND** an output PDF whose re-extracted text contains `"JAN JANSEN"` at an occurrence the replacement missed
- **WHEN** the validation pass runs
- **THEN** the needle is reported as residual
- **AND** the response reports `complete: false`

#### Scenario: A substitution rejected by SAPP appears in the residual list

- **GIVEN** a substitution SAPP could not apply and reports as rejected
- **WHEN** the validation pass runs
- **THEN** that needle appears in the residual list even if re-extraction did not independently find it
- **AND** the response reports `complete: false`

### Requirement: Replacement output MUST use identifiable placeholders, not pure redaction

Hard constraint #2: replacements MUST take the form `[<TYPE>: <id>]` (the established convention from `entity-relation-grondslagen`). Pure black-bar redaction is ruled out. All variants of one logical entity MUST resolve to the same placeholder text (same id) — the substitution map already enforces this; this Requirement locks the invariant at the spec level so future maintainers don't break it for layout reasons.

Both `<TYPE>` and `<id>` are whatever the upstream substitution map carries for the entity. As of `anonymisation-placeholder-id-scope`, `<id>` is a **scope-local sequence number** (per-document by default, per-dossier when opted in), NOT the global `openregister_entities.id`; and `<TYPE>` is a **localized label** in the acting user's language (e.g. `PERSOON` on a Dutch instance), NOT necessarily the English type. The PDF replacer is agnostic to how either is computed — it MUST faithfully emit the placeholder text supplied in the substitution map without re-deriving, re-numbering, or re-translating. Only the upstream map changes; the PDF replacement contract is unchanged beyond this clarification.

As of `entity-replacement-planner`, the planner likewise never authors placeholder text: it decides which ranges are replaced and emits the supplied placeholder verbatim. Where an accepted range and a rejected overlapping candidate's residue are both redacted, the emitted placeholders are still verbatim map values, so two adjacent placeholders MAY appear for what a reader perceives as one name. Adjacent identical placeholders separated only by whitespace, dashes or underscores continue to be collapsed to a single placeholder.

#### Scenario: Placeholder format follows `[<TYPE>: <id>]`

- **GIVEN** an entity with type `PERSON`, the scope-local id `1` in the substitution map, and value `"Jan Jansen"`
- **WHEN** `anonymizeDocument` replaces this entity in a PDF
- **THEN** every replacement instance in the output text reads `[PERSON: 1]` (case-sensitive, with a space after the colon)
- **AND** the replacer MUST emit exactly the id from the map — it MUST NOT substitute the global entity id

#### Scenario: Variants of one entity share one placeholder

- **GIVEN** an entity with scope-local id `1`, value `"Jan Jansen"`, variants `["Jansen", "Jan"]`
- **WHEN** `anonymizeDocument` replaces these in a PDF containing all three
- **THEN** every replacement (regardless of which variant matched) reads `[PERSON: 1]`
- **AND** adjacent identical placeholders separated only by whitespace / dashes / underscores ARE collapsed to a single placeholder

### Requirement: NO breaking changes for DOCX / ODT / text branches

A change scoped to the PDF branch MUST NOT modify the DOCX, ODT or plain-text branches, and the `anonymizeDocument` public contract MUST be preserved for callers regardless of which branch changes.

This requirement was originally written as a **scope fence for the PDF work**: `pdf-anonymisation` targeted the PDF branch exclusively, so leaving the other branches untouched was the correct constraint at that time. It is re-scoped here, because `entity-replacement-planner` deliberately changes those branches — that is the whole point of it, and reading this requirement as a permanent prohibition would freeze three known correctness defects in place (unreachable text split across `<w:r>` runs, unresolvable partial overlap, and silently unreported residuals).

What remains binding:

- The **public contract** of `anonymizeDocument` MUST be preserved for callers: signature compatibility, return shape, side effects, and audit-trail entries.
- The dispatch **structure** — which branch handles which MIME type — MUST NOT change. This change replaces what a branch does internally, not which branch runs.
- No change to a non-PDF branch may be made *by a change whose stated scope is the PDF branch*. The fence still applies to `pdf-anonymisation` itself and to any successor change scoped to PDF.

What is superseded:

- The clause requiring DOCX/ODT/text **output** to be byte-identical or semantically identical across changes. `entity-replacement-planner` intentionally produces different output for documents that were previously partially anonymised, for documents where a short free-text name was previously over-redacted, and wherever rejected-candidate residue is now covered. Those differences are the fix, not a regression.

#### Scenario: The `anonymizeDocument` public contract is unchanged

- **GIVEN** an existing caller of `anonymizeDocument` for a DOCX input
- **WHEN** the same call is made on the post-change code path
- **THEN** the return type and shape are unchanged, the output file is created at the same location, and the audit-trail entries are of the same form

#### Scenario: MIME dispatch is unchanged

- **GIVEN** DOCX, ODT, plain-text and PDF inputs
- **WHEN** each is anonymised
- **THEN** each is handled by the same branch as before this change

#### Scenario: A previously over-redacted DOCX intentionally changes output

- **GIVEN** a DOCX containing the word `Januari` and a `PERSON` entity `"Jan"`
- **AND** a pre-change output in which `Januari` had been rewritten to `[PERSOON: 1]uari`
- **WHEN** the same DOCX is anonymised on the post-change code path
- **THEN** the output differs from the pre-change output
- **AND** `Januari` is present unmodified
- **AND** this difference does NOT constitute a breaking change under this requirement

#### Scenario: A PDF-scoped change still may not touch the office branches

- **GIVEN** a future change whose stated scope is the PDF branch
- **WHEN** it modifies a DOCX, ODT or plain-text branch code path
- **THEN** it violates this requirement
