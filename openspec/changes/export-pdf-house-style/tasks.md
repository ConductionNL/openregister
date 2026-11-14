# Tasks: export-pdf-house-style

## 1. Reader

- [x] 1.1 `DocumentStyleReader` with the app check, class check, container lookup and null on any failure. Verify: `tests/Unit/Service/Export/DocumentStyleReaderTest.php` with thematiq absent, disabled, throwing, and returning a profile.

## 2. PDF

- [x] 2.1 Logo as data URI with the size and type caps, primary colours on the table header, footer lines through `page_text()`. Verify: `ExportServiceTest` renders a PDF with a fake profile and asserts the footer text and the embedded image with a PDF text and image extractor.
  - Done in the ODT pipeline that replaced Dompdf (the design's `page_text()` no longer exists): `OdtHouseStyle` reads `colours.primary` / `colours.primaryText` onto the table header and `footer.lines`, `accessibilityUrl` and `privacyUrl` into footer lines above the page number on every page; `ExportService` reads the requesting user's profile (the instance default for a table with no user). The logo: `DocumentStyleReader::logo()` reads thematiq's uploaded document logo from its asset store in-process (a token set's bundled logo from thematiq's `img/` folder), `OdtLogo` checks it (1 MB, PNG/JPEG/SVG by content, SVG without active content or outside references) and sizes it, and `OdtWriter` embeds it as `Pictures/logo.*` and draws it in the header of every page. Verified by `OdtLogoTest`, `OdtWriterTest::testALogoIsEmbeddedAndDrawnInThePageHeader`, `DocumentStyleReaderTest` and `ExportServicePdfTest::testTheProfilesLogoIsEmbeddedInThePageHeader`.
- [ ] 2.2 Custom fonts registered from the profile, DejaVu Sans fallback per role. Verify: the same test with a font that fails to load.
  - Open: thematiq stores uploaded fonts as WOFF2 only, which an ODT package cannot embed for the office server; this needs a TTF/OTF source from thematiq or a WOFF2 decoder. Until then the export keeps its default font.
- [x] 2.3 Without a profile the output is unchanged. Verify: a byte-level comparison of the text layer against the current output for the same data.
  - `OdtWriterTest::testWithoutAHouseStyleThePartsAreUnchanged` compares every ODT part with and without a null style and pins the plain colours and single footer line; `ExportServicePdfTest::testWithoutAProfileTheExportKeepsThePlainLayout`.
- [ ] 2.4 Export one record as a PDF: a record route that renders the readable fields with their labels through the same converter and house style, and an "Export as PDF" action on the record page. Verify: an `ExportServiceTest` case that exports one record with a hidden field and asserts the field is absent from the PDF text.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/export-pdf-house-style.spec.ts` on a stack with thematiq: set a document logo and footer line, export a list as PDF, and assert the footer text in the file.
- [x] 3.2 Document the house style in PDF exports in `docs/`, naming thematiq's Documents block as the place to set it.
  - `docs/features/data-import-export.md`, "House Style in PDF Exports".

Acceptance:
- An export never fails because of the house style.
