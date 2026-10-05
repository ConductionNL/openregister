---
kind: code
depends_on: []
---

# Proposal: anonymisation-image-seam

## Why

Row 4.13, "Formats other than PDF are redacted, including office files and images", is `partial` in our column (`baseline/openwoo.tsv`): OpenRegister redacts PDF text (`PdfTextReplacer`) and office text (`DocxSanitizer`, `OdtSanitizer`), and has no image redaction path at all. A scanned letter, a photo of a signed form or an image pasted into a Word file goes out as it came in.

Decision D6 (Ruben, 2026-10-05) gave this an owner split: OpenRegister owns the seam every redaction path uses, and anonymiq owns the vision model (`anonymiq/object-detection-in-page-images`, row 4.20, wave 2). Decision D5 keeps filinq's `image-redaction` scope for now: signatures in, faces and plates out until a detector exists, which is what the anonymiq change adds through this seam. OpenRegister has no OCR of its own; OCR text reaches it through `TextExtractionService::extractFromProvidedText()` (#2033), so this change lets that input carry word positions.

## What changes

- `ImageRedactionService::detectImage(File $file): list<ImageRegion>` returns regions with a page number and a box normalised to 0..1, from two sources: detected text entities located through OCR word boxes, and region detectors registered on the seam.
- `extractFromProvidedText()` accepts an optional `words` list (`text`, `page`, `box`), so an OCR provider (filinq's seam) hands over positions, and detected text entities map to boxes.
- `IImageRegionDetector` is the seam a detector implements. anonymiq is reached through it as an AppAPI ExApp call; a detector that is not installed is reported as not run, never as run.
- Every region becomes an `EntityRelation` with `page` and `box`, so it goes through the same operator decision and review as a text finding.
- `ImageRedactionService::redactImage(File $file, list<ImageRegion> $regions): File` burns the decided regions into the pixels of standalone images (PNG, JPEG, TIFF, WebP), image XObjects inside PDF pages (including image-only pages) and images inside DOCX and ODT media folders, strips EXIF and XMP, and writes the `_anonymized` sibling as the text path does.
- `FileService::anonymizeDocument()` routes images and image content through the seam, so `POST /api/files/{id}/anonymize`, opencatalogi's `DocumentRedactor` and filinq all get it.

## What does not change

- The text paths (`PdfTextReplacer`, the office sanitisers).
- Which objects a detector finds: anonymiq's change decides that (4.20).
- filinq's page and range redaction in one action (4.24, `filinq/image-redaction`, wave 2, depends on this change).

## Dependencies and absent apps

- Consumed by `anonymiq/object-detection-in-page-images` and `filinq/image-redaction` (both wave 2).
- anonymiq absent: only OCR-located text regions are found, and every run records `objectDetection: not-run` with the reason, so nobody reads a clean result as "no faces".
- No OCR text with positions for an image: the file cannot be detected, and anonymising it is refused with a message that names the missing OCR step. An image is never reported as redacted when nothing could look at it.

## Wave and decision

Wave 1, size L. Implements D6 (the seam is OpenRegister's, the detector anonymiq's) and honours D5 (faces and plates come only from a detector). Closes 4.13.
