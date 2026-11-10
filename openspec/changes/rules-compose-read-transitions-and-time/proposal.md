---
kind: code
depends_on: [rules-engine-operability, field-rules-by-state, calc-engine-scalar-functions]
---

# Proposal: rules-compose-read-transitions-and-time

## Summary

Four things a rule cannot say. It cannot reuse another rule as one of its
conditions, so the same clause is written into twenty rules and corrected in
nineteen. It cannot tell "moved into this status" from "is in this status",
so the first fires once and the second fires on every save. It cannot say
"created more than three working hours ago", so time-based escalation is
written as code rather than administered. And an administrator cannot add a
check of their own with the sentence a handler will read when it fails.

## The rows this closes

### Row 11.40, one rule usable as a condition inside another, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.32`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.40** | 11.32 | One rule usable as a condition inside another | no | unread | pending Q3.23 |
```

- ledger note, verbatim:

> Row 11.20 rules stand alone. Without composition, the same condition is written out in twenty rules and corrected in nineteen of them.

### Row 11.44, automation condition on a before-state and an after-state, not only on now, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.36`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.44** | 11.36 | Automation condition on a before-state and an after-state, not only on now | no | unread | pending Q3.23 |
```

- ledger note, verbatim:

> Row 11.20 rules read current values, so moved into this status and is in this status are the same condition. The first fires once, the second fires every time anything is saved.

### Row 11.50, rule conditions expressed relative to now, including in business hours, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.42`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.50** | 11.42 | Rule conditions expressed relative to now, including in business hours | no | unread |  |
```

- ledger note, verbatim:

> A rule cannot say created more than three working hours ago, so time-based escalation is written as jobs rather than as configuration a manager can change.

### Row 11.53, validation written by an administrator that refuses a save with its own message, rated `partial`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.45`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.53** | 11.45 | Validation written by an administrator that refuses a save with its own message | partial | unread | pending Q3.23 |
```

- ledger note, verbatim:

> FieldValidator and StepConfigValidator validate what the code says. An administrator cannot add a check, and cannot write the sentence the handler reads when it fails.

## What the competitor evidence is

All four rows are among the 98 promoted under decision D1, and the corpus is
explicit about its competitor columns, verbatim:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here. Three of the four carry the
cross-reference `pending Q3.23` in the register, and 11.50 carries none.

## ADRs

- ADR-031 (schema-declarative business logic): a condition, a transition
  guard and a validation message are declared beside the schema. This change
  is the ADR applied to the parts of a rule that are still written in PHP.
- ADR-022 (apps consume OpenRegister abstractions): one rules engine and one
  expression vocabulary for the fleet, which is also decision D3's position.
- ADR-005 (security): a rule that cannot be evaluated is a refusal, never a
  silent pass. A named condition that is missing, a cycle, and an
  unevaluable time expression all fail closed.
- ADR-025 (i18n source of truth): the sentence an administrator writes is
  translatable content, so it is stored as content and not compiled into a
  language file.
- ADR-009 (openregister, performance invariants): a relative-time condition
  is compiled into an indexed comparison, not evaluated per row in PHP.

## What openregister builds

- A named condition, reusable. A condition is saved under a name, with a
  description, and referenced from any rule, guard or field rule. Correcting
  it corrects every rule that uses it. A reference to a name that does not
  exist, and a cycle between named conditions, are refused at save.
- A rule that reads the transition, not only the state. A condition may read
  the value before the write and the value after it, so "moved into this
  status" is expressible and fires once. On a create there is no before
  value, and a condition that requires one is refused at save rather than
  silently never matching.
- Time relative to now, in the calendar's units. A condition may compare a
  date property to now with an offset in hours, working hours, calendar days
  or business days, resolved against the working calendar the record type
  already resolves. Escalation after three working hours is then a line an
  administrator edits.
- A check an administrator writes, with the sentence it says. A schema
  carries validations: a named condition, a severity of refuse or warn, the
  properties the message points at, and the message itself, translatable.
  A refusal returns the administrator's sentence, not a generic one. The
  validations run in the save pipeline, so the API, the import and a flow
  node all meet them.

## What dossiq consumes

dossiq declares its own named conditions, its transition-scoped automations
and its case-type validations with their Dutch messages, and renders the
message on the form. The register names `field-rules-declared` as dossiq's
consuming half of the rules cluster, and that change stands open on dossiq.
The four rows here have no dossiq slug of their own on dossiq `development`,
so the remainder is to be specified in dossiq. Beside dossiq: decidiq,
humaniq, pipelinq and shillinq, which all declare lifecycles.

## Size

L. A new mechanism on each of composition, transition-aware conditions,
relative time and administered validation, over one expression vocabulary.

## The specs this extends

- `specs/flow-engine` and the change `rules-engine-operability`
  (REQ-REO-001, REQ-REO-002, REQ-REO-004), which make the rules listable,
  logged and unskippable, and which say in their own "Out of scope" that a
  rule action making a field required is not theirs.
- `specs/object-lifecycle` and the change `field-rules-by-state`, whose
  requirement "A field rule may be conditional on the object's own data"
  gives a condition over the current object and no access to the prior
  value, no named reuse and no message of its own.
- `calc-engine-scalar-functions`, which is the expression vocabulary the
  named conditions and the time comparisons are written in.
