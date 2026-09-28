---
kind: code
---

# Proposal: detection-dutch-licence-plates

## Summary

A privacy officer who anonymises a document gets every Dutch licence plate in it
found and replaced, the way an IBAN already is. Open Register's detector gains a
`LICENSE_PLATE` entity type and a jurisdiction pattern set for the Netherlands
that knows all fourteen sidecodes. The same set carries the BSN with its
elfproef, because today the built-in detector does not find a BSN at all. A
plate-shaped token that is not a plate (a case number, a product code) is not
flagged, because a pattern alone is never enough to claim one. An instance
outside the Netherlands keeps its current behaviour: the Dutch set is switched
on per instance, not built into every detector.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| filinq | det-dutch-ids | Recognise Dutch identifiers such as BSN, IBAN and licence plates. | partial |

Row `det-dutch-ids` in filinq's matrix, owned here because `built.owner` is
ConductionNL/openregister: filinq sends documents to Open Register's detector
and anonymiser and only filters what comes back.

Demand rows: none recorded in the packet. The row is competitor-derived.

Competitor yes cells, quoted from the packet:

- xxllnc Anonimiseren (DataMask): "docs-only, read 2026-09-26:
  https://xxllnc.nl/applicaties/anonimiseren/ regular expressions 'zoals
  e-mail, IBAN, BSN'; https://algoritmes.overheid.nl/nl/algoritme/gm1724/94888124/datamask-anonimiseringstool
  BSN, phone numbers, e-mail addresses". Evidence:
  https://xxllnc.nl/applicaties/anonimiseren/ and
  https://algoritmes.overheid.nl/nl/algoritme/gm1724/94888124/datamask-anonimiseringstool
- Decos JOIN: "docs-only, read 2026-09-26: https://decos.com/oplossingen/anonimiseren
  names burgerservicenummers, IBAN's and kentekens". Evidence:
  https://decos.com/oplossingen/anonimiseren

## Why

The row's evidence cites filinq's `lib/Service/EntityDetectionService.php:51-52`
`TYPED_PII_TYPES`. That list is a filter over what Open Register returns, not a
recogniser. Recognition happens in Open Register, and there:

- The entity types are ten constants at
  `lib/Service/TextExtraction/EntityRecognitionHandler.php:66-75`. There is no
  licence plate type. The BSN is `SSN` (the Dutch label is "BSN",
  `l10n/nl.json:1995`).
- The built-in regex detector, `getRegexPatterns()` at
  `EntityRecognitionHandler.php:505-533`, knows three patterns: e-mail, phone
  and IBAN. It has no BSN pattern and no plate pattern.
- Every other method falls back to that regex set: Presidio when unconfigured
  or failing (`:547-557`, `:597-604`), OpenAnonymiser when unreachable
  (`:660-669`, `:696-701`), LLM always (`:919-933`), and hybrid is regex only
  (`:939-951`). So on most instances the regex set is the detector.
- For Presidio, `SSN` is sent as `US_SSN` (`:869`, `:899`), a United States
  pattern that does not match a nine-digit BSN.
- A BSN validator with the elfproef already exists at `lib/Formats/BsnFormat.php`
  but only schema validation uses it (`lib/Service/Object/ValidateObject.php:2016`).

So a kenteken in a document is never found, and a BSN is found only when an
external backend that knows it is configured and up.

## What changes

- A new entity type `LICENSE_PLATE` beside the existing ten, with category
  personal data, risk tier medium, and a translatable label (`KENTEKEN` in nl).
- Jurisdiction pattern sets: a small interface and one implementation per
  jurisdiction. The first is `nl`: licence plate (sidecodes 1 to 14) and BSN.
  The generic patterns (e-mail, phone, IBAN) stay where they are and run
  everywhere.
- The rules live in `lib/Formats/` (openregister ADR-008): a new
  `LicensePlateNlFormat` holds the sidecodes and letter rules, and the BSN
  pattern calls the existing `BsnFormat`. The detector calls these; it carries
  no copy of the rule.
- A false-positive guard: a hyphenated plate must match a sidecode exactly and
  stand alone as a token; an unhyphenated one counts only with a context word
  nearby; a span claimed by a checksum-validated identifier is not a plate.
- The enabled sets are a file setting, `entityPatternSets`, defaulting from
  Nextcloud's `default_phone_region` (NL gives `["nl"]`), editable by an admin.
- Jurisdiction set matches are merged into the results of every method,
  including Presidio and OpenAnonymiser, so a backend that does not know plates
  does not hide them.

## Consumers

- filinq (row det-dutch-ids): its anonymisation flow receives `LICENSE_PLATE`
  entities and replaces them. filinq adds the type to its own `TYPED_PII_TYPES`
  in a filinq change so its length floor never drops one.
- Open Register's own file anonymisation (`POST /api/files/{fileId}/anonymize`)
  and the entities page (`/entities`).

## ADRs

- hydra ADR-001 (data layer): Dutch fields sit behind a mapping, not as the
  primary name. The type is `LICENSE_PLATE`; `nl` supplies the patterns.
- hydra ADR-005 (security): no PII in logs. A detected plate or BSN is never
  logged, only counts.
- hydra ADR-007 (i18n): the new label is a translatable string in en and nl.
- hydra ADR-011 via openregister ADR-008: one validator per rule in
  `lib/Formats/`.
- openregister ADR-009 (performance invariants): patterns are compiled once per
  run, not per chunk.

## Impact

- New capability `pii-entity-detection`.
- Affected code: `lib/Service/TextExtraction/EntityRecognitionHandler.php`, a
  new `lib/Service/TextExtraction/PatternSet/` folder, new
  `lib/Formats/LicensePlateNlFormat.php`, `lib/Service/RiskLevelService.php`,
  `lib/Service/File/DocumentProcessingHandler.php` (label list),
  `lib/Service/Settings/FileSettingsHandler.php`, `l10n/`.
- Backwards compatible. An instance whose region is not NL and whose admin does
  not enable `nl` sees no change. On an NL instance the regex detector finds
  more (plates and BSNs), which is the point; existing entity rows are untouched.
- Size: S.

## Out of scope

- Teaching the OpenAnonymiser ExApp (anonymiq) or Presidio a plate recogniser.
  That is anonymiq's repository. This change makes Open Register find plates
  whatever the backend knows.
- Foreign plates, diplomatic plates and trade plates (handelaarskentekens).
  Another jurisdiction is another pattern set in a later change.
- An IBAN checksum. The IBAN pattern is generic and stays as it is.
- filinq's `TYPED_PII_TYPES` entry, which is filinq's.
