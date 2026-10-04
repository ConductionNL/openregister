## Read this first: no new dependency

The brief asked for `phpoffice/phppresentation` as a new composer dependency, with a caret range and no major bump of anything else. That cannot be done today:

```
$ composer require "phpoffice/phppresentation:^1.2" --dry-run
  - phpoffice/phppresentation 1.2.0 requires phpoffice/phpspreadsheet ^1.9 || ^2.0 || ^3.0 || ^4.0
    ... but it conflicts with your root composer.json require (^5.0).
```

1.2.0 is the newest tag. OpenRegister requires phpspreadsheet `^5.0` (locked 5.10.0) and patches it (`patches/phpspreadsheet-zipstream3-prefer.patch`). Only the unreleased `dev-master` of phppresentation accepts `^5.0`. So the options were a moving branch instead of a caret range, or a major downgrade of a patched dependency; the brief ruled out both.

This PR reads the pptx package with `ZipArchive` and `DOMDocument`, which OpenRegister already uses. `composer.json` and `composer.lock` are untouched. The result shape does not expose the parser, so the internals can switch to phppresentation once a release accepts phpspreadsheet 5. If you would rather wait for that release than carry this reader, close this PR and say so.

## Scope

A `PresentationExtractor` next to `WordExtractor` reads a PowerPoint deck (`.pptx`, `.pptm`, `.ppsx`) into structured slides instead of flat text. Each slide, in deck order, carries its number, hidden flag, title, body paragraphs in shape order (groups and table cells included), speaker notes, and image references with alt text; failures degrade to `null` without logging content.

## Where it comes from

- Learniq round 2, recon D (`learniq-mi/learniq/_round2/recon/D-ai-lessons-onboarding-styles.md`), section 4, row `pptx-structured-reader`, and open question 3 (recommendation A: OpenRegister, next to `WordExtractor`).
- Decision D17: learniq detects a dropped Word or PowerPoint file, and extraction happens only after the teacher confirms. The consumer is learniq `office-file-lesson-onboarding` (wave 2). Nothing in OpenRegister calls the extractor yet.
- Competitor evidence for the consumer: proposed row C-new-7 (`learniq-mi/learniq/corporate-lms/round1/documented-columns.md:294`: Studytube turns "SCORM, PDF, or PPT files" into courses; Docebo Creator drafts lessons from uploaded documents; vendor claims). No rung: C-new-7 is a proposed row without a tier.

## What it does

- `PresentationExtractor`: WordExtractor's shape (logger-only constructor, `extract(File): ?array`, a throw when the zip extension is missing), plus `supports(mimeType, fileName)`.
- `OoxmlPackage`: bounded, read-only part and relationship access. Each part is read up to 20 MiB, never trusting the size the zip directory claims. A part that declares a DOCTYPE is refused before parsing (UTF-8) and after parsing (UTF-16). Relationship targets resolve relative to their part, and a target that climbs out of the package is flagged external, never resolved.
- `PresentationSlideParser`: slide order from the presentation's slide list (not file names), titles from title placeholders, notes from every text shape on the notes page except page furniture, a single branch of each markup-compatibility block, group descent capped at depth 20. Elements are matched by local name, so transitional and strict OOXML read the same.
- A deck stops at 500 slides with `truncated: true`.
- Docs: a "Structured presentation reading" section in `docs/Features/text-extraction-vectorization-ner.md`, and a correction there. The page listed PPTX as indexed for search; `TextExtractionService` has no presentation branch, so that was never true.

## Verification (exit codes)

