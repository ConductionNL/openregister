# A transition input may name a field inside an object property

## Why

A transition's `inputs` allowlist matched payload keys against the declared
`field` names at the top level only. nextcloud-vue's transition dialog folds a
dotted input (`municipalityFeedback.masRoute`) into the nested payload
`{"municipalityFeedback": {"masRoute": "..."}}`, so declaring the dotted name
refused the dialog's payload ("does not accept input field(s)
municipalityFeedback") and asked for a literal top-level `municipalityFeedback.masRoute`
key. Declaring the parent instead (`municipalityFeedback`) accepts the whole
object, and `required` can then not be put on one of its fields.

learniq's attendance flag (`recordMunicipalityFeedback`, task 1.3 of
`attendance-flag-report-actions`) needs exactly that: the MAS route required,
the note optional, both inside `municipalityFeedback`.

## What changes

- A declared `field` is a path. `parent.child` accepts `parent` in the payload
  holding only its declared children; an undeclared child is refused by its
  dotted path (`parent.other`), and a scalar or list in place of the parent is
  refused as `parent`.
- `required` on a dotted input is checked inside the parent: absent parent,
  absent child or empty string are missing.
- A literal dotted payload key is unfolded into the nested shape first.
- The accepted children are written into the stored parent leaf by leaf, so
  the parent's other stored fields survive the transition write.
- A parent that is itself declared accepts its whole value, as before. Plain
  declarations behave exactly as before.

## Not in scope

The task-form completion path (`TaskFormSubjectWriter`) shares the allowlist
and so accepts and refuses the same payloads; it still merges at top level.
