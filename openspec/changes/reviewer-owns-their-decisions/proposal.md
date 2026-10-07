---
kind: code
depends_on: [redaction-release-safeguards, access-owner-and-condition-scopes]
---

# Proposal: reviewer-owns-their-decisions

## Summary

A reviewer edits only their own redaction decisions and comments, and can be barred from seeing another reviewer's.

- Rows: 12.28 (not statutory). The grant on `wooAssessment` is not in this change: it belongs to whichever app ships that schema (D1 moved the Woo request to dossiq), and is open as a follow-up.
- Wave 1, size M.
- Dependencies: `openregister/redaction-release-safeguards` (https://github.com/ConductionNL/openregister/issues/4392) for `decidedBy` and `decidedAt`, and `openregister/access-owner-and-condition-scopes` (open, 0 of 5 tasks, no issue yet) for `@creator`.
- Decision: D1 decides who ships the assessment schema; no decision bears on this change directly.
- Build rules: openspec/woo-build-rules.md

## Why

Row 12.28, "A reviewer edits only their own redactions and comments, and can be barred from seeing another reviewer's", is `no` in our column (the round 1 baseline). `EntityRelationMapper::updateDecisionMetadata()` records the acting user in the immutable audit trail, but `EntityRelationsController::update()` lets any caller who may write the relation's subject change any occurrence, including one another reviewer decided. opencatalogi's `wooAssessment` schema grants read, create, update and delete to `admin` (`lib/Settings/register.d/fix-woo-capability-provisioning.json`), so every administrator edits every other reviewer's assessment, and nothing bars one reviewer from seeing another's.

A four-eyes review is only four eyes if the second reviewer cannot quietly overwrite the first, and in some organisations a second reviewer must decide without seeing the first decision.

## What changes

- Decision ownership. A relation decided by a person (`decidedBy`, added by `redaction-release-safeguards`) can then be changed only by that person, by a member of a declared supervisor group, or by an administrator. An undecided relation can be decided by anyone who may write its subject, as today. A refused change answers 403 naming who decided and when.
- The decision note. `EntityRelation` gains `decisionNote`, a reviewer's comment on the decision, owned the same way as the decision.
- Hiding. When hiding is on, every endpoint that returns entity relations returns another reviewer's decided relation as `decision: "hidden"`, without `decidedBy`, `decisionNote` or `bases`, unless the caller is that reviewer, a supervisor or an administrator. The relation itself stays listed, so the reviewer knows there is something to decide.
- Configuration. Per schema through `x-openregister-review: {"ownership": "decider", "supervisors": ["<group>"], "hideOthers": true}`, and an instance default in the file settings (`anonymisation.review.ownership` `open` or `decider`, `anonymisation.review.supervisors`, `anonymisation.review.hideOthers`) for files that belong to no schema. The default stays `open`, today's behaviour.
- The assessment record. A Woo assessment is an OpenRegister object, so its half is an authorization block the owning app ships: `update: ["@creator", "<supervisor group>"]` and, to hide, `read: ["@creator", "<supervisor group>"]`, enforced by `access-owner-and-condition-scopes` (open, 0 of 5). This change adds a test that such a block on a fixture `wooAssessment`-shaped schema refuses a second reviewer's edit, so the owning app can rely on it.

## What does not change

- Who may write the relation's subject at all: `actorCanWriteRelationSubject()` stays the first check.
- The audit trail of decisions.

## Dependencies and absent apps

- `redaction-release-safeguards` (wave 1) adds `decidedBy` and `decidedAt`; this change orders after it in the same wave.
- `access-owner-and-condition-scopes` (open, 0 of 5) supplies `@creator` for the assessment half.
- The plan names "the wooAssessment grant change in opencatalogi/woo-request-scoped-access". That change does not exist on opencatalogi `development` (checked 2026-10-05), and decision D1 moved the Woo request to dossiq. The grant change therefore belongs to whichever app ships the `wooAssessment` schema; the PR body names the authorization block above for that lane. No app is called at runtime, so there is no absent-app path.

## Wave and decision

Wave 1, size M. No decision bears on it directly; D1 decides who ships the assessment schema. Closes 12.28.
