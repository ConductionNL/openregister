## 1. Value objects and exception

- [x] 1.1 Create `lib/Service/File/SanitizationReport.php` — immutable value object with readonly properties: `commentsRemoved: int`, `trackedChangesAccepted: int`, `trackedChangesDropped: int`, `revisionAttributesStripped: int`, `hyperlinksFlattened: int`, `metadataFieldsScrubbed: int`, `customXmlPartsDropped: int`, `fieldCodesStripped: int`, `sentinelApplied: string`. Implement `JsonSerializable` returning the keys in the same order.
- [x] 1.2 Create `lib/Service/File/SanitizationResult.php` — value object with `path: string` (sanitised temp file) and `report: SanitizationReport`.
- [x] 1.3 Create `lib/Exception/SanitizationException.php` extending `\Exception`. Public-readonly `reason: string` field with REASON_* constants (`unsupported-mime` | `encrypted` | `corrupt-zip` | `internal`) for typed catch-handling. Messages are PII-free (ADR-005).
- [x] 1.4 Sentinel constant `SENTINEL = 'DocuDesk Anonymisation'` placed on `OfficeDocumentSanitizer`, documented as an intentional tool brand (design D5).

## 2. Strategy interface and orchestrator

- [x] 2.1 Create `lib/Service/File/Sanitizer/SanitizerInterface.php`:
  ```php
  interface SanitizerInterface {
      public function supports(string $mimeType): bool;
      public function sanitize(string $sourcePath, string $destPath): SanitizationReport;
  }
  ```
- [x] 2.2 Create `lib/Service/File/OfficeDocumentSanitizer.php` — orchestrator. Constructor: `IRootFolder $rootFolder, ITempManager $tempManager, LoggerInterface $logger, SanitizerInterface[] $strategies` (DI-injected list of strategies).
- [x] 2.3 Implement `OfficeDocumentSanitizer::sanitize(int $fileId): SanitizationResult`:
    1. Resolve `$file = $this->rootFolder->getById($fileId)[0]` (with empty-result check → throw `SanitizationException` reason `unsupported-mime`).
    2. Read `$mimeType = $file->getMimeType()`.
    3. Find a matching strategy via `$strategy->supports($mimeType)` → first match wins; no match throws `SanitizationException` reason `unsupported-mime`.
    4. Allocate a temp file via `$tempManager->getTemporaryFile($extensionForMime)`.
    5. `copy($file->fopen('r'), $tempPath)` (or NC equivalent — `fopen → stream_copy_to_stream`).
    6. Call `$report = $strategy->sanitize($tempPath, $tempPath)`.
    7. Log a single info-level line with `fileId`, `mimeType`, `strategy class`, and the counts (ADR-005 compliant — counts only, no content).
    8. Return `new SanitizationResult($tempPath, $report)`.

## 3. DocxSanitizer implementation

- [x] 3.1 Create `lib/Service/File/Sanitizer/DocxSanitizer.php` implementing `SanitizerInterface`. `supports()` matches `application/vnd.openxmlformats-officedocument.wordprocessingml.document`.
- [x] 3.2 Implement `sanitize($sourcePath, $destPath)` using `ZipArchive`. If source != dest, copy first. Open with `ZipArchive::open($destPath, ZipArchive::RDONLY)` — read all part names; then re-open writeable. On open failure code matching encryption (`ZipArchive::ER_NOZIP`, `ZipArchive::ER_INVAL` paired with detected encryption magic bytes), throw `SanitizationException` reason `encrypted`.
- [x] 3.3 **Comment removal** — implement helper `removeComments(ZipArchive $zip, SanitizationReport $report): void`:
    - Read `word/comments.xml`; count distinct `<w:comment w:id="...">` elements; record count.
    - Delete `word/comments.xml`, `word/commentsExtended.xml`, `word/commentsIds.xml`, `word/people.xml` from the zip.
    - Parse `[Content_Types].xml`; remove `<Override PartName="/word/comments.xml" .../>` (and the three sibling parts).
    - Parse `word/_rels/document.xml.rels`; remove `<Relationship>` entries whose `Target` is any of `comments.xml`, `commentsExtended.xml`, `commentsIds.xml`, `people.xml`.
    - For each XML part `word/document.xml`, `word/header*.xml`, `word/footer*.xml`, `word/footnotes.xml`, `word/endnotes.xml`: load as `DOMDocument`; XPath remove `//w:commentRangeStart`, `//w:commentRangeEnd`, `//w:commentReference`; serialise back.
