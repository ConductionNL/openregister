## 1. Value objects and exception

- [ ] 1.1 Create `lib/Service/File/SanitizationReport.php` — immutable value object with readonly properties: `commentsRemoved: int`, `trackedChangesAccepted: int`, `trackedChangesDropped: int`, `revisionAttributesStripped: int`, `hyperlinksFlattened: int`, `metadataFieldsScrubbed: int`, `customXmlPartsDropped: int`, `fieldCodesStripped: int`, `sentinelApplied: string`. Implement `JsonSerializable` returning the keys in the same order.
- [ ] 1.2 Create `lib/Service/File/SanitizationResult.php` — value object with `path: string` (sanitised temp file) and `report: SanitizationReport`.
- [ ] 1.3 Create `lib/Exception/SanitizationException.php` extending `\Exception`. Add a public-readonly `reason: string` field (`unsupported-mime` | `encrypted` | `corrupt-zip` | `internal`) for typed catch-handling. Exception messages MUST NOT include filename or content (ADR-005); only the reason code and structural detail.
- [ ] 1.4 Create the `SentinelStrings` constant holder (or place constants directly on `OfficeDocumentSanitizer`): `SENTINEL = 'DocuDesk Anonymisation'`. Document via a code comment that this is intentionally a tool brand (per design D5).

## 2. Strategy interface and orchestrator

- [ ] 2.1 Create `lib/Service/File/Sanitizer/SanitizerInterface.php`:
  ```php
  interface SanitizerInterface {
      public function supports(string $mimeType): bool;
      public function sanitize(string $sourcePath, string $destPath): SanitizationReport;
  }
  ```
- [ ] 2.2 Create `lib/Service/File/OfficeDocumentSanitizer.php` — orchestrator. Constructor: `IRootFolder $rootFolder, ITempManager $tempManager, LoggerInterface $logger, SanitizerInterface[] $strategies` (DI-injected list of strategies).
- [ ] 2.3 Implement `OfficeDocumentSanitizer::sanitize(int $fileId): SanitizationResult`:
    1. Resolve `$file = $this->rootFolder->getById($fileId)[0]` (with empty-result check → throw `SanitizationException` reason `unsupported-mime`).
    2. Read `$mimeType = $file->getMimeType()`.
    3. Find a matching strategy via `$strategy->supports($mimeType)` → first match wins; no match throws `SanitizationException` reason `unsupported-mime`.
    4. Allocate a temp file via `$tempManager->getTemporaryFile($extensionForMime)`.
    5. `copy($file->fopen('r'), $tempPath)` (or NC equivalent — `fopen → stream_copy_to_stream`).
    6. Call `$report = $strategy->sanitize($tempPath, $tempPath)`.
    7. Log a single info-level line with `fileId`, `mimeType`, `strategy class`, and the counts (ADR-005 compliant — counts only, no content).
    8. Return `new SanitizationResult($tempPath, $report)`.

## 3. DocxSanitizer implementation

- [ ] 3.1 Create `lib/Service/File/Sanitizer/DocxSanitizer.php` implementing `SanitizerInterface`. `supports()` matches `application/vnd.openxmlformats-officedocument.wordprocessingml.document`.
- [ ] 3.2 Implement `sanitize($sourcePath, $destPath)` using `ZipArchive`. If source != dest, copy first. Open with `ZipArchive::open($destPath, ZipArchive::RDONLY)` — read all part names; then re-open writeable. On open failure code matching encryption (`ZipArchive::ER_NOZIP`, `ZipArchive::ER_INVAL` paired with detected encryption magic bytes), throw `SanitizationException` reason `encrypted`.
- [ ] 3.3 **Comment removal** — implement helper `removeComments(ZipArchive $zip, SanitizationReport $report): void`:
    - Read `word/comments.xml`; count distinct `<w:comment w:id="...">` elements; record count.
    - Delete `word/comments.xml`, `word/commentsExtended.xml`, `word/commentsIds.xml`, `word/people.xml` from the zip.
    - Parse `[Content_Types].xml`; remove `<Override PartName="/word/comments.xml" .../>` (and the three sibling parts).
    - Parse `word/_rels/document.xml.rels`; remove `<Relationship>` entries whose `Target` is any of `comments.xml`, `commentsExtended.xml`, `commentsIds.xml`, `people.xml`.
    - For each XML part `word/document.xml`, `word/header*.xml`, `word/footer*.xml`, `word/footnotes.xml`, `word/endnotes.xml`: load as `DOMDocument`; XPath remove `//w:commentRangeStart`, `//w:commentRangeEnd`, `//w:commentReference`; serialise back.
