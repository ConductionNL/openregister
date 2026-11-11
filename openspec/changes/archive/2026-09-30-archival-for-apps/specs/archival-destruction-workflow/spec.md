# archival-destruction-workflow

## ADDED Requirements

### Requirement: Another app can create a destruction list for objects OpenRegister finds eligible

`POST /api/archival/destruction-lists` and `DestructionListCreator::createFor()` SHALL accept a list of object uuids and SHALL create a destruction list holding only the objects that pass the same eligibility rule the daily sweep applies. Every refused uuid SHALL be returned with the rule that refused it. When no uuid is eligible, no list SHALL be created and the route SHALL answer 422.

#### Scenario: an object under legal hold is refused, the rest is listed

- **GIVEN** two objects nominated for destruction with a past action date, one of them under an active legal hold
- **WHEN** an archivist posts both uuids
- **THEN** the response is 201 and the stored list holds only the object without a hold
- **AND** `refused` names the held object with reason `legal_hold`
- @e2e exclude {asserted over the real RetentionService in tests/Unit/Service/Archival/DestructionListCreatorTest.php}

#### Scenario: nothing eligible creates nothing

- **GIVEN** an object nominated to be kept
- **WHEN** its uuid is posted
- **THEN** the response is 422, no list is saved, and `refused` names it with reason `not_nominated_for_destruction`
- @e2e exclude {asserted in tests/Unit/Service/Archival/DestructionListCreatorTest.php and tests/Unit/Controller/ArchivalCertificatesTest.php}

### Requirement: The certificates route returns the stored destruction certificates

`GET /api/archival/certificates` SHALL return the certificate stored for each executed destruction list, optionally narrowed to one list, and SHALL name under `missing` every executed list whose certificate is not stored.

#### Scenario: an executed list's certificate is returned

- **GIVEN** an executed destruction list whose `certificateUuid` points at a stored `verklaring_van_vernietiging`
- **WHEN** an archivist reads the certificates
- **THEN** the result holds that certificate with its `destructionListUuid` and `totalDestroyed`
- @e2e exclude {asserted over the real DestructionListRepository in tests/Unit/Controller/ArchivalCertificatesTest.php}

### Requirement: An app's register import can ship selectielijst categories

An app's register import SHALL accept `components.selectionLists` entries with `category`, `retentionYears`, `action`, `description` and `organisation`, and SHALL write each as a row of the configured selectielijst register, matched on category and organisation so a second import changes nothing.

#### Scenario: a second import is a no-op

- **GIVEN** an app import carrying category `2.1` with action `vernietigen` and 10 retention years
- **WHEN** the import runs twice
- **THEN** exactly one selectielijst row exists for `2.1`, with `bewaartermijn` `P10Y`, and the second run reports it unchanged
- @e2e exclude {asserted in tests/Unit/Service/Archival/SelectionListSeederTest.php}

#### Scenario: a category shipped by an app drives retention

- **GIVEN** the row written for category `2.1`
- **WHEN** retention resolves a schema whose `archive.classification` is `2.1`
- **THEN** it applies `vernietigen` and `P10Y`
- @e2e exclude {asserted with the real SelectielijstResolver in tests/Unit/Service/Archival/SelectionListSeederTest.php}