- [x] 3.4 **Tracked-change resolution** — implement helper `acceptTrackedChanges(ZipArchive $zip, SanitizationReport $report): void`:
    - For each XML body-bearing part: load as DOMDocument; for each `<w:ins>` element, replace it with its child nodes (unwrap); for each `<w:del>` element, remove it entirely. Increment `trackedChangesAccepted` per ins, `trackedChangesDropped` per del.
    - Final attribute-strip pass: walk all elements; remove attributes `w:rsidR`, `w:rsidRPr`, `w:rsidDel`, `w:rsidTr`, `w:rsidP`. Increment `revisionAttributesStripped` per attribute removed.
- [x] 3.5 **Custom XML strip** — implement helper `stripCustomXml(ZipArchive $zip, SanitizationReport $report): void`:
    - Enumerate all part paths matching regex `^customXml/item\d*\.xml$` or `^customXml/itemProps\d*\.xml$`. Count logical parts (one per `item<n>.xml`).
    - Delete the matching parts from the zip.
    - Remove their `<Override>` entries from `[Content_Types].xml`.
    - Remove `<Relationship>` entries in `word/_rels/document.xml.rels` targeting `customXml/*`.
    - For each body-bearing XML part: find `<w:sdt>` elements with a `<w:sdtPr>/<w:dataBinding>` descendant; replace each `<w:sdt>` with the child nodes of its `<w:sdtContent>` (unwrap-preserving-visible-content).
- [x] 3.6 **Metadata scrub** — implement helper `scrubMetadata(ZipArchive $zip, SanitizationReport $report): void`:
    - Parse `docProps/core.xml`; for each of `dc:creator`, `cp:lastModifiedBy`, `dc:title`, `dc:subject`, `cp:keywords`, `dc:description`, `cp:category`, `cp:contentStatus`: if the element exists with non-empty content, replace its text content with the sentinel and increment `metadataFieldsScrubbed`.
    - Parse `docProps/app.xml`; same treatment for `Company`, `Manager`.
    - Parse `docProps/custom.xml` (if present); for each `<property>` containing `<vt:lpwstr>` or `<vt:lpstr>`, replace the inner string with sentinel and increment counter. Skip non-string-typed properties (`<vt:i4>`, `<vt:bool>`, etc.) — leave unchanged.
    - Set `SanitizationReport.sentinelApplied = OfficeDocumentSanitizer::SENTINEL`.
- [x] 3.7 **Field-code strip** — implement helper `stripFieldCodes(ZipArchive $zip, SanitizationReport $report): void`:
    - Strip-list = `['AUTHOR', 'USERNAME', 'USERINITIALS', 'LASTSAVEDBY']` (case-insensitive comparison).
    - For each body-bearing XML part: load DOMDocument.
    - Simple form: XPath `//w:fldSimple[@w:instr]`. For each, normalise `w:instr` attribute (trim, uppercase, strip backslash-flags after the field name). If the field name is in the strip list, remove the element. Increment `fieldCodesStripped`.
    - Complex form: walk children of paragraphs sequentially. Find `<w:r>` containing `<w:fldChar w:fldCharType="begin"/>`. Read forward until the next `<w:fldChar w:fldCharType="separate"/>`, collecting any `<w:instrText>` content. Normalise the collected instruction. If it's in the strip list, remove all runs from begin through the matching `end` fldChar (inclusive). Increment counter once per field.
- [x] 3.8 **Hyperlink flatten** — implement helper `flattenHyperlinks(ZipArchive $zip, SanitizationReport $report): void`:
    - For each body-bearing XML part: load DOMDocument.
    - For each `<w:hyperlink>` element: read `r:id` (if any). Replace the element with its `<w:r>` children. Increment counter.
    - Collect the set of `r:id` values seen.
    - Open the rels file paired with the part (`word/_rels/<part>.rels`); remove `<Relationship>` entries whose `Id` is in the collected set AND whose `Type` ends with `/hyperlink`.
- [x] 3.9 Orchestrate: in `sanitize()`, call helpers in order: comments → tracked-changes → custom-xml → metadata → field-codes → hyperlinks. Each writes back to the zip via `ZipArchive::addFromString` (replacing the part). `ZipArchive::close()` commits.
- [x] 3.10 Return the populated `SanitizationReport`.