- [ ] 3.4 **Tracked-change resolution** — implement helper `acceptTrackedChanges(ZipArchive $zip, SanitizationReport $report): void`:
    - For each XML body-bearing part: load as DOMDocument; for each `<w:ins>` element, replace it with its child nodes (unwrap); for each `<w:del>` element, remove it entirely. Increment `trackedChangesAccepted` per ins, `trackedChangesDropped` per del.
    - Final attribute-strip pass: walk all elements; remove attributes `w:rsidR`, `w:rsidRPr`, `w:rsidDel`, `w:rsidTr`, `w:rsidP`. Increment `revisionAttributesStripped` per attribute removed.
- [ ] 3.5 **Custom XML strip** — implement helper `stripCustomXml(ZipArchive $zip, SanitizationReport $report): void`:
    - Enumerate all part paths matching regex `^customXml/item\d*\.xml$` or `^customXml/itemProps\d*\.xml$`. Count logical parts (one per `item<n>.xml`).
    - Delete the matching parts from the zip.
    - Remove their `<Override>` entries from `[Content_Types].xml`.
    - Remove `<Relationship>` entries in `word/_rels/document.xml.rels` targeting `customXml/*`.
    - For each body-bearing XML part: find `<w:sdt>` elements with a `<w:sdtPr>/<w:dataBinding>` descendant; replace each `<w:sdt>` with the child nodes of its `<w:sdtContent>` (unwrap-preserving-visible-content).
- [ ] 3.6 **Metadata scrub** — implement helper `scrubMetadata(ZipArchive $zip, SanitizationReport $report): void`:
    - Parse `docProps/core.xml`; for each of `dc:creator`, `cp:lastModifiedBy`, `dc:title`, `dc:subject`, `cp:keywords`, `dc:description`, `cp:category`, `cp:contentStatus`: if the element exists with non-empty content, replace its text content with the sentinel and increment `metadataFieldsScrubbed`.
    - Parse `docProps/app.xml`; same treatment for `Company`, `Manager`.
    - Parse `docProps/custom.xml` (if present); for each `<property>` containing `<vt:lpwstr>` or `<vt:lpstr>`, replace the inner string with sentinel and increment counter. Skip non-string-typed properties (`<vt:i4>`, `<vt:bool>`, etc.) — leave unchanged.
    - Set `SanitizationReport.sentinelApplied = OfficeDocumentSanitizer::SENTINEL`.
- [ ] 3.7 **Field-code strip** — implement helper `stripFieldCodes(ZipArchive $zip, SanitizationReport $report): void`:
    - Strip-list = `['AUTHOR', 'USERNAME', 'USERINITIALS', 'LASTSAVEDBY']` (case-insensitive comparison).
    - For each body-bearing XML part: load DOMDocument.
    - Simple form: XPath `//w:fldSimple[@w:instr]`. For each, normalise `w:instr` attribute (trim, uppercase, strip backslash-flags after the field name). If the field name is in the strip list, remove the element. Increment `fieldCodesStripped`.
    - Complex form: walk children of paragraphs sequentially. Find `<w:r>` containing `<w:fldChar w:fldCharType="begin"/>`. Read forward until the next `<w:fldChar w:fldCharType="separate"/>`, collecting any `<w:instrText>` content. Normalise the collected instruction. If it's in the strip list, remove all runs from begin through the matching `end` fldChar (inclusive). Increment counter once per field.
