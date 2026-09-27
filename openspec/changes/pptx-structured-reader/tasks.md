## 1. Dependency decision

- [x] 1.1 Try `composer require "phpoffice/phppresentation:^1.2" --dry-run` and record the result; verify `composer.json` and `composer.lock` are unchanged afterwards (`git diff --quiet composer.json composer.lock`)

## 2. Extractor

- [x] 2.1 Add `lib/Service/TextExtraction/PresentationExtractor.php` with the `WordExtractor` shape (logger-only constructor, `extract(File $file): ?array`, public `supports()`), reading slide order, title, body, notes and images per the spec; verify `php -l`, `phpcs`, `phpstan` and `phpmd` report nothing new on the file
- [x] 2.2 Bound hostile input (DOCTYPE refusal, per-part read cap, slide cap with `truncated`, group depth cap) and degrade per-document failures to `null` with a content-free log; verify with the tests in 3.1

## 3. Tests

- [x] 3.1 Add `tests/Unit/Service/TextExtraction/PresentationExtractorTest.php` that builds a small deck inside the test (reordered slide files, a hidden slide, title and body, group and table, notes with a slide-number placeholder, an embedded and a linked picture) and asserts every spec scenario; verify `vendor/bin/phpunit --filter PresentationExtractorTest` passes
- [x] 3.2 Cross-check against a deck written by a real office suite (LibreOffice, local only, not committed) and verify slide order, titles, notes and images read back as expected

## 4. Verification

- [ ] 4.1 Run `composer check:strict`, `npm run lint` and the hydra gates once before push, and record each exit code in the PR body