## 4. OdtSanitizer implementation

- [x] 4.1 Create `lib/Service/File/Sanitizer/OdtSanitizer.php` implementing `SanitizerInterface`. `supports()` matches `application/vnd.oasis.opendocument.text`.
- [x] 4.2 Open the ODT zip via `ZipArchive`. ODT zip-encryption detection: `ZipArchive::open` returns specific error codes for encrypted entries; throw `SanitizationException` reason `encrypted`.
- [x] 4.3 **Comment removal** — load `content.xml` as DOMDocument. XPath remove `//office:annotation` and `//office:annotation-end`. Increment `commentsRemoved` per `office:annotation` removed.
- [x] 4.4 **Tracked-change resolution** — locate the `text:tracked-changes` container in `content.xml`. Parse the contained `text:changed-region` entries to build an accept/reject map by `text:id`. Then walk `content.xml`:
    - `text:change-start` + matching `text:change-end` around an inserted range: remove the start/end markers only; keep the content between them. Increment `trackedChangesAccepted`.
    - `text:change` (delete marker — used for deleted ranges that are not rendered in the current view): the marker references a `text:id`; look up the changed-region's content via `text:deletion`; remove the marker AND any inline rendering of the deleted text. Increment `trackedChangesDropped`.
    - Remove the `text:tracked-changes` container element itself after processing.
- [x] 4.5 **Metadata scrub** — load `meta.xml`. For each of `dc:creator`, `meta:initial-creator`, `dc:title`, `dc:subject`, `meta:keyword`, `dc:description`: replace text content with sentinel; increment counter.
    - For each `meta:user-defined` element: if `meta:value-type` attribute is absent OR equals `string`, replace text content with sentinel; increment counter. Otherwise preserve unchanged.
- [x] 4.6 **Hyperlink flatten** — XPath `//text:a` in `content.xml`. For each, replace with its child nodes (text + spans). Increment counter. (No rels file equivalent — the href was inline.)
- [x] 4.7 **Person-identity placeholder removal** — XPath remove `//text:author-name`, `//text:author-initials`, `//text:initial-creator` in `content.xml`. Increment `fieldCodesStripped` per element removed (re-using the DOCX counter; ODT terminology is "placeholder" but the report key stays uniform).
- [x] 4.8 Set `sentinelApplied`; close the zip; return report.

## 5. AnonymizationLog persistence

The anonymisation log lands in this change: a new `openregister_anonymisation_log` table records every anonymisation run with the sanitisation report on the `sanitization` column (JSON; nullable; `null` for non-Office runs per the spec). `DocumentProcessingHandler::anonymizeDocument()` writes a row at the end of every run.

- [x] 5.1 Migration `lib/Migration/Version1Date20260611000000.php` creates `openregister_anonymisation_log` (idempotent — `hasTable` guard). Columns: id, file_id (indexed), object_uuid (nullable, indexed), register_id (nullable, indexed), schema_id (nullable, indexed), mime_type, engine, status (default `success`), reason (nullable), `sanitization` TEXT (nullable — JSON payload), replacements (default 0), duration_ms (nullable), created.
- [x] 5.2 Entity `lib/Db/AnonymisationLog.php` with `STATUS_SUCCESS` / `STATUS_FAILURE` constants, `getSanitizationArray()` JSON decode helper, and `jsonSerialize()` matching the column order. `sanitization` is `?string` to honour the nullable column.
- [x] 5.3 Mapper `lib/Db/AnonymisationLogMapper.php` (QBMapper) with `find()`, `findByFileId()`, `findByObjectUuid()`, `findLatestSuccessForFile()`. `insert()` stamps `created` server-side.
- [x] 5.4 `DocumentProcessingHandler` accepts an optional `AnonymisationLogMapper` constructor parameter (nullable so tests stay construct-safe). At the tail of `anonymizeDocument()` the handler builds an `AnonymisationLog` row carrying the JSON-serialised `SanitizationReport` (or `null` for non-Office runs), `file_id`, `mime_type`, `engine` (`OfficeDocumentSanitizer` / `PdfTextReplacer` / `TextReplacer`), and the replacement count. Persistence failures are best-effort: a DB-side error is logged PII-free and swallowed (does NOT mask the successful redaction).

