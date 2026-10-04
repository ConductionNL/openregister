## Scope

Adds `DocumentExtractor` next to `WordExtractor` and `PresentationExtractor` (#4077): a Word document comes back as a title and one section per heading with its level, holding its paragraphs, lists, tables as rows and picture references in document order, plus the flat text exactly as `WordExtractor` gives search. Learniq's folder onboarding (learniq#1080) reads docx structure itself with `ZipArchive` today and can switch to this in a follow-up.

OpenSpec change: `openspec/changes/docx-structured-reader/` (proposal, design, tasks, new capability `text-extraction-document` with REQ-DOCX-001 to 010).

## Evidence

- learniq#1080 design, decision "`WordExtractor` drops heading levels and images, so docx structure is read in learniq; the reader sits behind one method and can move to OpenRegister (named follow-up)". This PR is that follow-up.
- Recon `learniq-mi/learniq/_round2/recon/D-ai-lessons-onboarding-styles.md`, section 1: `WordExtractor.php` reads docx "for flat text (search/indexing use, not structure)".
- Competitor evidence for the consumer, proposed row C-new-7 (vendor claims): Studytube turns uploaded files into courses, Docebo Creator drafts lessons from uploaded documents (`learniq-mi/learniq/corporate-lms/round1/documented-columns.md:294`). No rung: a round 2 follow-up, round 3 scope.

## What it returns

```
{ title, sections: [ { heading, level, blocks: [ paragraph | list | table | image ] } ], text, truncated }
```

- Headings by outline level, else by style name through the `basedOn` chain, so a Dutch Word style (`Kop1`, named `heading 1`) and LibreOffice's headings read the same. Text before the first heading sits in a level 0 section.
- `title`: the first Title paragraph, else the core properties title.
- Lists: consecutive items of one numbering become one block; each item has its level and whether it is numbered. A heading carrying outline numbering (LibreOffice's chapter numbering, format `none`) stays a heading.
- A text box stored twice for compatibility (`mc:AlternateContent`) is read once. Deleted text of tracked changes is left out.
- Pictures: package path or link, `external`, name and alt text. No bytes, as for decks.
- `text` is `WordExtractor::extract()` on the same file, byte-identical to what search indexes. When that gives nothing (PhpWord could not read the file, or is missing), `text` is built from the structure.

## Bounds and failure

Reuses `OoxmlPackage` from #4077 unchanged (DOCTYPE refused on bytes and parsed tree, 20 MiB per part without trusting the zip directory). Adds a cap of 10,000 paragraphs and tables with `truncated: true`, and a depth cap of 20 for content controls, nested tables and text boxes. Anything unreadable returns `null` and logs the file id, MIME type and exception class, never content; a missing zip extension throws.

## Classes

`DocumentExtractor` (file handling, flat text), `DocumentBodyParser` (sections and blocks), `DocumentContentReader` (one paragraph or table), `DocumentStyleMap` (headings, title, numbering), `OoxmlElements` (local-name lookups). All but the extractor are pure DOM work. The split follows phpmd's class complexity cap: the first single parser came to 89 against a cap of 50.

## Headless decisions

- `text` comes from `WordExtractor`, not from the structure, so search and structure can never disagree. `WordExtractor` is injected through the constructor.
- Sections are flat with a level, not a tree: learniq needs one block per section, and a tree is one pass away.
- Word's "List Bullet 2" style is its own numbering instance, so its items come back as a new list at level 1. That is what the file says; nesting is never guessed from indents. Found by the cross-check below.

## Verified

| Command | Exit |
|---|---|
| `openspec validate docx-structured-reader --strict` | 0 |
| `php -l` on every touched PHP file | 0 |
| `vendor/bin/phpcs` on touched `lib/` files | 0 |
| `vendor/bin/phpmd` per new class (isolated HOME, cold pdepend cache) | 0 each |
| `vendor/bin/phpstan analyse` and `psalm` on touched files | 0, 0 |
| `phpunit --no-coverage --filter 'DocumentExtractorTest\|PresentationExtractorTest\|WordExtractorTest'` (85 tests) | 0 |
| `phpunit --no-coverage --filter DocumentExtractorTest` after the cap test (49 tests, 114 assertions) | 0 |
| `composer check:strict` once, via the memory semaphore (TMPDIR and HOME outside the checkout) | 1: lint, check:migration-version, phpcs, phpmd, psalm (0 errors) and phpstan (0 errors) all pass; `test:all` ran 24,213 tests with no failure and exits 1 only on "No code coverage driver available" (no xdebug or pcov on this box) |
| `vendor/bin/phpunit --no-coverage`, the full suite | 0 (24,213 tests, 25 skipped, 1 warning that is not from this change) |
| `npm run lint` / `npm run format` / `npm run test:l10n` | 0 (932 inherited warnings) / 0 / 0 |
| hydra gates `--scope-to-diff` (base origin/development, 13 files) | 1: only gate-112 newman-reach, 21 Postman collections outside the CI path, none touched here. gate-16 spec-coverage, gate-46 anchors, gate-57 orphaned writes and 30 more pass; gate-68 checker exited without a count (wiring, no finding) |
| opsx-verify (headless) | 0 CRITICAL, 0 WARNING; the one untested bound (style chain cap) got its test in 2ef8feaf99 |

LibreOffice Writer is not installed on this box (only Draw and Impress), so the LibreOffice-shaped test document is hand-built from LibreOffice 24.2's DOCX conventions. A real-generator cross-check ran locally against a document written by python-docx 1.2.0, which ships Word's own default template (scratch install, not committed): title, three heading levels, lists, table and picture read back as expected, and `text` equals `WordExtractor`'s output.

## Inherited findings

The hydra gate run fails only on gate-112 (21 Postman collections outside the CI path, all untouched), and `test:all` inside `check:strict` exits 1 on the missing coverage driver; both are environment or inherited, none on lines this PR touches.

## Not in this PR

- Learniq replacing its `DocxLessonReader` with `DocumentExtractor`. That is a learniq change.
- `.odt` and legacy `.doc`; indexing the structure for search.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
