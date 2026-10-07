---
status: proposed
---

# redaction-policy

## ADDED Requirements

### Requirement: Always and never-redact term lists apply on every detection path (REQ-RPD-001, carried from filinq entity-publication-policies)

OpenRegister SHALL ship a `redaction-policy` register with a `termRule` schema (`list` `always` or `never`, `entityType`, `matchRules` of type exactly `exact`, `normalized`, `bsn` or `kvk`, `reason`, `legalAuthority`, `bases`, `validFrom`, `validUntil`, `active`, `scope`, `requestRef`, `layer`). A `matchRules` type other than those four SHALL be refused at write time. `EntityRecognitionHandler` SHALL, for every detection method, load the active rules whose time bounds are open into an in-memory index once per run (no database query per detected entity), SHALL search the text for `always` terms of type `exact` and `normalized` so a term the model missed is still detected, and SHALL match every detection against both lists. A detection matching an `always` rule SHALL be pre-set to redact; one matching only a `never` rule SHALL be pre-set to release; one matching both SHALL follow the `always` rule. Among several matching rules of one list the lowest uuid SHALL be recorded as `policyMatch`, and every matching rule SHALL be written to the audit row. A rule with `active` false, a future `validFrom` or a past `validUntil` SHALL NOT match. The cache SHALL be invalidated when a `termRule` object changes. There SHALL be no option to make `never` win.

#### Scenario: a protected witness is always redacted, even where the model missed the name
- **GIVEN** an always-redact rule `normalized` "beschermde getuige a" for the organisation
- **WHEN** a document containing "Beschermde Getuige A" is detected with the Presidio backend, which does not find the name
- **THEN** a PERSON detection exists for it, pre-set to redact, with `policyMatch` naming the rule

#### Scenario: the municipality's own name is never redacted
- **GIVEN** a never-redact rule `exact` "Gemeente Voorbeeldstad" for ORGANIZATION
- **WHEN** a document mentioning it is detected
- **THEN** the detection is pre-set to release with `policyMatch` naming the rule, and the anonymised output keeps the name

#### Scenario: both lists match and the always list wins
<!-- @e2e exclude Matcher precedence; covered by PHPUnit TermRuleMatcherTest::testAlwaysWinsAndBothAreAudited. -->

- **GIVEN** an always rule and a never rule matching the same text
- **WHEN** it is detected
- **THEN** it is pre-set to redact, `policyMatch` names the always rule, and the audit row names both

#### Scenario: an expired rule does not match
<!-- @e2e exclude Covered by PHPUnit TermRuleMatcherTest::testExpiredAndInactiveRulesAreSkipped. -->

- **GIVEN** an always rule whose `validUntil` passed yesterday
- **WHEN** a matching document is detected
- **THEN** the rule does not match

#### Scenario: overriding an always match needs a supervisor and a reason
<!-- @e2e exclude Covered by PHPUnit PolicyOverrideTest::testAReviewerCannotReleaseAnAlwaysMatch through EntityRelationsController::update. -->

- **GIVEN** a detection pre-set to redact by an always rule
- **WHEN** a reviewer who is not a supervisor or administrator sets it to release
- **THEN** the change is refused naming the rule; a supervisor with a reason may make it

### Requirement: A term list can be scoped to one request (REQ-RPD-002)

A `termRule` with `scope: request` and a `requestRef` SHALL apply only to detection and anonymise calls whose `policyContext.requestRef` equals it, and SHALL NOT apply to any other call.

#### Scenario: a requester's own name is redacted in their request only
- **GIVEN** a request-scoped always rule for "P. de Boer" with `requestRef` `woo-2026-118`
- **WHEN** a document is detected for request `woo-2026-118` and the same document is detected for request `woo-2026-119`
- **THEN** the name is pre-set to redact in the first and not matched by that rule in the second

### Requirement: Detection rules come in a shipped base layer and an organisation layer (REQ-RPD-003)

Every policy object SHALL carry `layer` `base` or `local`. The repair step SHALL create and update `base` objects by slug and SHALL NEVER create, update or delete a `local` object. A `local` object with `overrides` naming a base slug and `active` false SHALL switch that base rule off, and SHALL keep doing so after the base is updated. `NlPatternSet`'s shipped patterns SHALL be reported as the base layer with their version.

#### Scenario: an update keeps the organisation's rules
- **GIVEN** an organisation's local pattern for its own case numbers and a local override switching off a base rule
- **WHEN** OpenRegister is upgraded and the base layer changes
- **THEN** the local pattern still runs, the override still switches the base rule off, and the updated base rules run

### Requirement: An officer's own pattern runs beside the model (REQ-RPD-004)