- [ ] 3.8 **Hyperlink flatten** — implement helper `flattenHyperlinks(ZipArchive $zip, SanitizationReport $report): void`:
    - For each body-bearing XML part: load DOMDocument.
    - For each `<w:hyperlink>` element: read `r:id` (if any). Replace the element with its `<w:r>` children. Increment counter.
    - Collect the set of `r:id` values seen.
    - Open the rels file paired with the part (`word/_rels/<part>.rels`); remove `<Relationship>` entries whose `Id` is in the collected set AND whose `Type` ends with `/hyperlink`.
- [ ] 3.9 Orchestrate: in `sanitize()`, call helpers in order: comments → tracked-changes → custom-xml → metadata → field-codes → hyperlinks. Each writes back to the zip via `ZipArchive::addFromString` (replacing the part). `ZipArchive::close()` commits.
- [ ] 3.10 Return the populated `SanitizationReport`.

## 4. OdtSanitizer implementation

- [ ] 4.1 Create `lib/Service/File/Sanitizer/OdtSanitizer.php` implementing `SanitizerInterface`. `supports()` matches `application/vnd.oasis.opendocument.text`.
- [ ] 4.2 Open the ODT zip via `ZipArchive`. ODT zip-encryption detection: `ZipArchive::open` returns specific error codes for encrypted entries; throw `SanitizationException` reason `encrypted`.
- [ ] 4.3 **Comment removal** — load `content.xml` as DOMDocument. XPath remove `//office:annotation` and `//office:annotation-end`. Increment `commentsRemoved` per `office:annotation` removed.
- [ ] 4.4 **Tracked-change resolution** — locate the `text:tracked-changes` container in `content.xml`. Parse the contained `text:changed-region` entries to build an accept/reject map by `text:id`. Then walk `content.xml`:
    - `text:change-start` + matching `text:change-end` around an inserted range: remove the start/end markers only; keep the content between them. Increment `trackedChangesAccepted`.
    - `text:change` (delete marker — used for deleted ranges that are not rendered in the current view): the marker references a `text:id`; look up the changed-region's content via `text:deletion`; remove the marker AND any inline rendering of the deleted text. Increment `trackedChangesDropped`.
    - Remove the `text:tracked-changes` container element itself after processing.
- [ ] 4.5 **Metadata scrub** — load `meta.xml`. For each of `dc:creator`, `meta:initial-creator`, `dc:title`, `dc:subject`, `meta:keyword`, `dc:description`: replace text content with sentinel; increment counter.
    - For each `meta:user-defined` element: if `meta:value-type` attribute is absent OR equals `string`, replace text content with sentinel; increment counter. Otherwise preserve unchanged.
- [ ] 4.6 **Hyperlink flatten** — XPath `//text:a` in `content.xml`. For each, replace with its child nodes (text + spans). Increment counter. (No rels file equivalent — the href was inline.)
- [ ] 4.7 **Person-identity placeholder removal** — XPath remove `//text:author-name`, `//text:author-initials`, `//text:initial-creator` in `content.xml`. Increment `fieldCodesStripped` per element removed (re-using the DOCX counter; ODT terminology is "placeholder" but the report key stays uniform).
- [ ] 4.8 Set `sentinelApplied`; close the zip; return report.

## 5. AnonymizationLog persistence

- [ ] 5.1 Add migration `lib/Migration/VersionXXXXXX/AddSanitizationColumn.php` adding a `sanitization` JSON column (nullable, default null) to the anonymisation log table.
- [ ] 5.2 Update `lib/Db/AnonymizationLog.php` (or whatever the equivalent entity class is named in the current code) to add a `sanitization: ?array` property, getter, setter, and `setSanitization($report->jsonSerialize())` integration.
- [ ] 5.3 If the project uses a mapper-based persistence pattern for this log, update the mapper to (a) read JSON column → array, (b) write array → JSON column. If the JSON-column adapter is already generic, no mapper change.

## 6. DocumentProcessingHandler integration

