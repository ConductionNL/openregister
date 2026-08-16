# tag-preserving-redaction Specification

## Purpose
TBD - created by archiving change tag-preserving-redaction. Update Purpose after archive.
## Requirements
### Requirement: The engine MUST detect a tagged PDF and measure its structure-element count before and after redaction (REQ-ORTPR-001)

The redaction engine MUST determine whether a PDF redaction input is a tagged
PDF and MUST count its logical-structure elements, over the already-parsed
`ddn/sapp` object model (never a raw byte scan, which misses objects packed in
compressed object streams). A PDF is tagged when its Catalog references a
`/StructTreeRoot` and/or carries `/MarkInfo` with `/Marked true`. The structure-
element count is the number of parsed objects whose dictionary has
`/Type /StructElem`. The engine MUST record this count for the input
(`tagCountBefore`) and for the produced output (`tagCountAfter`). Detection and
counting MUST reuse the document already parsed for redaction — no second load —
and MUST be exposed as a service seam (`PdfStructureInspector`) that is unit-
testable without a live Nextcloud instance.

#### Scenario: A tagged PDF is detected with its element count

- **GIVEN** a PDF whose Catalog carries `/StructTreeRoot` and `/MarkInfo << /Marked true >>` and 42 `/Type /StructElem` objects
- **WHEN** the engine inspects it on the redaction path
- **THEN** it MUST report the input as tagged with `tagCountBefore` equal to 42
- @e2e exclude structure-tree detection is pure engine logic over the SAPP object model — covered by PHPUnit (tests/unit/Service/File/Pdf/PdfStructureInspectorTest.php) against a tagged fixture PDF

#### Scenario: An untagged PDF reports a zero element count

- **GIVEN** a PDF with no `/StructTreeRoot` and no `/MarkInfo`
- **WHEN** the engine inspects it
- **THEN** it MUST report the input as not tagged with `tagCountBefore` equal to 0
- @e2e exclude negative detection case — covered by PHPUnit (PdfStructureInspectorTest::testUntaggedPdfHasZeroTags) against an untagged fixture PDF

### Requirement: Redacting a tagged PDF MUST preserve the structure tree or degrade explicitly, never silently flatten (REQ-ORTPR-002)

When redacting a **tagged** PDF with preservation in effect, the engine MUST
preserve the structure tree or degrade explicitly. `PdfTextReplacer::replaceInPdf`
MUST carry the structure-tree object graph (`/StructTreeRoot`,
`/MarkInfo`, `/RoleMap`, document `/Lang`) through the SAPP
`to_pdf_file_s(rebuild: true)` serialisation and the subsequent
`PdfMetadataSanitizer` pass rather than dropping it, and MUST re-measure the
output. The engine MUST attest `preserved: true` **only** when every one of the
following holds: `tagCountAfter` equals `tagCountBefore` and is greater than 0,
the `/StructTreeRoot` survived the rebuild, and no structured page's marked-
content operator count was altered by the replacement. In every other case where
the input was tagged, the engine MUST set `preserved: false` and MUST record at
least one machine-readable entry in `lossReasons` — it MUST NOT drop tags
without reporting the loss, and it MUST NOT claim a preservation it cannot
guarantee. The redacted bytes MUST still be produced (best-effort parity with
the existing residual policy); the accessibility regression is reported, not
concealed, and the caller decides how to act on it.

#### Scenario: Tagged input, faithful preservation attested

- **GIVEN** a tagged PDF whose redaction does not alter marked content on any structured page and whose `/StructTreeRoot` and 42 `/StructElem` objects survive the rebuild
- **WHEN** it is redacted with preservation in effect
- **THEN** the result MUST report `preserved: true`, `tagCountBefore` and `tagCountAfter` both 42, and an empty `lossReasons`
- @e2e exclude preservation attestation is engine byte-level behaviour — covered by PHPUnit (tests/unit/Service/File/Pdf/PdfTextReplacerTest.php::testTaggedPreservationAttested)

#### Scenario: Tagged input, unavoidable loss reported explicitly

- **GIVEN** a tagged PDF whose redaction mutates marked content on a tagged page so the tag→content correspondence can no longer be guaranteed by this engine
- **WHEN** it is redacted with preservation in effect
- **THEN** the redacted PDF MUST still be produced, `preserved` MUST be `false`, and `lossReasons` MUST contain `marked-content-correspondence-broken`
- @e2e exclude explicit-degradation path is engine logic — covered by PHPUnit (PdfTextReplacerTest::testTaggedLossIsReportedNotSilent)

#### Scenario: Structure tree dropped on rebuild is surfaced, not hidden

- **GIVEN** a tagged PDF whose `/StructTreeRoot` does not survive the SAPP rebuild
- **WHEN** the output is re-measured
- **THEN** `preserved` MUST be `false` and `lossReasons` MUST contain `structtreeroot-dropped-on-rebuild`
- @e2e exclude rebuild-loss detection — covered by PHPUnit (PdfTextReplacerTest::testStructTreeDroppedIsReported)

### Requirement: Every PDF redaction MUST return the `structurePreservation` result block with the exact contracted fields (REQ-ORTPR-003)

