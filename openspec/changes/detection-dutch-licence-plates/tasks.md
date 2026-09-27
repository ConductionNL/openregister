# Tasks: detection-dutch-licence-plates

## 1. Rules in lib/Formats

- [ ] 1.1 Add `lib/Formats/LicensePlateNlFormat.php` holding the fourteen sidecodes and letter rules as data, returning the matched sidecode; register it as format `license-plate-nl` in `ValidateObject`. Verify: `tests/Unit/Formats/LicensePlateNlFormatTest.php`, one valid plate per sidecode, C, Q and vowel rejections, sources and check date in the docblock.

## 2. Type and pattern set

- [ ] 2.1 Add `ENTITY_TYPE_LICENSE_PLATE` and wire it into `getCategoryForType()`, `RiskLevelService::ENTITY_RISK_MAP` (medium) and `DocumentProcessingHandler::LOCALIZABLE_ENTITY_TYPES`; add `LICENSE_PLATE` to `l10n/en.json` and `l10n/nl.json` (`KENTEKEN`). Verify: `RiskLevelServiceTest` asserts medium for a file with only a plate.
- [ ] 2.2 Add the `JurisdictionPatternSet` interface and `NlPatternSet` with the plate (via `LicensePlateNlFormat`) and BSN (via `BsnFormat`) and the D-3 guard. Verify: `tests/Unit/Service/TextExtraction/PatternSet/NlPatternSetTest.php`, including a list of at least ten plate-shaped non-plates that yield nothing.
- [ ] 2.3 Run enabled sets after every method in `detectEntities()` and merge with the backend-wins overlap rule. Verify: `EntityRecognitionHandlerTest` cases for regex, a stubbed Presidio result overlapping a plate, and a disabled set.

## 3. Setting

- [ ] 3.1 Add `entityPatternSets` to `FileSettingsHandler` with the `default_phone_region` default and 400 on an unknown code; add the multi-select to `FileConfiguration.vue`. Verify: `FileSettingsHandlerTest` for NL default, other-region default and unknown code; `PATCH /api/settings/files` with `["xx"]` answers 400.

## 4. Tests and docs

- [ ] 4.1 Add `tests/e2e/ci/licence-plate-detection.spec.ts`: upload a text file with a plate, a BSN and a case number, extract, read `GET /api/entities`, anonymise, and assert the placeholders.
- [ ] 4.2 Update `docs/features/ner-nlp-concepts.md` with the jurisdiction sets, the plate type, the guard and the setting, with a screenshot of the setting.

Acceptance:

- A file with `12-GBK-3` and a valid BSN yields one `LICENSE_PLATE` and one `SSN` entity with the regex method.
- An instance with `entityPatternSets: []` yields neither from the same file.
