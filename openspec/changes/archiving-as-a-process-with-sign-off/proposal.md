---
kind: code
depends_on: [object-archive-state, delete-window-and-recorded-destruction]
---

# Proposal: archiving-as-a-process-with-sign-off

## Summary

The end of a retention period is a decision a person takes, not a job that
runs. Four systems describe the same arc: a dossier is offered for
archiving when it closes, listed when its retention expires, reviewed per
item by a named person, then destroyed or transferred, with a record of
who decided. OpenRegister has the destruction list, the approval and the
certificate. What it has not got is the front of the arc, the named
reviewer with a worklist, and transfer as a third answer at the moment of
review.

## Candidates and cluster

Cluster 43 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The archiving process,
from nomination to destruction or transfer". Size L, 17 candidates, twelve
of them `must`: C-documents-6, C-documents-9, C-documents-11,
C-documents-18, C-documents-21, C-documents-23, C-documents-24,
C-documents-32, C-documents-33, C-documents-39, C-documents-41,
C-documents-43, C-configuration-84, C-integrations-24, C-integrations-40,
C-integrations-44.

Ledger rows named in the candidate notes: 11.22, 13.24, 7.7, 8.1.
Passers: ten, four driven and six documented. dossiq: seven `partial`,
nine `no`.

## The decision this rests on

**D7, option 1, and the owner moved.** The recommendation was a
multi-step process with sign-off, in filinq, with the record kept in
openregister. Ruben placed the process itself in openregister: the
vernietigingslijst, the reviewer per item, the approval and the act, all
recorded, live where the objects and their retention live. filinq and
dossiq consume it. The build plan's owner line for this cluster reads
filinq and is superseded by that decision.

The reason to write the process down rather than export it is in the
decision itself: an export moves the evidence out of the product, which is
fine until an auditor asks who approved the destruction of a dossier in
2029.

## Why

The proving system is xxllnc-zaken, cited by the integrations lane at
`integrations.tsv:26`: "Archiving (archiving/spec.md)", for certification
against the named records management standards. The lane's reading of
dossiq is blunt: the one hit for `vernietigingslijst` in dossiq's tree is
`src/data/capabilityComparison.json`, which is dossiq's own claim about
itself.

- **atabix, decos-join and rx-mission**, documented,
  `cross-area.tsv:2`: "atabix: /gestandaardiseerde-modules
  (Recordmanagement)". The end of a retention period is a process with
  named steps and a person's decision, not a delete job. The clause names
  the shape: a prompt with three named choices, and the third choice is
  the e-depot.
- **visma-circle**, documented, `documents.tsv:25`:
  "/software/archief-en-dossiervorming". A dossier moves to a static
  preservation regime once its business use ends. The clause: the statisch
  dossier is a named Dutch archival state between active and transferred,
  and we have nothing between open and destroyed.
- **xxllnc-zaken**, `documents.tsv:48`: "Case type > Archief
  (case-type-editor-anatomy.md)". Each MDTO or TMLO element is mapped to a
  case attribute in the interface. The clause is exactly our position: we
  can produce the export and cannot let an administrator say what goes in
  which element.
- **xxllnc-zaken**, `integrations.tsv:49`: "Case page, MEER INFORMATIE
  panel (Case-MeerInformatie.md)". The case shows its own lead times,
  archival nomination, destruction date and statutory basis. A handler
  answering a Woo request needs the grondslag in front of them.
- **odoo**, `integrations.tsv:51`: "data_recycle_model.py:65
  notify_frequency". The owner of a retention rule is reminded that
  records are waiting on their decision. The clause is one line: the
  review only happens if somebody is asked.
- **opencase**, `configuration.tsv:126`: "Admin settings
  (AdminSettings.md)". The national classification plan and its facets are
  uploaded as files, which for us is the selectielijst and the
  zaaktypecatalogus.
- **dimpact-zac**, `documents.tsv:43`: "Archiving
  (docs/system-context.md)". Archiving handed to a dedicated component,
  which is the Common Ground answer and the opposite of a certified single
  system.

**What exists here and does not close it.** `retention-management`
generates destruction lists by background job, requires review and
approval (including a second archivist for schemas marked
`archive.requireDualApproval`), records exclusion reasons, extends
`archiefactiedatum` on a rejection, produces an immutable destruction
certificate, holds objects under bevriezing and sends pre-destruction
notifications. `archivering-vernietiging` restates that arc and adds
e-depot export, NEN 2082 and NEN-ISO 16175 conformance requirements.
`edepot-transfer` carries the transfer itself. `object-archive-state`,
open, gives an object a state between open and gone.