A `detectionPattern` of `kind` `regex` or `term` with an `entityType` SHALL run on every detection method beside the model. A regex SHALL be refused at save when it does not compile, matches the empty string, is longer than 500 characters, or exceeds the backtrack limit on a 100 KB test text. A pattern's detections SHALL carry `detectionMethod: pattern:<slug>`.

#### Scenario: case numbers are found by the organisation's pattern
- **GIVEN** a local pattern `\bZK-\d{4}-\d{5}\b` with entity type `CASE_NUMBER`
- **WHEN** a document containing `ZK-2026-00412` is detected with the LLM backend
- **THEN** a `CASE_NUMBER` detection exists with method `pattern:` and the pattern's slug

#### Scenario: a dangerous pattern is refused
<!-- @e2e exclude Covered by PHPUnit DetectionPatternValidatorTest::testCatastrophicBacktrackingIsRefused and testAnEmptyMatchIsRefused. -->

- **GIVEN** the pattern `(a+)+$`
- **WHEN** it is saved
- **THEN** it is refused naming the backtrack limit

### Requirement: A rule carries the ground it implies (REQ-RPD-005, carried from filinq REQ-DDARW-005)

`termRule`, `detectionPattern` and a profile's `basesByEntityType` SHALL carry optional `bases`: ground identifiers stored as text and never resolved against a list in OpenRegister (decision D3). At detection, when a relation's `bases` is empty, the matched rule's `bases`, else the pattern's, else the profile's for that entity type, SHALL be written into it. A reviewer's `bases` SHALL always win and SHALL NOT be overwritten by a later detection run. A rule without `bases` SHALL match exactly as before.

#### Scenario: the ground arrives with the detection
- **GIVEN** the profile maps BSN to ground `5.1.1d` and an always rule carries `5.1.2e`
- **WHEN** a document with a BSN and the rule's name is detected
- **THEN** the BSN relation holds `5.1.1d` and the name's relation holds `5.1.2e`, with nobody having typed either

### Requirement: A named profile is applied without restating it, chosen by request or document type (REQ-RPD-006)

A `redactionProfile` SHALL carry `name`, `redact`, `keep`, `termRuleTags`, `method` with `maskForms`, `basesByEntityType`, `documentTypes` and `isDefault`. The shipped base profile SHALL be the Woo profile: redact PERSON, BSN, PHONE, EMAIL, IBAN and ADDRESS; keep ORGANIZATION, LOCATION and DATE. Detection and anonymise SHALL accept `policyContext` (`profile`, `requestRef`, `documentType`) through `POST /api/files/{fileId}/anonymize`, the extraction endpoints and `FileService::anonymizeDocument()`, and SHALL choose the profile in this order: named in the call, bound to the document type (`policyContext.documentType` or the file's metadata `documentType`), the organisation's default, the shipped Woo profile. The chosen profile SHALL be recorded on the `AnonymisationLog` run. Two profiles binding the same document type in one organisation SHALL be refused at save.

#### Scenario: the next besluit is handled like the last
- **GIVEN** a local profile `besluiten-openbaar` bound to document type `besluit`, keeping ORGANIZATION and masking IBAN to its last four characters
- **WHEN** a new file with document type `besluit` is anonymised without naming a profile
- **THEN** the run records `besluiten-openbaar`, organisations are kept and IBANs are masked as the profile says

#### Scenario: a new request takes the organisation's default
<!-- @e2e exclude Covered by PHPUnit ProfileResolverTest::testTheOrganisationDefaultIsUsedWhenNothingIsNamed. -->

- **GIVEN** an organisation default profile and no profile named or bound
- **WHEN** a file is anonymised
- **THEN** the default profile is applied and recorded

### Requirement: filinq's lists are imported into the engine (REQ-RPD-007)

`occ openregister:redaction-policy:import-filinq` SHALL copy every `publicationProhibition` object to an `always` `termRule` and every `publicationConsent` object with `scope: entity` to a `never` `termRule` from the `filinq` register, carrying match rules, time bounds, active state, reason, legal authority and `bases`, as `layer: local` with `source` set to the filinq object's uuid. A second run SHALL update, not duplicate. Without a `filinq` register it SHALL report that there is nothing to import and exit 0.

#### Scenario: a filinq prohibition becomes an engine rule
<!-- @e2e exclude occ command; covered by PHPUnit ImportFilinqPolicyCommandTest::testAProhibitionBecomesAnAlwaysRuleOnce. -->

- **GIVEN** a filinq prohibition for "Beschermde Getuige A" with `bases` `5.1.2e`
- **WHEN** the import runs twice
- **THEN** one `always` term rule exists with the same match rule, ground and source uuid
