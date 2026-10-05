# Design

## D-1 · Why a declared set rather than deriving one

The audit trail already answers "what did this run write", and `flow-object-attribution` built the endpoint for it. It is tempting to reuse that as the run's object set and add nothing.

It cannot serve this purpose, for a reason that only shows up in the flows that need it. A case flow reads a case it never writes; it writes a task, a document and an audit row it does not consider a subject; and it may write the same object at three steps for three different reasons. The derived list therefore contains too much and too little at once, and — decisively — it carries **no role**. `attachTo: case` needs a name, and a list of things that happened has none to give.

So the two coexist. Derived answers the auditor. Declared answers the author.

## D-2 · Why a role and not an index

`attachTo: 2` would work and would be unreadable, and would break the moment a step is inserted. A role is the flow author's own word for what the object is *to this flow*, which is the thing later steps actually mean.

Roles are per-run and unconstrained on purpose. There is no vocabulary to register and no schema to keep in step: `case`, `besluit`, `aanvraag` are all fine, and a typo is caught by the addressing rule failing loudly rather than by a registry the author has to maintain.

## D-3 · Replacement is recorded, not refused

Re-pointing a role is legitimate: a flow that supersedes a draft decision with a final one genuinely means `decision` to be the new object. Refusing would force authors into `decision2`.

But it is also exactly how a later step silently attaches to the wrong record. So it is allowed and logged. The log entry is what makes the eventual "why is this task on the old decision" answerable in under a minute.

## D-4 · Attaching through the task's existing object fields

`Task` already has `objectUuid`, `registerId` and `schemaId`, and three things already read them: the subject-anchored inbox query (which also relaxes the external-performer exclusion on that anchor), the case sidebar, and the portal visibility rule.

Filling those from the subject entry means the attachment works everywhere immediately. A new `attachedSubjectRole` column would have needed all three taught about it, and the two that were not taught would have been found by a user.

## D-5 · The answers fix is one line, and that is the point

`outcomeBagFor()` gains `answers`. `PortalTaskNode` line 450 has done exactly this since it was written.

The interesting question is why nobody noticed. Because the feature reads as complete from every angle except the last: the form is declared, refused at save if it names a field the schema lacks, rendered to the performer, validated on completion, and stored. Every one of those has a test. None of them asserts that a *following step* can see the value, so the one hop that was missing was the one hop nothing looked at.

The test that closes it must therefore be a downstream assertion — a Switch routing on `answers.x` — not another assertion that the responses were stored.

## Traps

**An absent `answers` key and an empty one are different failures.** Omitting the key when a step declares no form would make every downstream expression need two guards. Always present, sometimes empty.

**The subject set must survive suspension.** It lives on the run row, not in the resume slot: slots are per node, and a run holds one per node. A three-week approval must wake up still knowing what its case is.

**Idempotency is not optional here.** A user-task node is re-entered on a heartbeat, by design, with the task still open. Any node that records a subject will be re-entered too, and a set that grew on each wake would report a run as working on the same case forty times.

**Do not seed declared subjects from the audit in the migration.** The repair seeds the `trigger` entry from `subjectUuid` and nothing else. Back-filling roles from what old runs wrote would invent declarations the authors never made, and those declarations would then be addressable by `attachTo`.
