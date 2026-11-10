---
kind: code
depends_on: []
---

# Proposal: docx-structured-reader

## Why

A teacher who drops a Word file into learniq's onboarding folder gets one lesson draft with a text block per heading section (learniq round 2, decision D17). OpenRegister owns the fleet's file text extraction, but its `WordExtractor` returns one flat string for search, so heading levels, lists, tables and images are lost. Learniq therefore reads the docx package itself with `ZipArchive` (`DocxLessonReader`, learniq PR 1080), a second copy of the package reading that OpenRegister already does for PowerPoint (`PresentationExtractor`, openregister #4077). That copy has no DOCTYPE check on the parsed tree, trusts the size the zip directory claims, and reads text boxes twice. The structure belongs next to the other extractors, where every consumer gets the same bounded reader.

Source: learniq PR 1080 (`feat/office-file-lesson-onboarding`, merged), design decision "`WordExtractor` drops heading levels and images, so docx structure is read in learniq; the reader sits behind one method and can move to OpenRegister (named follow-up)". Recon `learniq-mi/learniq/_round2/recon/D-ai-lessons-onboarding-styles.md`, section 1, row "Generic file-event to async text-extraction pipeline": `WordExtractor.php` reads docx "for flat text (search/indexing use, not structure)". The competitor evidence for the consumer is proposed row C-new-7: Studytube converts uploaded files into courses and Docebo Creator drafts lessons from uploaded documents (`learniq-mi/learniq/corporate-lms/round1/documented-columns.md:294`, vendor claims). No rung is assigned: this is the named follow-up of a round 2 change, round 3 scope.

## What Changes

- A new document extractor next to the Word and presentation extractors. It reads a `.docx` (and the same-format `.docm`, `.dotx` and `.dotm`) into structure: a title, sections that each start at a heading and carry its level, and under each heading its paragraphs, lists (items with level and numbered or bulleted), tables as rows of cell text, and image references, all in document order.
- The result also carries the flat text exactly as the Word extractor returns it today, so search and structure agree and a consumer needs one call.
- Garbage, corrupt or unsupported input (legacy `.doc`, `.odt`) degrades to `null` with a log line that carries no document content, the same contract as the other extractors.
- Hostile input is bounded the way the presentation extractor bounds it: an XML part with a DOCTYPE is refused, each part is read up to a size cap, the number of blocks is capped with a `truncated` flag, and nesting (content controls, nested tables, text boxes) is depth-limited.
- The bounded package reader from the presentation change (`OoxmlPackage`) is reused as is; only its header comment names the second user.
- No new composer dependency. The flat text still comes from `phpoffice/phpword` through `WordExtractor`; the structure is read with `ZipArchive` and `DOMDocument`, as in #4077.

## Capabilities

### New Capabilities
- `text-extraction-document`: structured reading of Word documents into a title and heading sections with paragraphs, lists, tables and image references, plus the flat text, with graceful failure and bounded input.

### Modified Capabilities
- None. The flat-text pipeline (`text-extraction`, `text-extraction-word`) and the presentation reader are unchanged.

## Impact

- `lib/Service/TextExtraction/DocumentExtractor.php` (new), resolved through Nextcloud DI like the other extractors; it takes the existing `WordExtractor` for the flat text.
- `lib/Service/TextExtraction/DocumentBodyParser.php` (new): body XML to sections and blocks, no I/O.
- `lib/Service/TextExtraction/DocumentContentReader.php` (new): one paragraph's text, pictures and text boxes, and one table's rows, no I/O.
- `lib/Service/TextExtraction/DocumentStyleMap.php` (new): heading, title and list resolution from the styles and numbering parts, no I/O.
- `lib/Service/TextExtraction/OoxmlElements.php` (new): local-name lookups shared by the readers.
- `lib/Service/TextExtraction/OoxmlPackage.php`: header comment only.
- `tests/Unit/Service/TextExtraction/DocumentExtractorTest.php` (new). It builds small documents inside the test, one of them in the shape LibreOffice writes.
- `docs/Features/text-extraction-vectorization-ner.md`: a section on structured document reading next to the presentation section.
- No change to `composer.json`, `composer.lock`, routes, schemas or the database.
- Consumer: learniq `office-file-lesson-onboarding` (merged in PR 1080) can replace `DocxLessonReader` with this extractor in a follow-up; nothing in OpenRegister calls it yet. Learniq keeps reading image bytes by the returned package path, as it does for decks.