So the back half is specified. Four things are not. Nothing nominates an
object for archiving when its business use ends. Nobody is named as the
reviewer of an item, so nobody can be reminded and nothing appears on
anybody's worklist. Transfer is not one of the answers a reviewer may give
at the moment of review: approve and reject are, and the e-depot is a
separate act elsewhere. And the archival facts of an object are computed
and never shown on the object itself.

## What changes

- **Nomination at closure.** When an object reaches a terminal state, the
  system derives its archival nomination and its archiefactiedatum from
  the selectielijst and writes them on the object, with the rule that
  produced each one. An object that cannot be nominated is reported, not
  skipped.
- **A preservation regime between active and transferred.** A nominated
  object moves to a preservation state: out of the working views, refusing
  content writes, keeping its references, and distinct from the archive
  state `object-archive-state` gives a finished object. The two are
  declared side by side so nobody has to guess which one a dossier is in.
- **A reviewer per item, with a worklist.** An entry on a destruction list
  carries the person accountable for it. That person reads their own
  pending items, and is reminded on a declared frequency while items wait.
  A list with unassigned items names them.
- **Three answers at the review, not two.** Destroy, retain with a new
  date and a reason, or transfer to an e-depot. Transfer at the review
  hands the item to `edepot-transfer` and records the choice in the same
  place as the other two, so the decision history is one artefact.
- **The archival facts are readable on the object.** Nomination,
  archiefactiedatum, the selectielijst row, the statutory basis, the hold
  if there is one and the destruction or transfer record once it exists.
- **The element mapping is administered.** Which object property fills
  which MDTO or TMLO element is configuration with a validator, not a
  shipped default, and an unmapped mandatory element is refused before a
  transfer rather than discovered in the e-depot.
- **The classification plan arrives as a file.** A selectielijst and a
  classification plan are imported from the file the archivist was given,
  versioned, and diffed against what is in use.

## Consumers

- **filinq**: the preservation format half, which stays where it is. A
  file whose format is not on the approved list is not archivable
  (C-documents-9), PDF/A at filing (C-documents-21), and the technical
  preservation metadata per file (C-documents-33). openregister holds the
  process and the record; filinq holds the bytes and their formats.
- **dossiq**: declares the resultaattype that drives the nomination, which
  the build plan names as the dossiq half, and renders the archival panel
  on the case from the facts this change publishes.
- **decidiq, humaniq, pipelinq, keepiq**: the same obligation over
  non-case work (C-documents-43), which is the object layer's answer to a
  gap every case system leaves.

## ADRs

- ADR-022: the archival process belongs where the objects and their
  retention live, and the leaf apps declare rather than implement.
- ADR-005: an unmapped mandatory element, an unassigned item and an
  unresolvable selectielijst row all refuse.
- ADR-031: the nomination rule, the mapping and the review policy are
  declared configuration.

## Impact

- Extends `retention-management` (nomination, the reviewer and the
  worklist, transfer as a review answer, the administered mapping, the
  imported plan) and `object-lifecycle` (the preservation regime, beside
  `object-archive-state`). `edepot-transfer` and `archivering-vernietiging`
  keep their scope.
- Affected code: the archival derivation, the destruction list objects and
  their controller, the notification rules for the reminder, the MDTO
  mapping and its validator, the selectielijst import and the object read
  path.
- Backwards compatible: an instance with no nomination rule and no
  assigned reviewers behaves as today, with the list unassigned rather
  than blocked.
- Size: L.

## Out of scope, and where each one goes

- **C-documents-9, C-documents-21, C-documents-33**: format policy, PDF/A
  and PRONOM metadata. filinq owns the bytes.
- **C-integrations-24**: certification against NEN 2082 and NEN-ISO
  16175. `archivering-vernietiging` already carries conformance
  requirements; a certificate is an audit to commission, not code to
  write. Recorded, not built.
- **C-documents-11**: a pre-depot holding records against the
  preservation standards. That is an e-depot deployment question, not a
  product capability, and the transfer path is `edepot-transfer`.
- **C-documents-23**: documents from task-specific applications under the
  same rules. The candidate note says the product is naming the gap; the
  answer is an integriq intake, not a change here.
