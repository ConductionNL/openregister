---
status: proposed
---

# image-anonymisation

## ADDED Requirements

### Requirement: Images and image content are detected through one seam (REQ-AIS-001)

`ImageRedactionService::detectImage()` SHALL return regions for standalone images (PNG, JPEG, TIFF, WebP), image XObjects in PDF pages and images in DOCX and ODT media. A region SHALL carry `page` (null for a standalone image), `box` (`x`, `y`, `w`, `h`, each between 0 and 1 relative to the image), `entityType`, `confidence` and `source` (`ocr-text` or the detector name and version). Text entities SHALL be located through OCR word boxes passed to `TextExtractionService::extractFromProvidedText()` as `words: [{text, page, box}]`. Each region SHALL be stored as an `EntityRelation` with `page` and `box`.

#### Scenario: a name on a scanned page gets a box
<!-- @e2e exclude Needs OCR input with positions; covered by PHPUnit ImageRedactionServiceTest::testAnOcrLocatedNameBecomesARegion. -->

- **GIVEN** an image-only PDF page whose OCR words, with boxes, contain "Jan Jansen"
- **WHEN** detection runs on the file
- **THEN** an `EntityRelation` of type PERSON is stored with page 1 and the box that covers both words

#### Scenario: an image with no OCR input cannot be called clean
<!-- @e2e exclude Server-side refusal; covered by PHPUnit ImageRedactionServiceTest::testAnImageWithoutOcrIsNotDetectable. -->

- **GIVEN** a PNG for which no OCR words were provided
- **WHEN** detection runs
- **THEN** the result says the file was not detected and names the missing OCR step, and no "no findings" result is recorded

### Requirement: Region detectors plug into the seam and an absent one is never read as run (REQ-AIS-002)

A detector SHALL implement `IImageRegionDetector::detect(string $imageBytes, ?int $page): array{regions: list<array>, detector: array{name: string, version: string}}` and be registered with the seam. The anonymiq ExApp SHALL be reached through an adapter that calls its route `POST /api/v1/image/regions` with `{image: <base64>, page}` and expects the same return keys. Every detection run SHALL record per registered detector `ran`, `not-installed` or `failed` with the reason. When no detector ran, the run SHALL record `objectDetection: not-run`.

#### Scenario: anonymiq finds a signature
<!-- @e2e exclude Needs the anonymiq ExApp; covered by PHPUnit AnonymiqRegionDetectorTest::testRegionsFromTheExAppBecomeEntityRelations with a recorded response, and by anonymiq's own contract test. -->

- **GIVEN** the anonymiq ExApp installed and returning one SIGNATURE region for page 2
- **WHEN** detection runs on the PDF
- **THEN** an `EntityRelation` of type SIGNATURE is stored for page 2 with the returned box and `source` naming anonymiq and its version

#### Scenario: no detector installed
<!-- @e2e exclude Absent-app path; covered by PHPUnit ImageRedactionServiceTest::testAnAbsentDetectorIsRecordedAsNotRun. -->

- **GIVEN** anonymiq not installed
- **WHEN** detection runs on an image
- **THEN** the run records `objectDetection: not-run` with the reason `not-installed`

### Requirement: Decided regions are burned into the pixels (REQ-AIS-003)

`ImageRedactionService::redactImage()` SHALL paint every region whose `EntityRelation` is not marked `skipAnonymization` as an opaque fill into the pixel data, re-encode the image, and strip EXIF, XMP, IPTC and embedded thumbnails. It SHALL NOT draw an overlay, annotation or separate layer. For a PDF it SHALL replace each affected image XObject with the burned raster. For DOCX and ODT it SHALL replace the affected media file. The output SHALL be the `_anonymized` sibling the text path writes. After burning, every burned region SHALL be uniform fill when read back, and an OCR re-read supplied by the caller, when present, SHALL NOT contain any redacted value.

#### Scenario: a photographed form is redacted
- **GIVEN** a JPEG with a decided PERSON region
- **WHEN** the officer anonymises the file
- **THEN** an `_anonymized` JPEG is written whose region pixels are one fill colour, with no EXIF or XMP

#### Scenario: an image-only PDF page is redacted
<!-- @e2e exclude Byte-level check on the PDF; covered by PHPUnit ImageRedactionServiceTest::testAnImageOnlyPdfPageIsBurnedNotOverlaid. -->

- **GIVEN** a scanned PDF with a decided region on page 1
- **WHEN** it is anonymised
- **THEN** the page's image XObject is replaced by a burned raster, the original image stream is absent from the output, and no annotation is added

### Requirement: Every redaction path reaches the seam (REQ-AIS-004)

`FileService::anonymizeDocument()` SHALL send image files and the image content of PDF and office files through `redactImage()` in the same call that handles their text. A path that calls `FileService::anonymizeDocument()` directly, as opencatalogi's `DocumentRedactor` does, SHALL get the same result as `POST /api/files/{id}/anonymize`.

#### Scenario: opencatalogi's path burns images too
<!-- @e2e exclude Service-level call path; covered by PHPUnit FileServiceImageRouteTest::testADirectServiceCallBurnsImageRegions. -->

- **GIVEN** a PDF with a decided text entity and a decided image region
- **WHEN** `FileService::anonymizeDocument()` is called the way `DocumentRedactor` calls it
- **THEN** the text is replaced and the region is burned in one output file
