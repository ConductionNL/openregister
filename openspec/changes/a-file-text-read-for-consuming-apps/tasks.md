# Tasks: a-file-text-read-for-consuming-apps

- [x] 1.1 Add `lib/Contract/FileTextReaderInterface.php` (`readFileText(File $file): ?string`, nothing stored).
- [x] 1.2 Add `lib/Files/Service/TextExtraction/FileTextReader.php` over `PdfExtractor` and `WordExtractor`, plain text read directly, other types null.
- [x] 1.3 Bind the interface in `ServiceRegistrar::registerRequestScoped()`.
- [x] 2.1 PHPUnit `tests/Unit/Files/Service/TextExtraction/FileTextReaderTest.php`: PDF, Word/ODT/extension routing, a real `.docx`, plain text, a type without a reader.
- [ ] 3.1 Publish the interface in the hydra-gates contracts copy (`.github` hydra-gates/contracts) so consumers can type against it without a guard.
