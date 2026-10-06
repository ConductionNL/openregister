---
kind: code
depends_on: [anonymisation-discloses-itself]
---

# Proposal: anonymisation-discloses-itself-pipeline

## Summary

Every external call that carries a document's text is recorded per document, and every content-altering step in the anonymisation pipeline reports whether it is on and whether it ran.

- Rows: 13.22 and 14.18 (none statutory). 14.18 reads yes only with the retention statement of the first part in place.
- Wave 1, size M. Second part of a chain split from `openregister/anonymisation-discloses-itself` to keep each issue at 20 tasks or fewer.
- Depends on `openregister/anonymisation-discloses-itself` (https://github.com/ConductionNL/openregister/issues/4379): the detector version record on `AnonymisationLog`, the per-backend retention statement and the e2e spec file this part extends. Build it after that change is merged.
- Decision: D10 (Ruben, 2026-10-05) unflagged 14.13 to 14.19 as compliance disclosures for the detector the stack already runs.
- Build rules: openspec/woo-build-rules.md

## Why

Two rows from the Woo capability programme (round 1 build plan) ask what the anonymisation pipeline does to a document and where the document's text goes. Our column today, from the round 1 baseline:

| row | capability | ours today |
|---|---|---|
| 13.22 | An operator can see which content-altering steps are on, and a switch that is off says so | partial: only the OpenAnonymiser source has a surface (`FileConfiguration.vue`); the PDF text replacer, the PDF metadata sanitiser and the two office sanitisers have none |
| 14.18 | The product names every external service a document's text goes to, and what it retains | partial: endpoints sit in `FileSettingsHandler`; nothing records per document which service received the text, and nothing states retention |

`anonymisation-discloses-itself` states what each backend retains. This part records, per document, which services actually received the text, and reports the pipeline steps.

## What changes

- A disclosure record is written per document for every external call that carries its text: service, host, moment, byte count. A call whose record cannot be written is not made.
- A pipeline report lists every content-altering step (anonymiser, PDF text replacer, PDF metadata sanitiser, DOCX sanitiser, ODT sanitiser, and the XLSX and PPTX sanitisers once `redaction-release-safeguards` adds them) with its configured state, and each run records whether each step ran.

## What does not change

- Detection itself, the backends and their precedence (`anonymiser-backend-selection`).
- The version record, measured accuracy, training and retention statements and the threshold setting: those are `anonymisation-discloses-itself`.

## Dependencies

- `anonymisation-discloses-itself` (wave 1), merged first.
- No other app is called. filinq and opencatalogi read nothing from this part.

## Wave and decision

Wave 1, size M. Implements D10. Closes 13.22 and 14.18.
