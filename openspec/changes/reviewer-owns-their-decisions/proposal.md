---
kind: code
depends_on: [access-owner-and-condition-scopes]
---

# Proposal: reviewer-owns-their-decisions

## Summary

A reviewer edits only their own redaction decisions and comments, and can be barred from seeing another reviewer's.

- Rows: 12.28 (not statutory). The grant on `wooAssessment` is not in this change: it belongs to whichever app ships that schema (D1 moved the Woo request to dossiq), and is open as a follow-up.
- Wave 1, size M.
- Dependencies: `openregister/access-owner-and-condition-scopes` (open, 0 of 5 tasks, no issue yet) for `@creator` (section 3 only). The former dependency on `redaction-release-safeguards` for `decidedBy`/`decidedAt` is gone: decision 197 puts them on the decision object.
- Decision: D1 decides who ships the assessment schema; no decision bears on this change directly.
- Build rules: openspec/woo-build-rules.md

## Why

Row 12.28, "A reviewer edits only their own redactions and comments, and can be barred from seeing another reviewer's", is `no` in our column (the round 1 baseline). `EntityRelationMapper::updateDecisionMetadata()` records the acting user in the immutable audit trail, but `EntityRelationsController::update()` lets any caller who may write the relation's subject change any occurrence, including one another reviewer decided. opencatalogi's `wooAssessment` schema grants read, create, update and delete to `admin` (`lib/Settings/register.d/fix-woo-capability-provisioning.json`), so every administrator edits every other reviewer's assessment, and nothing bars one reviewer from seeing another's.

A four-eyes review is only four eyes if the second reviewer cannot quietly overwrite the first, and in some organisations a second reviewer must decide without seeing the first decision.

## What changes

Decision 197 (Ruben, 11 Oct 2026): "A decision is its own object that can be linked by other objects." The earlier draft put `decidedBy`, `decidedAt` and `decisionNote` on the entity relation. That is replaced.

- The decision object. A new OpenRegister table `openregister_decisions` holds one decision per row: `uuid`, `subjectType` and `subjectId` (what was decided on), `verdict`, `decidedBy`, `decidedAt`, `note` and `supersedes`. A decision is never edited. A change records a new decision that names the one it supersedes, so who decided is a fact, not a field someone else can overwrite. `DecisionService` records and reads them for any subject type, so other apps can link decisions from their own objects.
- The link. An entity relation gains `decision_uuid` and serialises it as `decision`. `PATCH /api/entity-relations/{id}` records a decision object (`entity-relation`, the relation id, `redact` or `release`, the caller, and `decisionNote` from the body) and links it, in one transaction with the audited write of `bases` and `skipAnonymization`. The response carries the relation plus `decisionRecord`.
- Decision ownership. A relation whose current decision was taken by someone else can then be changed only by that person, by a member of a declared supervisor group, or by an administrator. An undecided relation can be decided by anyone who may write its subject, as today. A refused change answers 403 `{error: "decision-owned", decision, decidedBy, decidedAt}`, without the verdict or the note.
- The app that owns the subject decides who supervises. Before someone other than the decider changes a decider-owned decision, OpenRegister dispatches `DecisionChangeRequestedEvent` with the decision, the caller, the policy and the subject (register, schema, object uuid, file id). A listener may allow or refuse; a listener that fails refuses. This is how dossiq's case reviewer access answers per case, without OpenRegister knowing about cases.
- Hiding. When hiding is on, every endpoint that returns entity relations returns another reviewer's decided relation as `decision: "hidden"`, without the decision record or `bases`, unless the caller is that reviewer, a supervisor or an administrator. The relation itself stays listed, so the reviewer knows there is something to decide.
- Configuration. Per schema through `x-openregister-review: {"ownership": "decider", "supervisors": ["<group>"], "hideOthers": true}`, refused at save when the ownership is neither `open` nor `decider`. A relation on a file reads the schema of the object the file belongs to. For files that belong to no schema, the instance default sits in the file settings as `anonymisationReview` with the same shape. The default stays `open`, today's behaviour.
- The assessment record. A Woo assessment is an OpenRegister object, so its half is an authorization block the owning app ships: `update: ["@creator", "<supervisor group>"]` and, to hide, `read: ["@creator", "<supervisor group>"]`, enforced by `access-owner-and-condition-scopes` (open, 0 of 5). This change adds a test that such a block on a fixture `wooAssessment`-shaped schema refuses a second reviewer's edit, so the owning app can rely on it.

### Why OpenRegister's own decision object, not decidiq's Decision

decidiq's `Decision` already carries `subjectRegister`, `subjectSchema` and `subjectId`, so reuse was checked first. It does not fit a reviewer verdict on a redaction:

- OpenRegister cannot depend on decidiq. Redaction review runs on any OpenRegister instance, and a duck-typed call to an app that is not installed would turn ownership into a silent no-op.
- The subject is an entity relation, a row in `openregister_entity_relations` with an integer id, not an object with a register, schema and uuid. decidiq's back-reference cannot name it.
- decidiq's Decision is a governance decision: `title`, `text` and `decisionType` are required, it has a voting lifecycle, and creating one notifies `decidesk-members` (`decisionRecorded`). A document review takes hundreds of verdicts per document; each would become a governance decision and a notification.

decidiq's Decision stays the object for governance decisions. A later change can let decidiq link an OpenRegister decision when a governance decision rests on a review.

## What does not change

- Who may write the relation's subject at all: `actorCanWriteRelationSubject()` stays the first check.
- The audit trail of decisions.

## Dependencies and absent apps

- `redaction-release-safeguards` (wave 1) was to add `decidedBy` and `decidedAt` to the relation. Decision 197 moves both onto the decision object, so this change no longer waits on it for them.
- `access-owner-and-condition-scopes` (open, 0 of 5) supplies `@creator` for the assessment half.
- The plan names "the wooAssessment grant change in opencatalogi/woo-request-scoped-access". That change does not exist on opencatalogi `development` (checked 2026-10-05), and decision D1 moved the Woo request to dossiq. The grant change therefore belongs to whichever app ships the `wooAssessment` schema; the PR body names the authorization block above for that lane. No app is called at runtime, so there is no absent-app path.

## Wave and decision

Wave 1, size M. No decision bears on it directly; D1 decides who ships the assessment schema. Closes 12.28.
