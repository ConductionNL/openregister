# Notes leaf: rich text, a lock, and a journal sheet export

## Why

Round 2 of the dossiq competitor analysis (row B17 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10): OpenCase's journal notes on a case are rich text, a note can be locked
so it can no longer be edited, and the notes of a case export as one journal
sheet (`opencase/round2/pages/CaseDetail-JournalNotes.md`). In Dutch case
handling a locked note is the record of a contact moment or a decision, which
must not change after the fact.

dossiq's notes are the OpenRegister notes leaf, which is plain text once
defect D01 is fixed (M1 6.1). The leaf serves every fleet app, so the three
features belong on it.

## What changes

- A note's body accepts a bounded Markdown subset (headings, emphasis, lists,
  links) stored as Markdown and rendered sanitised; the editor is the
  Nextcloud Text editor when the Text app is present, a Markdown textarea
  when not.
- A note can be locked by its author or by a user with `manage` on the
  object. A locked note cannot be edited or deleted, by anyone, and shows who
  locked it and when. Locking is audited on the object.
- The notes of one object export as a journal sheet: a PDF or Markdown
  document listing every note in order with author, time and lock state,
  through the existing PDF export format.

## Who benefits

dossiq, zaakafhandelapp, humaniq (personnel file notes), keepiq, integriq
(sync notes).

## Impact

- Affected specs: object-interactions (delta).
- Affected code: `lib/Service/Interaction/NoteService.php`, the comment
  verb storage for the lock flag, the notes leaf Vue surfaces, export-pdf
  usage.
- Backwards compatible: an existing plain-text note is valid Markdown.
