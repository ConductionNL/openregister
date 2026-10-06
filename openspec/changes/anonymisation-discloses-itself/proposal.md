---
kind: code
depends_on: [anonymiser-backend-selection]
---

# Proposal: anonymisation-discloses-itself

## Summary

The anonymisation detector states what it is built from, how accurate it is measured to be, when it last changed and what each service behind it does with the text, and its confidence threshold becomes an organisation setting.

- Rows: 14.13, 14.14, 14.16 and 14.17 (none statutory). 13.22 and 14.18 moved to the second part of this chain, `openregister/anonymisation-discloses-itself-pipeline` (https://github.com/ConductionNL/openregister/issues/4409), which is built after this one.
- Wave 1, size M.
- Dependencies: none in the Woo programme. Extends `openregister/anonymiser-backend-selection` (https://github.com/ConductionNL/openregister/issues/2738, 35 of 37 tasks done), whose backend-state surface this builds on.
- Consumers: `openregister/detection-knows-the-person` (https://github.com/ConductionNL/openregister/issues/4385) and `filinq/anonymization-review-workbench` read `anonymisation.confidenceThreshold`.
- Decision: D10 (Ruben, 2026-10-05) unflagged 14.13 to 14.19 as compliance disclosures for the detector the stack already runs.
- Build rules: openspec/woo-build-rules.md

## Why

The Woo capability programme (round 1 build plan) measured our stack against six rows about what the anonymisation pipeline says about itself. Our column today, from the round 1 baseline:

| row | capability | ours today |
|---|---|---|
| 13.22 | An operator can see which content-altering steps are on, and a switch that is off says so | partial: only the OpenAnonymiser source has a surface (`FileConfiguration.vue`); the PDF text replacer, the PDF metadata sanitiser and the two office sanitisers have none |
| 14.13 | The product names the models, engines and datasets its detector was built from, with versions | partial: `AnonymisationBackendService` names engines; no model, pattern-set or dataset version anywhere |
| 14.14 | The product publishes precision and recall for its detector, measured over a corpus | no: `NlPatternSet::BSN_CONFIDENCE` is a prior, not a measurement |
| 14.16 | The organisation's documents are excluded from training the vendor's model, and that is stated | no: nothing states it for any backend |
| 14.17 | The product says when its detector last changed | no: backend-state carries no version or change date, and `AnonymisationLog` records the engine name only |
| 14.18 | The product names every external service a document's text goes to, and what it retains | partial: endpoints sit in `FileSettingsHandler`; nothing records per document which service received the text, and nothing states retention |

Decision D10 (Ruben, 2026-10-05) unflagged 14.13 to 14.19: they are compliance disclosures for the detector the stack already runs, not AI features.

The confidence threshold is also a literal today (`'confidence_threshold' => 0.5` in `TextExtractionService.php:319` and `:579`, default in `EntityRecognitionHandler.php:265`). filinq's `anonymization-review-workbench` (wave 2, row 14.15) needs it as an organisation setting it can read.

## What changes

- Every detection backend reports a version record: engine, model, pattern-set version, datasets, and the date that record last changed. `GET /api/admin/anonymisation/backend-state` returns it and every `AnonymisationLog` run stores it.
- `NlPatternSet` (and every `JurisdictionPatternSet`) declares a version and a change date as public constants.
- A shipped synthetic evaluation corpus and `occ openregister:anonymisation:evaluate` measure precision and recall per entity type for the active backend. The result is stored with the detector version and shown in backend-state. A result for another detector version is never shown as current.
- An administrator states, per backend, whether submitted text may be used for training and what the service retains. The internal ExApp is declared `excluded` and `none` by the product.
- A disclosure record is written per document for every external call that carries its text: service, host, moment, byte count. Moved to `anonymisation-discloses-itself-pipeline`.
- A pipeline report lists every content-altering step (anonymiser, PDF text replacer, PDF metadata sanitiser, DOCX sanitiser, ODT sanitiser, and the XLSX and PPTX sanitisers once `redaction-release-safeguards` adds them) with its configured state, and each run records whether each step ran. Moved to `anonymisation-discloses-itself-pipeline`.
- `anonymisation.confidenceThreshold` becomes a file setting (default 0.5, the value used today), read at request time.

## What does not change

- Detection itself, the backends and their precedence (`anonymiser-backend-selection`).
- What a reviewer sees about thresholds: that is filinq's `anonymization-review-workbench` (14.15), which reads the setting this change adds.

## Dependencies

- None blocking. `anonymiser-backend-selection` (35/37) is the backend-state surface this extends.
- Consumed by `filinq/anonymization-review-workbench` (wave 2), which reads `anonymisation.confidenceThreshold` from `GET /api/settings/files`.

## Wave and decision

Wave 1, size M. Implements D10 (14.13 to 14.19 unflagged). Closes 14.13, 14.14, 14.16 and 14.17. 13.22 and 14.18 are closed by `anonymisation-discloses-itself-pipeline`.
