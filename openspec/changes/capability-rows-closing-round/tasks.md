# Tasks: capability-rows-closing-round

## 1. Text extraction per format

- [x] 1.1 Pin the PDF, DOCX and EML scenarios in a test that extracts a fixture of each type through `lib/Files/Service/TextExtractionService.php`. Verify: `tests/Unit/Service/TextExtractionServiceTest.php` or a new fixture test.

## 2. A register as one unit

- [ ] 2.1 Pin the round trip: export a register with two schemas and its records, import it on an empty instance, and compare. Verify: a Newman or PHPUnit test against `registers#export` and `registers#import`.

## 3. Lock holder shown

- [ ] 3.1 Pin the lock notice: lock a record as one user and open it as another. Verify: a Playwright test on the record page that reads "locked by" with the holder's name (`src/views/object/ObjectDetails.vue`).
