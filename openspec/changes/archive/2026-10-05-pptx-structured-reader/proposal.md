---
kind: code
depends_on: []
---

# Proposal: pptx-structured-reader

## Why

A teacher who drops a PowerPoint into learniq's onboarding folder expects one lesson draft per deck, with one block per slide and the speaker notes kept as notes (learniq round 2, decision D17). OpenRegister owns the fleet's file text extraction, but it reads Word, PDF and spreadsheets as flat text for search, and it does not read `.pptx` at all. Flat text loses what makes a deck a lesson: the slide boundaries, the title of each slide, the notes and the pictures in their place.

Source: recon `learniq-mi/learniq/_round2/recon/D-ai-lessons-onboarding-styles.md`, section 4, row `pptx-structured-reader`, and open question 3 (recommendation A: in OpenRegister, next to `WordExtractor`). The competitor evidence for the consumer is proposed row C-new-7: Studytube converts "SCORM, PDF, or PPT files" into courses, and Docebo Creator drafts lessons from uploaded documents (`learniq-mi/learniq/corporate-lms/round1/documented-columns.md:294`, vendor claims). No rung is assigned: C-new-7 is a proposed row without a tier, and this is round 2 scope.

## What Changes

- A new presentation extractor next to the Word extractor. It reads a `.pptx` (and the same-format `.pptm` and `.ppsx`) into structured slides, in presentation order. Each slide carries its number, whether it is hidden, its title, its body paragraphs in shape order, its speaker notes, and its image references in shape order with their alt text.
- Garbage, corrupt or unsupported input degrades to `null` with a log line that carries no document content, the same contract as the Word extractor.
- Hostile input is bounded: an XML part with a DOCTYPE is refused, each part is read up to a size cap, the slide count is capped with a `truncated` flag, and nested group shapes are depth-limited.
- **No new composer dependency.** The brief asked for `phpoffice/phppresentation`. No tagged release can be installed next to OpenRegister today: the newest, 1.2.0, requires `phpoffice/phpspreadsheet ^1.9 || ^2.0 || ^3.0 || ^4.0`, and OpenRegister requires and patches `^5.0` (locked 5.10.0). Only the unreleased `dev-master` accepts `^5.0`. Adding it would mean a moving branch instead of a caret range, or a major downgrade of phpspreadsheet, and the brief rules out both. The extractor reads the Office Open XML package with PHP's `ZipArchive` and `DOMDocument`, which OpenRegister already uses. The public shape does not depend on the library, so the internals can switch to `phppresentation` once a release accepts phpspreadsheet 5.

## Capabilities

### New Capabilities
- `text-extraction-presentation`: structured reading of PowerPoint decks into slides with title, body, notes and image references, with graceful failure and bounded input.

### Modified Capabilities
- None. The flat-text pipeline (`text-extraction`, `text-extraction-word`) is unchanged; wiring presentations into search indexing is a follow-up.

## Impact

- `lib/Service/TextExtraction/PresentationExtractor.php` (new), resolved through Nextcloud DI like the other extractors.
- `lib/Service/TextExtraction/OoxmlPackage.php` (new): bounded read-only access to the package's parts and relationships.
- `lib/Service/TextExtraction/PresentationSlideParser.php` (new): slide, notes and slide-order XML to fields, no I/O.
- `tests/Unit/Service/TextExtraction/PresentationExtractorTest.php` (new). It builds a small deck inside the test and reads it back.
- `docs/Features/text-extraction-vectorization-ner.md`: a section on structured presentation reading, and a correction: the page listed PPTX as indexed for search, which the code never did.
- No change to `composer.json`, `composer.lock`, routes, schemas or the database.
- Consumer: learniq `office-file-lesson-onboarding` (round 2, wave 2), which resolves the extractor from the server container after the teacher confirms an import (D17). Nothing in OpenRegister calls it yet.
