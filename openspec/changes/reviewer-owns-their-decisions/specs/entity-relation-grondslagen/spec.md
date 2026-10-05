---
status: proposed
---

# entity-relation-grondslagen

## ADDED Requirements

### Requirement: A reviewer's decision is changed only by that reviewer or a supervisor (REQ-ROD-001)

When ownership is `decider` for the relation's schema (from `x-openregister-review`) or, for a relation with no schema, from the instance default, `PATCH /api/entity-relations/{id}` on a relation whose `decidedBy` is set SHALL be refused with 403 `{error: "decision-owned", decidedBy, decidedAt}` unless the caller is `decidedBy`, a member of a configured supervisor group, or an administrator. A relation with `decidedBy` null SHALL be decided by any caller who passes the existing subject write check. `decisionNote` SHALL follow the same rule. When ownership is `open` behaviour SHALL be as today.

#### Scenario: a second reviewer cannot overwrite the first
- **GIVEN** a schema with ownership `decider` and supervisors `woo-coordinator`, and a name `j.devries` decided to redact under `5.1.2e`
- **WHEN** reviewer `p.bakker`, who may write the document, changes it to release
- **THEN** the change is refused, naming `j.devries` and the moment of the decision, and the relation is unchanged

#### Scenario: the coordinator may change it
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testASupervisorMayChangeAnotherReviewersDecision through EntityRelationsController::update. -->

- **GIVEN** the same decision
- **WHEN** a member of `woo-coordinator` changes it
- **THEN** the change is stored with the coordinator as `decidedBy` and the audit row names both

#### Scenario: an undecided occurrence is open to any reviewer
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testAnUndecidedRelationIsOpen. -->

- **GIVEN** a relation with no decision
- **WHEN** `p.bakker` decides it
- **THEN** the decision is stored with `p.bakker` as `decidedBy`

### Requirement: A schema can hide one reviewer's decisions from another (REQ-ROD-002)

When `hideOthers` is on, every endpoint that returns entity relations (`gdprEntities#index`, `gdprEntities#show`, the file entity listings and the review leaf's reads) SHALL return a relation decided by someone other than the caller with `decision: "hidden"` and without `decidedBy`, `decidedAt`, `decisionNote` and `bases`, unless the caller is a supervisor or an administrator. The relation SHALL still be listed with its type and position.

#### Scenario: a blind second review
- **GIVEN** `hideOthers` on and ten occurrences, four decided by `j.devries`
- **WHEN** `p.bakker` opens the document's detections
- **THEN** all ten are listed, the four show as already decided without the decision or its ground, and the other six are open

#### Scenario: hidden fields do not leak through another endpoint
<!-- @e2e exclude Covered by PHPUnit HiddenDecisionTest::testEveryRelationEndpointMasks, which enumerates the controllers returning EntityRelation by reflection. -->

- **GIVEN** `hideOthers` on
- **WHEN** a reviewer reads relations through any endpoint that returns them
- **THEN** no response carries another reviewer's `decidedBy`, `decisionNote` or `bases`

### Requirement: An assessment record can be limited to its author and a supervisor (REQ-ROD-003)

An object schema whose authorization block holds `update: ["@creator", "<group>"]` SHALL refuse an update by a user who is neither the object's creator nor a member of the group, and `read: ["@creator", "<group>"]` SHALL keep the object out of that user's lists and reads, as `access-owner-and-condition-scopes` specifies.

#### Scenario: one reviewer cannot edit another's assessment
<!-- @e2e exclude Covered by PHPUnit AssessmentOwnershipTest::testASecondReviewerCannotEditTheFirstsAssessment on a wooAssessment-shaped fixture schema. -->

- **GIVEN** a fixture schema shaped like `wooAssessment` with `update: ["@creator", "woo-coordinator"]` and an assessment created by `j.devries`
- **WHEN** `p.bakker` updates it
- **THEN** the update is refused
