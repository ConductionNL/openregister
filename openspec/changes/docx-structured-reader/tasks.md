## 1. Reader

- [x] 1.1 Add `lib/Service/TextExtraction/DocumentStyleMap.php` (heading level, title and list numbering from the styles and numbering parts, `basedOn` chain bounded and cycle-safe) and `OoxmlElements.php` (local-name lookups); verify `php -l`, `phpcs` and `phpstan` report nothing new on the files
- [x] 1.2 Add `lib/Service/TextExtraction/DocumentBodyParser.php` (sections and typed blocks in document order, block cap with `truncated`, depth cap) and `DocumentContentReader.php` (one pass per paragraph skipping `mc:Fallback` and `w:txbxContent`, text boxes walked once, tables as rows, image blocks); verify with the tests in 2.1
- [x] 1.3 Add `lib/Service/TextExtraction/DocumentExtractor.php` with the `PresentationExtractor` shape (`supports()`, `extract(File): ?array`, zip guard, null plus content-free log per document), reusing `OoxmlPackage` and taking `WordExtractor` for the flat text with the structure as fallback; verify `php -l`, `phpcs`, `phpstan` and `phpmd` report nothing new

## 2. Tests

- [x] 2.1 Add `tests/Unit/Service/TextExtraction/DocumentExtractorTest.php` that builds documents inside the test (headings of two levels and a localised style id, preamble, title paragraph and core title, split runs, a text box stored twice, a tracked deletion, bulleted and numbered lists with a nested item and style numbering, a table, embedded and linked pictures, hostile parts) and asserts every spec scenario; verify `vendor/bin/phpunit --no-coverage --filter DocumentExtractorTest` passes
- [x] 2.2 Add a LibreOffice-shaped document to the test (heading styles with outline numbering and format `none`, `TextBody` paragraphs, direct list numbering, a text frame in `mc:AlternateContent`, an anchored picture) and assert headings stay headings and nothing is read twice; verify the same filter passes
- [x] 2.3 Cross-check locally against a document written by python-docx (Word's default template, not committed) and verify headings, lists, the table and the flat text read back as expected

## 3. Docs and verification

- [x] 3.1 Add a "Structured document reading" section to `docs/Features/text-extraction-vectorization-ner.md` next to the presentation section; verify the section names the result fields, the bounds and the formats
- [ ] 3.2 Run `composer check:strict`, `npm run lint` and the hydra gates once before push, and record each exit code in the PR body