- `composer require "phpoffice/phppresentation:^1.2" --dry-run`: failed to resolve (quoted above); `git diff --quiet composer.json composer.lock`: 0
- `php -l` on the three new classes and the test: 0
- `vendor/bin/phpcs --standard=phpcs.xml --warning-severity=0` on the three new classes: 0
- `vendor/bin/phpstan analyse` on the three new classes: 0 (`[OK] No errors`)
- `vendor/bin/phpmd` (repo ruleset and unused-params ruleset) on each new class, cold pdepend cache: 0 findings
- `vendor/bin/phpunit --filter PresentationExtractorTest`: 0 (28 tests)
- `vendor/bin/phpunit tests/Unit/Service/TextExtraction/` on the final tree (`451814f9`): 0 (252 tests, 607 assertions)
- `composer check:strict` (lane `TMPDIR`, isolated `HOME` so phpmd starts from a cold pdepend cache): 1. lint, check:migration-version, phpcs, phpmd, psalm (`No errors found!`) and phpstan (`[OK] No errors`) all passed. `test:all` ran 24109 tests with 1 failure, which is environmental; see Inherited findings.
- `npm run lint`: 0 (932 inherited warnings, no JS touched)
- `npm run format`: 0
- `npm run test:l10n`: 0
- `run-hydra-gates.sh --scope-to-diff --base origin/development`: 0 (36 pass, 58 not applicable under diff scope). The first run flagged gate 16 on `OoxmlPackage::refusedParts()` (no `@spec`), fixed in `7f335da9`; rerun on `451814f9`: 0.
- `openspec validate pptx-structured-reader`: valid

## Real-world cross-check (local only, nothing committed)

Three decks written by real tools were read back through the extractor:

- A deck built by python-pptx 1.0.2 on PowerPoint's default template (title and content layouts, a table, a picture with alt text, notes, a hidden slide): every field correct.
- The same deck round-tripped through LibreOffice 24.2.7.2 (`soffice --convert-to pptx`): every field correct.
- A deck LibreOffice exported from a hand-written ODP. This found a real defect the hand-built fixtures could not: LibreOffice writes speaker notes as a plain text box, not a `body` placeholder, so the first version returned empty notes. Fixed; the notes rule now takes every text shape on the notes page except furniture, and `testNotesWrittenAsAPlainTextBoxAreRead` pins it. That deck's slides carry no title placeholder at all, so their titles come back in `body`, which is faithful to the file.

Two mutations were also checked: removing the post-parse DOCTYPE guard turns the UTF-16 test red, and reading both markup-compatibility branches turns the groups test red.

## Inherited findings

- `MigrationVersionBumpCheckTest::testANonRepositoryRefusesToGiveAVerdict` fails only when `TMPDIR` sits inside a git checkout, as the lane rules prescribe (`TMPDIR=$PWD/.tmp`): its "not a repository" folder then lives inside this clone's repository. The same test exits 1 with `TMPDIR` in the clone and 0 with `TMPDIR` outside any repository. Not touched by this change.
- `test:all` also printed the runner warning "No code coverage driver available" (no xdebug or pcov on this box), and 25 skips, none in this change's tests.
- `docs/Features/text-extraction-vectorization-ner.md` claimed PPTX was indexed for search; corrected here because the section was being edited anyway.

## opsx-verify (headless)

- Completeness: 6 of 6 tasks ticked; 7 of 7 requirements implemented.
- Correctness: every requirement and scenario maps to code and to a named test in `PresentationExtractorTest`.
- Coherence: design decisions followed; the three new classes follow the `WordExtractor` shape.
- API and browser tests: not applicable (no endpoint, no UI) and no live instance may be touched.
- Three warnings found and fixed in `451814f9`: design.md still said only the `body` placeholder is notes (untrue since the LibreOffice fix); REQ-PPTX-002 did not say page furniture stays out of `body` although the code and a test rely on it; the "no readable slides" warning lacked the MIME type REQ-PPTX-005 promises (now logged, and asserted in `testADeckWithoutSlidesReturnsNull`).
- Left as it is: the missing-zip-extension throw has no unit test, because a test process cannot unload `ext-zip`; the branch is two lines and mirrors `WordExtractor`'s untested library guard.

## Not in this PR

- Indexing presentation text for search through `TextExtractionService` (it changes existing indexing, so it is its own change).
- Legacy binary `.ppt` and OpenDocument `.odp`.
- Image bytes: the consumer reads them from the original file.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
