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
