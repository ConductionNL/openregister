# Generated identifier: a sequence and a format on a schema property

## Why

Round 2 of the dossiq competitor analysis (row A13 in
`concurrentie-analyse/procest/_round2/compare/findings.md`, placement section
3, decision D10): every competitor numbers a case from a mask or a sequence.
OpenCase renders the chip `2026-00002` from the mask `yyyy-#####`
(`opencase/round2/case-detail-anatomy.md`); GZAC keeps a
`JsonSchemaDocumentDefinitionSequenceRecord` per definition
(`valtimo/round2/code-census.md`); Zaaksysteem titles the case `Zaak 2`
(`xxllnc-zaken/round2/case-detail-anatomy.md`).

dossiq's `case.identifier` is free text and shows "-" on 5 of 7 dashboard
rows; `ComplaintService::generateComplaintNumber` numbers complaints only,
and the open change email-case-matching assumes a generated `YYYY-NNNN` that
nothing generates. A number from a sequence is the same need in every fleet
app that files something: cases, complaints, invoices, tickets, decisions.
computed-fields owns save-time materialisation of a declared value; a
sequence is the one value a Twig expression cannot produce safely under
concurrency, so it becomes a sibling annotation there.

## What changes

- A string property may declare `x-openregister-generated`: a `sequence`
  name, a `format` with placeholders (`{year}`, `{seq:5}`, a fixed prefix),
  and a `resetOn` of `never` or `year`.
- On create, when the property is empty, the system takes the next value of
  the named sequence under a lock and renders the format. The result is
  unique per sequence, gap-tolerant, and never reused after a rollback.
- The property is read-only after generation; an update that changes it is
  refused. Import may supply a value, which advances the sequence past it.
- A sequence is shared when two schemas name the same sequence, so a
  register can number cases and complaints from one counter.

## Who benefits

dossiq (case number), zaakafhandelapp (zaaknummer), decidiq (decision
number), humaniq (employee number), pipelinq (quote number), keepiq (ticket).

## Impact

- Affected specs: computed-fields (delta).
- Affected code: schema annotation validation, a new
  `openregister_sequences` table with a migration, a listener on
  `ObjectCreatingEvent` beside the lifecycle initial-state listener, the
  update guard, import handling.
- Backwards compatible: a schema without the annotation is unchanged.

## Discovery cluster 41 extension (2026-09-14)

The round 4 discovery sweep in ConductionNL/market-intelligence,
`procest/_round4/discovery/build-plan.md`, puts cluster 41, "Case
numbering, sequences and second identifiers", on openregister's identifier
allocation, which is this change. Size M, five candidates: C-case-core-20,
-28, -30, -39 and C-configuration-61. Two are `must` and one is a matrix
hole: C-case-core-30. Passers: 5, all five driven. Proving system
osticket. dossiq rates `partial` on one and `no` on four. The cluster
carries no decision of its own and enters under D6.

Ledger row the candidate notes name: 2.1, which this change already
closes for the sequence and the format.

- **Each record type has its own number format and sequence, or a
  non-sequential one** (C-case-core-30, `must`, a hole): osticket,
  "Per-case-type number sequence (include/class.sequence.php:5, :43 next()
  under a lock, :214 RandomSequence)", and znuny. dossiq: "complaints
  only, and the format is a mask".
- **The identifier the sending system used is kept beside the one this
  system allocated, and finds the record** (C-case-core-39, `must`):
  kanboard, "Task, 26 detail fields including a reference". A zaak arrives
  over ZGW or StUF with the sender's own id and we have nowhere to put it,
  so correlation is a text search. dossiq: "case.identifier is free text
  and reads a dash on 5 of 7 demo rows".
- **A second, human number with an administered prefix beside the system
  one** (C-case-core-20): xxllnc-zaken, "Configuratie > Zaken". The number
  the citizen was given in a letter is often not the system's.
- **Changing the identifier scheme rewrites the identifiers already
  issued, and reports that migration's progress** (C-case-core-28):
  openproject, "Administration, /admin/settings/work_packages_identifier
  with get :status and confirm_dialog".
- **Identifiers are reserved so nothing else can claim them**
  (C-configuration-61, `could`): openproject, "resources
  :project_reserved_identifiers".

**What the extension adds.**

- **A non-sequential sequence kind.** Beside the counter, a random
  identifier of a declared length and alphabet, allocated under the same
  uniqueness guarantee. A municipality that does not want its case volume
  readable from a case number needs this and nothing else.
- **A foreign identifier is a first-class value.** An object may carry
  identifiers allocated elsewhere, each naming the system that issued it.
  They are indexed and resolvable, so a ZGW or StUF message finds its
  object by the sender's own id without a text search.
- **A second, human identifier beside the system one.** Generated from its
  own sequence and format, kept in step with the first, and the one that
  goes in a letter.
- **Reserved identifiers.** A value or a pattern may be reserved so no
  sequence issues it.
- **A scheme change is a migration with progress.** Changing the format of
  an already-issued identifier runs as a background job, reports progress,
  keeps the old value as a foreign identifier of this system, and is
  refused while another migration of the same sequence runs.
