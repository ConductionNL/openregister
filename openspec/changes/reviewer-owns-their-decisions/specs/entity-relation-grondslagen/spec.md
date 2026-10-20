---
status: proposed
---

# entity-relation-grondslagen

## ADDED Requirements

### Requirement: A reviewer's decision is changed only by that reviewer or a supervisor (REQ-ROD-001)

When ownership is `decider` for the relation's schema (from `x-openregister-review`, read from the relation's own schema or, for a relation on a file, from the schema of the object the file belongs to) or, for a relation with no schema, from the instance default (`anonymisationReview` in the file settings), `PATCH /api/entity-relations/{id}` on a relation whose current decision object (REQ-DEC-001) was taken by someone else SHALL be refused with 403 `{error: "decision-owned", decision, decidedBy, decidedAt}` unless the caller is the decider, a member of a configured supervisor group, an administrator, or allowed by the app that owns the subject (REQ-DEC-002). The refusal SHALL NOT carry the verdict or the note, and under `hideOthers` (REQ-ROD-002) it SHALL carry neither `decidedBy` nor `decidedAt`: it says only that the decision belongs to another reviewer. A relation without a decision SHALL be decided by any caller who passes the existing subject write check. The decision note (`decisionNote` in the body, stored as the decision's `note`) SHALL follow the same rule. When ownership is `open` behaviour SHALL be as today. A declaration whose ownership is neither `open` nor `decider`, whose supervisors are not a list of group ids, or whose `hideOthers` is not a boolean SHALL be refused at schema save with 422; an ownership that is unreadable at run time SHALL be read as `decider`.

#### Scenario: a second reviewer cannot overwrite the first
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testASecondReviewerIsRefused through EntityRelationsController::update; the Newman sequence of task 1.3 runs it through the route. -->
- **GIVEN** a schema with ownership `decider` and supervisors `woo-coordinator`, and a name `j.devries` decided to redact under `5.1.2e`
- **WHEN** reviewer `p.bakker`, who may write the document, changes it to release
- **THEN** the change is refused, naming `j.devries` and the moment of the decision, and the relation is unchanged

#### Scenario: the coordinator may change it
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testASupervisorMayChangeAnotherReviewersDecision through EntityRelationsController::update. -->

- **GIVEN** the same decision
- **WHEN** a member of `woo-coordinator` changes it
- **THEN** a new decision is stored with the coordinator as `decidedBy`, superseding the first, and the audit row of the relation records the change

#### Scenario: an undecided occurrence is open to any reviewer
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testAnUndecidedRelationIsOpen. -->

- **GIVEN** a relation with no decision
- **WHEN** `p.bakker` decides it
- **THEN** a decision object is stored with `p.bakker` as `decidedBy` and linked from the relation

### Requirement: A schema can hide one reviewer's decisions from another (REQ-ROD-002)

When `hideOthers` is on, every endpoint that returns entity relations (`gdprEntities#index`, `gdprEntities#show`, the file entity listings and the review leaf's reads) SHALL return a relation decided by someone other than the caller with `decision: "hidden"`, without its decision record (`decidedBy`, `decidedAt`, `note`), without `bases` and `skipAnonymization`, and without `anonymized` and `anonymizedValue` (an anonymise pass writes both from the verdict, so they would show it), unless the caller is a supervisor or an administrator. The relation SHALL still be listed with its type and position.

#### Scenario: a blind second review
- **GIVEN** `hideOthers` on and ten occurrences, four decided by `j.devries`
- **WHEN** `p.bakker` opens the document's detections
- **THEN** all ten are listed, the four show as already decided without the decision or its ground, and the other six are open

#### Scenario: hidden fields do not leak through another endpoint
<!-- @e2e exclude Covered by PHPUnit HiddenDecisionTest::testEveryRelationEndpointMasks, which enumerates the controllers returning EntityRelation by reflection. -->

- **GIVEN** `hideOthers` on
- **WHEN** a reviewer reads relations through any endpoint that returns them
- **THEN** no response carries another reviewer's `decidedBy`, `decisionNote` or `bases`

#### Scenario: a refused change under hideOthers names no one
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testUnderHideOthersTheRefusalNamesNoDecider. -->

- **GIVEN** ownership `decider` with `hideOthers` on, and `j.devries` decided an occurrence
- **WHEN** `p.bakker` tries to change that decision
- **THEN** the answer is 403 `{error: "decision-owned", decision}` without `decidedBy` or `decidedAt`

### Requirement: An assessment record can be limited to its author and a supervisor (REQ-ROD-003)

An object schema whose authorization block holds `update: ["@creator", "<group>"]` SHALL refuse an update by a user who is neither the object's creator nor a member of the group, and `read: ["@creator", "<group>"]` SHALL keep the object out of that user's lists and reads, as `access-owner-and-condition-scopes` specifies.

#### Scenario: one reviewer cannot edit another's assessment
<!-- @e2e exclude Covered by PHPUnit AssessmentOwnershipTest::testASecondReviewerCannotEditTheFirstsAssessment on a wooAssessment-shaped fixture schema. -->

- **GIVEN** a fixture schema shaped like `wooAssessment` with `update: ["@creator", "woo-coordinator"]` and an assessment created by `j.devries`
- **WHEN** `p.bakker` updates it
- **THEN** the update is refused
