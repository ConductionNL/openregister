---
status: proposed
---

# person-aware-detection

## ADDED Requirements

### Requirement: A detected person gets a capacity suggestion with its source (REQ-DKP-001)

For every PERSON detection, OpenRegister SHALL compute `capacity` (`public-office`, `professional`, `private` or `unknown`) and `capacitySource` (`officials-register`, `role-words`, or `none`). It SHALL use the configured officials source (an OpenRegister schema with name and role properties, or Nextcloud groups mapped to a capacity) and role words within the same sentence or signature block. The organisation policy `anonymisation.capacityPolicy` SHALL map each capacity to a suggested decision (`release` or `withhold`) and a ground identifier; the shipped default SHALL be `public-office: release`, and `withhold` with ground `5.1.2e` for `professional`, `private` and `unknown`.

#### Scenario: an alderman is suggested for release
- **GIVEN** an officials register listing "P. de Vries" as wethouder (`public-office`)
- **WHEN** a decision letter signed "P. de Vries, wethouder" is detected
- **THEN** the PERSON occurrence shows the suggestion release, capacity `public-office`, source `officials-register`

#### Scenario: a case handler is suggested for withholding with a ground
<!-- @e2e exclude Covered by PHPUnit CapacitySuggesterTest::testAProfessionalIsSuggestedWithheldUnder512e. -->

- **GIVEN** "behandeld door: J. Bakker" with J. Bakker in the officials register as `professional`
- **WHEN** detection runs
- **THEN** the suggestion is withhold with ground `5.1.2e`

### Requirement: A suggestion is confirmed by a person and the applied capacity is recorded (REQ-DKP-002)

A capacity suggestion SHALL NOT set `skipAnonymization` by itself. An occurrence with an unconfirmed suggestion SHALL be anonymised. The reviewer SHALL confirm or change the capacity through `PATCH /api/entity-relations/{id}` with `capacity`; the relation SHALL then record `capacityApplied`, `capacitySource`, `capacityConfirmedBy` and `capacityConfirmedAt`, and the decision and ground follow the policy unless the reviewer sets them explicitly. The change SHALL go through the audited `updateDecisionMetadata` path.

#### Scenario: an unconfirmed release suggestion is still redacted
<!-- @e2e exclude Fail-closed service path; covered by PHPUnit CapacityDecisionTest::testAnUnconfirmedReleaseSuggestionIsAnonymised. -->

- **GIVEN** a `public-office` suggestion nobody confirmed
- **WHEN** the file is anonymised
- **THEN** the name is replaced

#### Scenario: the reviewer confirms and the file records which applied
- **GIVEN** the same suggestion
- **WHEN** the reviewer confirms `public-office` in the review screen
- **THEN** the name is released on anonymise, and the relation records capacity `public-office`, source `officials-register`, the reviewer and the moment

### Requirement: A person detection is checked against the organisation's own registers before it is proposed (REQ-DKP-003)

When `anonymisation.personRegister` names a source (an OpenRegister schema with a name property, or the Nextcloud contacts of a named address book) and a declared purpose, each PERSON candidate SHALL be matched against it by normalised name (case and accents ignored) before it is stored. A match SHALL raise the confidence by `anonymisation.personRegisterBoost` (default 0.2, capped at 0.99) and set `personMatch: {source}`. When `anonymisation.placeRegister` names a street or place source, a candidate that matches it and not the persons register SHALL be stored with `uncertain: true`. A candidate SHALL NOT be dropped by this step. The lookup SHALL read in memory, SHALL NOT store the matched record's id or values on the relation, and each run SHALL record the purpose and the number of lookups.

#### Scenario: a known resident's name is proposed with high confidence
<!-- @e2e exclude Needs a configured persons register; covered by PHPUnit PersonRegisterMatcherTest::testAMatchRaisesConfidence. -->

- **GIVEN** a persons register with "Fatima El-Amrani" and a detection of "fatima el amrani" at confidence 0.55
- **WHEN** detection runs
- **THEN** the relation is stored at confidence 0.75 with `personMatch.source` naming the register and no record id

#### Scenario: a street named after a person is flagged, not dropped
<!-- @e2e exclude Covered by PHPUnit PersonRegisterMatcherTest::testAStreetMatchMarksUncertainAndKeepsTheCandidate. -->

- **GIVEN** a place register containing "Willem de Zwijgerlaan" and a PERSON candidate "Willem de Zwijger" in that street name
- **WHEN** detection runs
- **THEN** the candidate is stored with `uncertain: true` and still appears for the reviewer

#### Scenario: no register configured
<!-- @e2e exclude Covered by PHPUnit PersonRegisterMatcherTest::testWithoutARegisterTheRunSaysNotConfigured. -->

- **GIVEN** no `anonymisation.personRegister`
- **WHEN** detection runs
- **THEN** confidences are unchanged and the run records `personRegister: not-configured`
