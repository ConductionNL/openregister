# Tasks: export-pdf-house-style

## 1. Reader

- [ ] 1.1 `DocumentStyleReader` with the app check, class check, container lookup and null on any failure. Verify: `tests/Unit/Service/Export/DocumentStyleReaderTest.php` with thematiq absent, disabled, throwing, and returning a profile.

## 2. PDF

- [ ] 2.1 Logo as data URI with the size and type caps, primary colours on the table header, footer lines through `page_text()`. Verify: `ExportServiceTest` renders a PDF with a fake profile and asserts the footer text and the embedded image with a PDF text and image extractor.
- [ ] 2.2 Custom fonts registered from the profile, DejaVu Sans fallback per role. Verify: the same test with a font that fails to load.
- [ ] 2.3 Without a profile the output is unchanged. Verify: a byte-level comparison of the text layer against the current output for the same data.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/export-pdf-house-style.spec.ts` on a stack with thematiq: set a document logo and footer line, export a list as PDF, and assert the footer text in the file.
- [ ] 3.2 Document the house style in PDF exports in `docs/`, naming thematiq's Documents block as the place to set it.

Acceptance:
- An export never fails because of the house style.
