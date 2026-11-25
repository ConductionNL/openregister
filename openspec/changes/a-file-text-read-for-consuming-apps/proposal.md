## Why

Buildiq generates an app from a document a maker picks in Files (buildiq change
`ai-copilot-documents-and-code-help`, task T09, REQ-BQCH-005). It reads plain
text and Markdown itself, but PDF, Word and OpenDocument text need parsers that
OpenRegister already ships for its own search index. Without a published read,
buildiq would have to bundle the same parsers or reach into OpenRegister's
internal TextExtractionService, which stores chunks as a side effect.

## What Changes

- New published contract `OCA\OpenRegister\Contract\FileTextReaderInterface::readFileText(File $file): ?string`.
- `FileTextReader` implements it over the existing `PdfExtractor` and
  `WordExtractor`, plus plain text, and stores nothing.
- `FilesRegistrar` binds the interface for consuming apps (the Files area owns the reader; Core may not reference it).

## Impact

- Affected specs: `text-extraction` (one added requirement).
- Affected code: `lib/Contract/FileTextReaderInterface.php`,
  `lib/Files/Service/TextExtraction/FileTextReader.php`,
  `lib/Files/FilesRegistrar.php`.
- The hydra-gates package's copy of the contracts does not carry this
  interface yet; consumers guard on `interface_exists()` until it does.