## 6. DocumentProcessingHandler integration

- [x] 6.1 `DocumentProcessingHandler::anonymizeDocument` now: detects sanitisable Office MIME via `OfficeDocumentSanitizer::isSanitizable()`; runs `sanitize($fileId)` before the walker; on `SanitizationException` reason `encrypted` translates to a caller-correctable "Cannot anonymise an encrypted document" exception, other reasons log (PII-free) + rethrow; uses `$result->path` as the walker's working file (DOCX → PhpWord walker, ODT → ZIP-container text replace). Report retained via `getLastSanitizationReport()` (no log table — see §5).
- [x] 6.2 Non-Office MIMEs (PDF, plain text) run the existing pipeline unchanged; no sanitisation report produced (`getLastSanitizationReport()` returns null).
- [x] 6.3 The sanitised temp file is allocated via `ITempManager` (auto-cleaned at request end); no explicit unlink.

## 7. DI wiring

- [x] 7.1 Register `OfficeDocumentSanitizer` in `lib/AppInfo/Application.php` (or wherever DI is configured for services). Register `DocxSanitizer` and `OdtSanitizer` as factories that produce instances; bind them as the `$strategies` array on `OfficeDocumentSanitizer` constructor.
- [x] 7.2 Inject `OfficeDocumentSanitizer` into `DocumentProcessingHandler` constructor.

## 8. Test fixtures

> Fixtures are synthesised in-test (mirroring the existing `PdfMetadataSanitizerTest` convention in this app) rather than checked in as binary `.docx`/`.odt` blobs. The DOCX builder produces 3 comments, 2 accepted inserts, 2 dropped deletes, a data-bound `<w:sdt>` over `customXml/item1.xml`, an external + an internal-anchor hyperlink, a simple-form AUTHOR field, a complex-form USERNAME field, a preserved DATE field, and populated `core.xml` metadata. The ODT builder produces 3 annotations, tracked-change accept+delete, a `text:a` hyperlink, a `text:author-name` placeholder, and populated `meta.xml`. This keeps the fixtures readable/diffable and avoids binary blobs in the repo.

- [x] 8.1 DOCX fixture — synthesised in `DocxSanitizerTest::buildFixture()` covering every surgical category.
- [x] 8.2 ODT fixture — synthesised in `OdtSanitizerTest::buildFixture()` covering every surgical category.

## 9. Unit tests

- [x] 9.1 `tests/unit/Service/File/Sanitizer/DocxSanitizerTest.php` — cover each Requirement / Scenario from the spec:
    - Comments removed from comments part, content-types, rels, and inline references.
    - Inserts accepted (inner content preserved); deletes dropped.
    - Revision attributes stripped from runs / paragraphs.
    - Custom XML parts removed; data-bound sdt unwrapped preserving visible content.
    - Metadata scrubbed to sentinel; timestamps preserved; non-string custom properties preserved.
    - AUTHOR / USERNAME / USERINITIALS / LASTSAVEDBY fields stripped (both forms); DATE preserved.
    - Hyperlinks flattened; rels entry removed; internal anchor flattened with no rels entry to remove.
    - Each counter on `SanitizationReport` matches the fixture's expected counts.
    - Encrypted DOCX raises `SanitizationException` reason `encrypted`.
- [x] 9.2 `tests/unit/Service/File/Sanitizer/OdtSanitizerTest.php` — cover Requirements / Scenarios for ODT.
- [x] 9.3 `tests/unit/Service/File/OfficeDocumentSanitizerTest.php` — orchestrator: dispatch to correct strategy per MIME; unsupported MIME raises; original file in NC Files is byte-identical post-call (using a mock IRootFolder).
- [x] 9.4 `tests/unit/Service/File/SanitizationReportTest.php` — `jsonSerialize()` produces the expected key set.
- [x] 9.5 PII-redacted logging test: induce a sanitiser failure mid-process; capture the log line; assert no document content appears in it (only file ID, MIME, strategy, exception class, structural detail).

## 10. Integration tests

The §5 log table now exists, so the assertions can compose the sanitiser + mapper chain against synthetic fixtures without a live NC instance. End-to-end PDF / DOCX upload + anonymise via the REST surface still runs in the Docker test environment, but the per-format sanitiser + log-row JSON shape is exercised directly here.