Every PDF redaction MUST make available a `structurePreservation` block with
exactly these fields and types: `requested` (boolean — whether preservation was
in effect), `preserved` (boolean — the conservative attestation of
REQ-ORTPR-002), `tagCountBefore` (integer), `tagCountAfter` (integer), and
`lossReasons` (array of strings, empty when `preserved` is true). The field
names MUST match this contract exactly (the DocuDesk accessible-redaction leaf
consumes them verbatim; drift breaks the consumer). The block MUST be reachable
via a `DocumentProcessingHandler::getLastStructurePreservation()` accessor
mirroring the existing `getLastResidualEntities()` / `getLastSanitizationReport()`
surfaces, and MUST be included in the `FileTextController::anonymizeFile` HTTP
`JSONResponse` for PDF inputs. For non-PDF redaction paths (DOCX/ODT/text) the
accessor MUST return null and the HTTP block MUST be omitted (no PDF structure
tree is involved). Loss reasons MUST be drawn from a documented enumerated set
(`engine-cannot-reauthor-structtree`, `marked-content-correspondence-broken`,
`structtreeroot-dropped-on-rebuild`, `input-not-tagged`,
`page-structure-not-preservable`) and MUST be PII-free (structural only).

#### Scenario: The block carries exactly the contracted fields

- **GIVEN** a PDF redaction has run
- **WHEN** the `structurePreservation` block is serialised
- **THEN** it MUST contain exactly `requested`, `preserved`, `tagCountBefore`, `tagCountAfter` and `lossReasons`, with the documented types, and no additional or renamed field
- @e2e exclude result-contract shape — covered by PHPUnit (tests/unit/Service/File/Pdf/StructurePreservationTest.php::testJsonSerializeFieldSet)

#### Scenario: A non-PDF redaction omits the block

- **GIVEN** a DOCX file is anonymised
- **WHEN** the processing result is read
- **THEN** `getLastStructurePreservation()` MUST return null and the HTTP response MUST NOT contain a `structurePreservation` block
- @e2e exclude non-PDF branch — covered by PHPUnit (tests/unit/Service/File/DocumentProcessingHandlerTest.php::testNonPdfHasNoStructureBlock)

### Requirement: The `preserveStructure` option MUST be plumbed through the public seams, defaulting to preserve when the input is tagged (REQ-ORTPR-004)

The engine MUST accept a tri-state `preserveStructure` option threaded through
the public seams — `DocumentProcessingHandler::anonymizeDocument` /
`replaceWords`, `PdfTextReplacer::replaceInPdf`, and the
`FileTextController::anonymizeFile` HTTP request param — with this resolution:
absent/`null` MUST mean **auto** (preserve if and only if the input is tagged,
i.e. `tagCountBefore > 0`); `true` MUST attempt preservation and still degrade
explicitly if impossible; `false` MUST skip preservation and set `requested`
false while still measuring tag counts for the truthful report. The default
(absent option) MUST be auto, so an existing caller redacting a tagged PDF gets
preservation without opting in, and a caller redacting an untagged PDF is
unaffected. Adding the option MUST NOT change the `anonymizeFile` route or its
existing file-access authorization gate.

#### Scenario: Default auto preserves a tagged input without an explicit opt-in

- **GIVEN** a tagged PDF and an `anonymizeFile` call with no `preserveStructure` param
- **WHEN** the redaction runs
- **THEN** `structurePreservation.requested` MUST be true (auto resolved to preserve because the input is tagged)
- @e2e exclude option-resolution matrix — covered by PHPUnit (PdfTextReplacerTest::testAutoPreservesTaggedByDefault)

#### Scenario: Explicit false skips preservation but still reports counts

- **GIVEN** a tagged PDF and a redaction call with `preserveStructure` false
- **WHEN** the redaction runs
- **THEN** `requested` MUST be false, `preserved` MUST be false, `lossReasons` MUST be empty, and `tagCountBefore` MUST still be measured (> 0)
- @e2e exclude opt-out path — covered by PHPUnit (PdfTextReplacerTest::testExplicitFalseSkipsButMeasures)

### Requirement: Untagged-PDF redaction output MUST remain byte-stable and report preservation as not applicable (REQ-ORTPR-005)

Introducing structure preservation MUST NOT change the redacted output of an
**untagged** PDF. For an untagged input, the produced bytes MUST be identical to
the output of the pre-change redaction pipeline (equivalently, identical to the
output with `preserveStructure` false), so no existing untagged-redaction
behaviour, residual validation, or metadata sanitisation regresses. The
`structurePreservation` block for an untagged input MUST report
`tagCountBefore` 0, `tagCountAfter` 0, and `preserved` false; when preservation
was requested (auto or `true`) but the input is not tagged, `lossReasons` MUST
contain `input-not-tagged` to state that preservation was not applicable rather
than that it failed.

#### Scenario: Untagged redaction output is unchanged

- **GIVEN** an untagged PDF redacted with the pre-change pipeline and the same PDF redacted with structure preservation available
- **WHEN** the two outputs are compared
- **THEN** the redacted bytes MUST be identical (structure preservation is inert for untagged input)
- @e2e exclude byte-stability regression guard — covered by PHPUnit (tests/unit/Service/File/Pdf/PdfTextReplacerTest.php::testUntaggedOutputByteStable) comparing against a golden fixture

#### Scenario: Untagged input reports preservation as not applicable

- **GIVEN** an untagged PDF redacted with preservation requested (default auto)
- **WHEN** the result block is read
- **THEN** `tagCountBefore` and `tagCountAfter` MUST both be 0, `preserved` MUST be false, and `lossReasons` MUST contain `input-not-tagged`
- @e2e exclude not-applicable reporting — covered by PHPUnit (PdfTextReplacerTest::testUntaggedReportsNotApplicable)

