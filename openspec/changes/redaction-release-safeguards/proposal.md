---
kind: code
depends_on: []
---

# Proposal: redaction-release-safeguards

## Why

Five rows measure whether what leaves the building is safe. Our column (`baseline/openwoo.tsv`):

| row | capability | ours today |
|---|---|---|
| 4.5 | Redaction cannot be undone in the published file | partial: opencatalogi's `DocumentRedactor` (`woo-redaction-pipeline`, merged 2026-10-05) calls `FileService::anonymizeDocument()` and counts a redaction as verified when the bytes differ and OpenRegister reports no residual entity. Nothing checks incremental updates, annotations, thumbnails or embedded files |
| 4.21 | Hidden content is found and reported before release: comments, notes, hidden rows, columns and sheets, and tracked insertions and deletions | partial: `DocxSanitizer` and `OdtSanitizer` find, remove and report this; there is no XLSX or PPTX sanitiser, so hidden rows, columns, sheets and speaker notes go out untouched |
| 4.22 | A value inside a metadata field is masked without withholding the whole field, and the product names the fields it cannot mask | no: `DocxSanitizer` and `PdfMetadataSanitizer` replace or remove whole fields, and nothing names a field the product cannot mask |
| 4.26 | The product states where the unredacted working copy lives and how long it is kept, and deletes it on that schedule | no: `FileTextController::anonymizeFile()` writes an `_anonymized` sibling and leaves the original; no lifetime is stated and no job deletes anything |
| 4.27 | An administrator forbids unattended redaction, so nothing is released without a person having decided | partial: every detection carries a decision (`EntityRelation::$skipAnonymization`), but nothing stops `POST /api/files/{fileId}/anonymize` or a direct `FileService::anonymizeDocument()` call before anyone decided |

**Decision D2 (Ruben, 2026-10-05): filinq's guarantees move into OpenRegister's engine, so every path has them.** filinq built two of them for the files filinq writes: REQ-RWB-01 "The written copy cannot be read back" (`RedactionIrreversibilityVerifier` with seven leak routes, `clean`, `leaking` or `unverifiable`, an unchecked route counted as a failure) and REQ-RWB-02 "Nothing is written until a person has checked it" (`RedactionReviewGate`, a review mark bound to the detection run, enforced in the service so the API, the batch path and the leaf reach the same refusal). This change carries both over faithfully into `FileService::anonymizeDocument()`, the one funnel behind OpenRegister's endpoint, opencatalogi's `DocumentRedactor` and filinq. `filinq/redaction-guarantees-from-the-engine` (wave 3) retires filinq's copies afterwards, and `opencatalogi/woo-redaction-scans-and-text-layer` (wave 2) gates the merged Woo pipeline on the verdict this change returns.

Read on filinq `development` at f0fa284: `openspec/changes/redaction-and-what-leaves-the-building/specs/redaction-output-guarantee/spec.md`, `lib/Service/Redaction/RedactionIrreversibilityVerifier.php`, `RedactionLeakRoute.php`, `RedactionReviewGate.php` and `RedactionVerdictRecorder.php`.

## What changes

- **The verifier (4.5, REQ-RWB-01).** `lib/Service/Anonymisation/RedactionIrreversibilityVerifier.php` checks the bytes actually written for every redacted value along seven routes, the same seven filinq checks: text under the mark, embedded preview or thumbnail, XMP, EXIF and document properties, incremental updates or earlier revisions, annotation and form field values, and embedded attachments. It returns `clean`, `leaking` or `unverifiable`. An unchecked route, an empty file or an empty list of values is never `clean`. It runs last in `anonymizeDocument()` for every output mode (PDF, PDF/A, DOCX, ODT, XLSX, PPTX, plain text, images once `anonymisation-image-seam` lands), and an output mode with no verifier entry fails the suite. The verdict is stored on the `AnonymisationLog` run and on the output file, and returned by the endpoint. `FilePublishingHandler::publishFile()` refuses to publish an `_anonymized` file whose verdict is not `clean`.
- **The review gate (4.27, REQ-RWB-02).** A new administrator setting `anonymisation.requireReview`. When on, `anonymizeDocument()` refuses until every detection of the file's current detection run has a person's decision and a review mark names who checked the file and when. The mark is bound to the run by a fingerprint of what the run found, so a re-detection that finds something else needs a new check. One refusal message tells the operator what to do. Decisions become explicit: `EntityRelation` gains `decision` (`undecided`, `redact`, `release`), `decidedBy` and `decidedAt`.
- **Spreadsheets and slides (4.21).** `XlsxSanitizer` and `PptxSanitizer` beside the DOCX and ODT ones: hidden and very hidden sheets, hidden rows and columns, comments and threaded comments, speaker notes, tracked revisions and custom XML are found, removed and reported in the persisted `SanitizationReport`.
- **Metadata masked in place (4.22).** Title, subject, keywords, description and text custom properties are masked with the same substitution map as the body, instead of blanked. Author and editor identity fields keep being stripped. A field that cannot be masked (non-text custom property, an unparsable XMP packet) is removed and named in the report under `unmaskableFields`.
- **The unredacted copy's custody (4.26).** `GET /api/files/{fileId}/anonymisation/custody` states where the original and the redacted copy live, the retention that applies to the original and the scheduled deletion date. The setting `anonymisation.originalRetention` is `keep` by default, because in OpenRegister the original is usually the object's archival record; an organisation that sets a duration gets the original deleted that long after its redacted copy is published, by a background job that writes an audit row and refuses while the object is under a legal hold or its archival nomination says keep.

## What does not change

- The detection and the substitution map, and the placeholder format (`anonymisation-placeholder-id-scope`).
- The default for unattended use: `anonymisation.requireReview` is off by default, because opencatalogi's merged Woo pipeline runs unattended until `opencatalogi/woo-review-surface` gives officers a place to decide. An administrator turns it on; the plan's D2 note names this dependency.

## Dependencies and absent apps

- None blocking. `anonymisation-image-seam` (wave 1) adds images as an output mode; whichever lands second adds the verifier entry, and the suite fails until it does.
- `reviewer-owns-their-decisions` (wave 1) builds on `decidedBy`.
- Consumers: `opencatalogi/woo-redaction-scans-and-text-layer` (wave 2) reads `verification` and refuses a publication that is not `clean`; `filinq/redaction-guarantees-from-the-engine` (wave 3) reads the same and retires filinq's verifier and gate. Both require OpenRegister, so there is no absent-engine path. filinq absent changes nothing here.

## Wave and decision

Wave 1, size L. Implements D2: the irreversibility check (filinq REQ-RWB-01) and the person-must-check gate (REQ-RWB-02) now live in the engine. Closes 4.5, 4.21, 4.22, 4.26 and 4.27.