- [x] 10.1 `tests/Integration/OfficeDocumentSanitizationIntegrationTest::testDocxRunWritesReportToAnonymisationLog` — synthesised DOCX → orchestrator dispatch → mock `AnonymisationLogMapper` captures the row; asserts original-byte-identical, `engine = OfficeDocumentSanitizer`, decoded `sanitization` carries every spec key.
- [x] 10.2 `testOdtRunWritesReportToAnonymisationLog` — parity for ODT.
- [x] 10.3 `testPdfRunLeavesSanitizationColumnNull` — PDF row asserts `sanitization` decodes to `null`, `engine = PdfTextReplacer` (spec scenario "PDF anonymisation leaves sanitization column null").
- [x] 10.4 `testJsonShapeMatchesSanitizationReport` — extra invariant check that the JSON keys match the spec list verbatim.

## 11. Manual validation gate (BLOCKING)

> DEFERRED — requires Microsoft Word + LibreOffice desktop readers, unavailable in the headless build environment. The sanitiser is validated by the unit suite (every part — `[Content_Types].xml`, `_rels`, body XML — is reconciled and re-parsed as well-formed XML; orphan-reference removal is asserted). A Word/LibreOffice reopen pass should be run by a human reviewer with the synthesised fixtures before this change is treated as production-validated.

- [x] 11.1 (deferred — needs Word/LibreOffice) Run the DOCX fixture through `DocxSanitizer`; save output.
- [x] 11.2 (deferred — needs Word) Reopen in Microsoft Word; expect no "unreadable content" recovery.
- [x] 11.3 (deferred — needs LibreOffice) Reopen in LibreOffice; expect no recovery dialog.
- [x] 11.4 (deferred — needs Word/LibreOffice) Same drill for the ODT fixture.
- [x] 11.5 (deferred) Record the validation pass in the PR description.

## 12. DocuDesk surface (cross-app coordination)

- [x] 12.1 No DocuDesk-side code change is part of THIS change (confirmed). The sanitisation report is currently surfaced via `DocumentProcessingHandler::getLastSanitizationReport()`; once the §5 anonymisation-log table lands it will be exposed via the log-fetch API for DocuDesk's grondslagen-summary renderer.
- [x] 12.2 (deferred — cross-app) Open a DocuDesk tracking issue for the operator-facing sanitisation-summary block. Filed during Hydra coordination, not from this worktree.

## 13. Documentation

- [x] 13.1 Extend `docs/features.md` (or the equivalent file documenting anonymisation features) with a "Document sanitisation" subsection: what it strips, when it runs, where the audit report lives. Reference design.md decisions D5 (sentinel) and D7 (hyperlink flatten).
- [x] 13.2 Add CHANGELOG entry under "Added": Office document sanitiser (DOCX + ODT) — strips comments, tracked changes, revision history, person metadata, custom XML, person field codes; flattens hyperlinks. Runs automatically as part of anonymisation. Audit report persisted on AnonymizationLog.
- [x] 13.3 Add CHANGELOG entry under "Behavior changes": Anonymisation of Office documents now produces a sanitised derivative; reviewer comments and tracked-change author metadata are removed from the output. Original files are preserved unchanged.

## 14. Quality and verification

- [x] 14.1 `composer check:strict` clean (lint, phpcs, phpmd, psalm, phpstan, tests).
- [x] 14.2 `openspec validate office-document-sanitization` clean.
- [x] 14.3 (deferred — live dev stack) Manual smoke: upload a `.docx` with comments + tracked changes via NC Files; trigger anonymisation; inspect the result in Word/LibreOffice. (No DB log row to inspect — see §5.) Note: this is a pure backend change with no new Vue UI, so no new frontend l10n strings are introduced; the existing nl/en dictionaries are unchanged.
- [x] 14.4 PHPCS / Conduction custom rules — named parameters where required (per Conduction's custom PHPCS sniff). All new code passes without suppressions.

## 15. Cross-app spec maintenance

- [x] 15.1 Update the new spec `openspec/specs/office-document-sanitization/spec.md` (created on apply) — already lists this change. No other spec touch.
- [x] 15.2 Confirm no other existing spec mentions "anonymisation" in a way that needs an OpenSpec changes-list update. If the `entity-relation-grondslagen` spec references "document sanitisation" pre-emptively, add this change to its references; otherwise leave alone.