- [ ] 6.1 In `lib/Service/File/DocumentProcessingHandler.php::anonymizeDocument`, after the file is resolved and before the walker pass:
    - Read `$mimeType = $file->getMimeType()`.
    - If `OfficeDocumentSanitizer::isSanitizable($mimeType)` (a public helper that mirrors strategy `supports()` across all strategies): call `$result = $this->sanitizer->sanitize($fileId)`. Catch `SanitizationException`:
        - `reason: encrypted` → translate to an `AnonymizationException` ("cannot anonymise encrypted document"); abort.
        - other reasons → log and rethrow.
    - Use `$result->path` as the working file for the rest of the pipeline (walker pass).
    - Persist `$result->report->jsonSerialize()` onto the AnonymizationLog row created for this anonymisation.
- [ ] 6.2 For non-Office MIMEs (PDF, plain text, etc.), the existing pipeline runs unchanged. The `sanitization` column stays null.
- [ ] 6.3 Lifecycle: after the walker writes the anonymised output to its destination, the temp file from `SanitizationResult.path` is no longer needed. `ITempManager` auto-cleans at request end; explicit `unlink` not required.

## 7. DI wiring

- [ ] 7.1 Register `OfficeDocumentSanitizer` in `lib/AppInfo/Application.php` (or wherever DI is configured for services). Register `DocxSanitizer` and `OdtSanitizer` as factories that produce instances; bind them as the `$strategies` array on `OfficeDocumentSanitizer` constructor.
- [ ] 7.2 Inject `OfficeDocumentSanitizer` into `DocumentProcessingHandler` constructor.

## 8. Test fixtures

- [ ] 8.1 Create `tests/Fixtures/sanitization/sample.docx` containing: 3 comments, 2 accepted-insert tracked-change groups, 2 dropped-delete tracked-change groups, 1 `customXml/item1.xml` with a bound `<w:sdt>`, 1 external hyperlink with rels entry, 1 internal-anchor hyperlink, 1 simple-form AUTHOR field, 1 complex-form USERNAME field, populated metadata (`dc:creator`, `dc:title`, `dc:subject`, `cp:keywords`).
- [ ] 8.2 Create `tests/Fixtures/sanitization/sample.odt` containing the ODT equivalent: 3 `office:annotation` elements, tracked-changes with both accepts and deletes, populated `meta.xml`, 1 `text:a` hyperlink, 1 `text:author-name` placeholder.

## 9. Unit tests

- [ ] 9.1 `tests/unit/Service/File/Sanitizer/DocxSanitizerTest.php` — cover each Requirement / Scenario from the spec:
    - Comments removed from comments part, content-types, rels, and inline references.
    - Inserts accepted (inner content preserved); deletes dropped.
    - Revision attributes stripped from runs / paragraphs.
    - Custom XML parts removed; data-bound sdt unwrapped preserving visible content.
    - Metadata scrubbed to sentinel; timestamps preserved; non-string custom properties preserved.
    - AUTHOR / USERNAME / USERINITIALS / LASTSAVEDBY fields stripped (both forms); DATE preserved.
    - Hyperlinks flattened; rels entry removed; internal anchor flattened with no rels entry to remove.
    - Each counter on `SanitizationReport` matches the fixture's expected counts.
    - Encrypted DOCX raises `SanitizationException` reason `encrypted`.
- [ ] 9.2 `tests/unit/Service/File/Sanitizer/OdtSanitizerTest.php` — cover Requirements / Scenarios for ODT.
- [ ] 9.3 `tests/unit/Service/File/OfficeDocumentSanitizerTest.php` — orchestrator: dispatch to correct strategy per MIME; unsupported MIME raises; original file in NC Files is byte-identical post-call (using a mock IRootFolder).
- [ ] 9.4 `tests/unit/Service/File/SanitizationReportTest.php` — `jsonSerialize()` produces the expected key set.
- [ ] 9.5 PII-redacted logging test: induce a sanitiser failure mid-process; capture the log line; assert no document content appears in it (only file ID, MIME, strategy, exception class, structural detail).

## 10. Integration tests

