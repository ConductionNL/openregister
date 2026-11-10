---
kind: code
depends_on: [working-calendar-admin, calendar-time-zone, notification-scheduled-filter-grammar]
---

# Proposal: service-hours-and-repeating-reminders

## Summary

Two clocks are missing. The deadline clock counts whole days, so a promise
measured in hours cannot be kept and a counter that opens on Tuesday
afternoon is the same calendar as one that opens all week. The reminder
clock fires once, from a fixed anchor, and cannot stop itself when the thing
it is chasing arrives. This change gives the calendar service hours inside
the day, and gives a reminder any date property as its anchor, a repeat and
a stop condition.

## The rows this closes

### Row 8.26, deadline clock running only in service hours, with more than one set of hours, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 8.18`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **8.26** | 8.18 | Deadline clock running only in service hours, with more than one set of hours | no | unread | corpus 8.12 |
```

- ledger note, verbatim:

> WorkingDayCalculator is day-level by design and there is one global calendar. Row 8.12 asks for that one calendar to be administered; this asks for hours inside the day and for more than one set of them.

### Row 8.28, reminder bound to any date field, with a repeat interval and a stop condition, rated `partial`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 8.20`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **8.28** | 8.20 | Reminder bound to any date field, with a repeat interval and a stop condition | partial | unread |  |
```

- ledger note, verbatim:

> Row 8.4 reminders attach to a case with a responsible user. They cannot bind to an arbitrary date field, cannot repeat, and cannot stop themselves when the thing they chase is done.

## What the competitor evidence is

Both rows are among the 98 promoted under decision D1, and the corpus states
what its competitor columns hold, verbatim:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here. The cross-reference on 8.26 is `corpus
8.12`, the administered-calendar row, which is `working-calendar-admin` in
this repository and a dependency of this change. 8.28 carries no
cross-reference.

## ADRs

- ADR-031 (schema-declarative business logic): the service hours are data on
  the calendar object and the reminder is a declared rule, not a job class
  per reminder.
- ADR-022 (apps consume OpenRegister abstractions): one clock for the fleet.
  An app that writes its own hour arithmetic gets a different answer from
  the engine, which is how two screens disagree about the same deadline.
- ADR-009 (openregister, performance invariants): a repeating reminder is
  swept by index off a due column, never by scanning the objects of a
  schema.

## What openregister builds

- Service hours on the working calendar. A calendar declares, per weekday,
  the windows during which the clock runs, in the calendar's own time zone.
  A term expressed in hours advances only inside those windows, so four
  service hours from Friday at 16:00 lands on Monday morning and not on
  Saturday.
- More than one set of hours, resolved the way calendars already resolve.
  `working-calendar-admin` REQ-WCA-005 resolves a calendar per record type,
  unit and instance. The service hours ride that resolution, so the
  counter's hours and the back office's hours are two calendars, not a
  branch in the code.
- A diagnostic that names the hours. When a term is computed, the answer
  says which calendar and which windows produced it. A deadline nobody can
  explain is a deadline nobody trusts.
- A reminder anchored to any date property. A reminder declares the property
  it counts from, an offset before or after, a repeat interval and a
  maximum number of repeats. `hoursPerWorkingDay` alone cannot express any
  of that.
- A stop condition. A reminder declares the condition that ends it: the
  property it was chasing is filled, a state is reached, or the object is
  closed. The reminder stops itself and records why, so it does not have to
  be switched off by hand on every object.

## What dossiq consumes

dossiq declares its service hours per case type through the calendar it
names, and declares its reminders against the date properties on the case,
with the stop condition on the property the reminder chases. The register's
`dossiq_half` for the neighbouring calendar row is
`every-term-on-the-engine-calendar`; for these two rows no dossiq slug
exists on dossiq `development`, so the consuming half is to be specified in
dossiq. Beside dossiq: humaniq and shillinq for the hours, pipelinq and
keepiq for the reminders.

## Size

M. One arithmetic change inside an existing calculator, one declaration on
an existing calendar object, and a reminder rule over the scheduled trigger
that already exists.

## The specs this extends

- `flow-business-timers`, requirement "Business time is measured against ONE
  resolvable working calendar" and the change `working-calendar-admin`
  (REQ-WCA-005, REQ-WCA-007), whose calendar object carries
  `hoursPerWorkingDay` and no windows.
- `specs/notificatie-engine`, requirements "Scheduled trigger filters MUST
  support relative-date and inequality operators" and "Scheduled rules MUST
  deduplicate dispatch per object and re-arm on watched-field change".
