---
status: proposed
---

# archivering-vernietiging

## ADDED Requirements

### Requirement: A permanent appraisal is inherited along declared references (REQ-AIP-001)

A schema MAY declare `x-openregister-retention.inheritAppraisalFrom` as a list of property names whose values reference other objects. Before an object is judged eligible for destruction, `RetentionService` SHALL resolve those references, and recursively the same declaration on each referenced object's schema, up to five levels, visiting each object once. When any object reached that way has a retain-permanently appraisal (`Appraisal::RETAIN_PERMANENTLY_ALIASES`), the object SHALL NOT be eligible, SHALL NOT receive a pre-destruction notification, and the reason SHALL name the ancestor. The schema annotation validator SHALL refuse a declaration naming a property the schema does not have or one that is not a reference.

#### Scenario: a publication under a hotspot subject is kept
<!-- @e2e exclude Background destruction run; covered by PHPUnit RetentionInheritedAppraisalTest::testAPublicationUnderAHotspotIsNotEligible. -->

- **GIVEN** a publication whose own appraisal is `vernietigen` with a past destruction date, whose `subjects` property references a subject with appraisal `blijvend_bewaren`, and a publication schema declaring `inheritAppraisalFrom: ["subjects"]`
- **WHEN** `DestructionCheckJob` runs
- **THEN** the publication is not on the destruction list and the run's report names the subject as the reason

#### Scenario: no declaration, no change
<!-- @e2e exclude Background run; covered by PHPUnit RetentionInheritedAppraisalTest::testWithoutTheDeclarationOnlyTheOwnAppraisalCounts. -->

- **GIVEN** the same objects but a schema without the declaration
- **WHEN** the job runs
- **THEN** the publication is eligible as today

#### Scenario: a cycle does not hang the job
<!-- @e2e exclude Background run; covered by PHPUnit RetentionInheritedAppraisalTest::testACycleIsVisitedOnce. -->

- **GIVEN** two objects that reference each other through declared properties
- **WHEN** the job runs
- **THEN** each is visited once and the job completes

### Requirement: An ancestor that cannot be read holds the object back (REQ-AIP-002)

When a declared reference cannot be resolved (the target is deleted, missing or unreadable to the system context), the object SHALL NOT be eligible for destruction in that run, and the run's report SHALL list it with the unresolved reference.

#### Scenario: a dangling subject reference
<!-- @e2e exclude Background run; covered by PHPUnit RetentionInheritedAppraisalTest::testAnUnresolvableAncestorHoldsTheObjectBack. -->

- **GIVEN** a publication whose declared `subjects` reference points at a deleted object
- **WHEN** the job runs
- **THEN** the publication is not eligible and the report names the reference

### Requirement: The hold-back is visible before anything is destroyed (REQ-AIP-003)

A new `occ openregister:retention:dry-run` command (none exists today) and the destruction list's review view SHALL show, for every object held back by inheritance, the ancestor and its appraisal.

#### Scenario: an archivist checks the run in advance
- **GIVEN** a hotspot subject with three publications past their date
- **WHEN** the archivist opens the destruction list review
- **THEN** the three publications appear under "held back" with the subject named
