---
kind: code
depends_on: [delete-window-and-recorded-destruction, permission-provenance-and-deny]
---

# Proposal: data-subject-rights-across-the-instance

## Summary

The Dutch tension in one cluster: the Archiefwet forbids deleting the zaak
and the AVG obliges you to erase the person. OpenRegister has a
data-subject-request model and a mode-parameterised erasure that honours a
legal hold. What it does not have is the preview that says what an erasure
will touch before it runs, an export the subject takes themselves, and a
way to take away everything one person can reach in one act. This change
adds those three, and the time-limited external collaborator beside them.

## Candidates and cluster

Cluster 38 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Erasure, export and the
data subject's own rights". Owner openregister, size L, depends on delete,
restore and destroy, six candidates: C-access-and-privacy-19, -22, -57,
-58, -61 and -74. Four are `must` and one is a matrix hole:
C-access-and-privacy-19. Passers: 7, five driven and two documented.
dossiq rates `partial` on one and `no` on five.

Ledger row the candidate notes name: 13.22.

## The decisions this rests on

**D10 as taken.** No new recycle state: openregister's existing soft
delete is the recovery window, with a stated period and a recorded
destruction in `delete-window-and-recorded-destruction`. An erasure in this
change destroys through that path rather than inventing a second one.

**D22 as taken.** Access is compiled into the query in openregister, which
is what makes "everything this person can reach" a question with an answer
rather than a walk over screens.

**D6, relevance-led promotion.** C-access-and-privacy-19 is a `must` with
two driven passers and no row in the corpus.

**D21, documented candidates admitted and labelled.**
C-access-and-privacy-58 (youtrack) and C-access-and-privacy-61
(visma-circle) have no driven passer. Both are in scope as documented.

**D1, the dossiq-only rows.** Row 13.22 is renumbered centrally.

## Why

The proving system is request-tracker, cited by the access lane at
`access-and-privacy.tsv:27`: "Self service,
$SelfServiceDownloadUserData (share/html/SelfService/User/)". The subject
takes their own data, in a machine readable form, without anybody
assembling it. That is C-access-and-privacy-19, a `must` and a matrix
hole, with request-tracker and vikunja driven and youtrack documented.
AVG article 20 portability and article 15 access are both answered within
a term, and dossiq's own lane says the answer today is a manual database
export.

The rest:

- **A person's data erased across the product as an administered task,
  with what it will touch shown first** (C-access-and-privacy-22, `must`):
  zammad, "Data privacy tasks (app/models/data_privacy_task.rb:20
  prepare_deletion_preview, :24 MAX_PREVIEW_TICKETS, :47 deletion_counts)".
  dossiq's lane: "WOOAnonymisationAssistService redacts a document for
  publication; nothing anonymises a person across the system".
- **Everything one person can reach, taken away in one act, shown first**
  (C-access-and-privacy-58, `must`, documented): youtrack, "Access Eraser
  Widgets". Uitdiensttreding, a compromised account, and the question
  "welke toegang had deze persoon", in one place. dossiq revokes by
  removing a group membership, one grant at a time.
- **An outside collaborator works temporarily and what they made stays
  under control** (C-access-and-privacy-61, `must`, documented):
  visma-circle, "/software/samenwerken". Time-limited access applied to a
  workspace rather than to one case.
- **Everything one account holds exported into another account or
  instance** (C-access-and-privacy-57): nextcloud-deck,
  "lib/UserMigration/DeckMigrator.php". That is the platform user
  migrator, and it is `platform-user-migrator` under D9.

**What exists here and does not close it.** `gdpr-data-subject-rights`
specifies the data-subject-request object with its status lifecycle, the
article 12 deadline computation, an RBAC and tenant scoped service,
erasure that honours a legal hold and is mode-parameterised, and an
immutable audit of fulfilment. The `dsar-` changes add the engine, the
subsystem, the surface, the escalation and the policy pack.
`pdf-anonymisation` and `tag-preserving-redaction` carry the document
half, which filinq consumes. So a request can be recorded, deadlined,
escalated and fulfilled. What is missing sits either side of fulfilment:
nothing counts what an erasure will touch before it runs, the subject
cannot take their own copy, and access is revoked one grant at a time.

## What changes

- **An erasure is previewed with counts.** Before it runs, the task reports
  how many objects, files, timeline entries and party records it will
  touch, split by what will be erased, what will be pseudonymised and what
  is held by a legal hold or a retention period. The preview writes
  nothing.
- **An erasure runs from an approved preview, through the recorded
  destruction.** What it destroys goes through the delete window's recorded
  destruction, so a vernietiging has one path and one record.
- **A subject takes their own export.** A data subject, or a handler acting
  for them, takes everything the instance holds about that subject in a
  machine readable form, produced as a background job, delivered as a file
  with its own expiry.
- **Everything one principal can reach is listed and revoked in one act.**
  The list is the effective access `permission-provenance-and-deny` already
  resolves, shown before anything is taken. The revocation is one act, and
  it is recorded with everything it removed.
- **An external collaborator is time limited by construction.** A grant to
  a principal outside the organisation carries an end date, is refused
  without one, warns before it lapses, and what the collaborator created
  stays with the organisation when it does.

## Consumers

- **dossiq**: the erasure preview on a zaak's parties, and the
  uitdiensttreding act, with no privacy code of its own.
- **filinq**: anonymisation of a document stays filinq's, called from the
  erasure once the preview is approved.
- **portaliq**: the subject's own export, requested from the portal by the
  subject.
- **humaniq**: the leaver case from the personnel side, over the same
  revocation act.

## ADRs

- ADR-005: the erasure and the revocation fail closed. An object whose
  hold cannot be resolved is counted as held, never as erasable.
- ADR-003: the preview, the erasure and the revocation are audit facts on
  the chain, and the audit of an erasure survives it.
- ADR-022: one data subject rights mechanism in the object layer, consumed
  by every leaf app.
- ADR-010: revocation removes grants through the authorization layer, never
  by editing a group.

## Impact

- Extends: `gdpr-data-subject-rights` (the preview, the subject export and
  the erasure path) and `authorization-rbac` (the reach listing, the single
  revocation and the expiring external grant).
- Affected code: the erasure service and its mode handling, a preview
  counter over registers and files, the export writer, the permission
  resolver's reverse query.
- Backwards compatible: an existing data-subject request keeps its
  lifecycle, and an erasure with no preview is refused rather than silently
  changed.
- Size: L.

## Out of scope

- Exporting one account's own working data into another account or
  instance (C-access-and-privacy-57), which is `platform-user-migrator`
  under D9.
- A document people must read and pass, with who did recorded
  (C-access-and-privacy-74, `could`). Recorded, not built.
- Anonymising a document's content, which filinq owns.