- [ ] 10.1 `tests/integration/.../DocumentProcessingHandlerSanitizationTest.php` — upload `tests/Fixtures/sanitization/sample.docx` to NC Files via a test helper; trigger anonymisation via the existing entry point; assert:
    - The anonymised output is written.
    - The original NC file is byte-identical to the pre-call state.
    - The AnonymizationLog row for this run has the `sanitization` column populated with non-null JSON containing all expected counters.
- [ ] 10.2 Same flow for `sample.odt`.
- [ ] 10.3 Integration test for the non-Office path: upload a PDF; trigger anonymisation; verify `sanitization` column is null.

## 11. Manual validation gate (BLOCKING)

- [ ] 11.1 Take `tests/Fixtures/sanitization/sample.docx`. Run it through `DocxSanitizer` via a small CLI script or PHPUnit invocation. Save the output to disk.
- [ ] 11.2 Open the output in **Microsoft Word** (current channel — Office 365 current OR LTSC current). Required: opens without "found unreadable content / want to recover?" dialog. Visible content matches pre-sanitisation minus stripped structures.
- [ ] 11.3 Open the same output in **LibreOffice** (24.x or newer). Required: opens without recovery dialog.
- [ ] 11.4 Take `tests/Fixtures/sanitization/sample.odt`. Same drill: run through `OdtSanitizer`, open in LibreOffice AND Word (Word reads ODT but is the more sensitive reader). Required: both open cleanly.
- [ ] 11.5 Record the validation pass in the PR description (Word version, LibreOffice version, screenshots if practical). Do NOT mark tasks 3 / 4 done until this gate passes.

## 12. DocuDesk surface (cross-app coordination)

- [ ] 12.1 No DocuDesk-side code change is part of THIS change. The sanitisation data lands on the AnonymizationLog row and is exposed via the existing log-fetch API (`GET /api/objects/{id}/anonymization-log` or equivalent). DocuDesk's grondslagen-summary renderer can pick it up in a follow-up.
- [ ] 12.2 Open a tracking issue in DocuDesk for the operator-facing summary block ("Sanitisation summary: 12 comments removed, 7 tracked-change groups accepted, …"). Reference this OR change.

## 13. Documentation

- [ ] 13.1 Extend `docs/features.md` (or the equivalent file documenting anonymisation features) with a "Document sanitisation" subsection: what it strips, when it runs, where the audit report lives. Reference design.md decisions D5 (sentinel) and D7 (hyperlink flatten).
- [ ] 13.2 Add CHANGELOG entry under "Added": Office document sanitiser (DOCX + ODT) — strips comments, tracked changes, revision history, person metadata, custom XML, person field codes; flattens hyperlinks. Runs automatically as part of anonymisation. Audit report persisted on AnonymizationLog.
- [ ] 13.3 Add CHANGELOG entry under "Behavior changes": Anonymisation of Office documents now produces a sanitised derivative; reviewer comments and tracked-change author metadata are removed from the output. Original files are preserved unchanged.

## 14. Quality and verification

- [ ] 14.1 `composer check:strict` clean (lint, phpcs, phpmd, psalm, phpstan, tests).
- [ ] 14.2 `openspec validate office-document-sanitization` clean.
- [ ] 14.3 Manual smoke against the dev stack: upload a `.docx` with comments + tracked changes via NC Files; trigger anonymisation; inspect the resulting file in Word/LibreOffice; inspect the AnonymizationLog row in the DB.
- [ ] 14.4 PHPCS / Conduction custom rules — named parameters where required (per Conduction's custom PHPCS sniff). All new code passes without suppressions.

## 15. Cross-app spec maintenance

- [ ] 15.1 Update the new spec `openspec/specs/office-document-sanitization/spec.md` (created on apply) — already lists this change. No other spec touch.
- [ ] 15.2 Confirm no other existing spec mentions "anonymisation" in a way that needs an OpenSpec changes-list update. If the `entity-relation-grondslagen` spec references "document sanitisation" pre-emptively, add this change to its references; otherwise leave alone.
