---
kind: code
depends_on: [flow-business-timers]
---

# Proposal: end-date-roll-on-the-calendar

## Summary

Let a timer declare what happens when its end date lands on a non-working
day. Today `SlaCalculator::add()` walks business days for a `businessDays`
budget and adds plain time for `calendarDays` and `hours`, so a
`calendarDays` term that ends on a Sunday ends on a Sunday. The Algemene
termijnenwet says a statutory term ending on a Saturday, Sunday or public
holiday runs until the next working day. This change adds one option,
`rollToWorkingDay`, on the SLA shape, evaluated against the same calendar.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 8.11 | Term end date moved off a weekend or public holiday | no | S |

The row is statutory in effect: dossiq turns the option on for every
`TermijnDefinitie` whose legal basis is the Algemene termijnenwet. The
register's README measured it: "30.4% of the terms dossiq stores land on a
day the Algemene termijnenwet says must move."

## Why

The register's note: "`lib/Service/TermijnService.php:109` computes
`$startDate->modify('+N days')` and stops.
`grep -rinE "termijnenwet|algemene termijnen|\batw\b" lib/ src/` returns
**zero hits**." The best competitor, verbatim from the `best` column: "GLPI
11 Calendar::computeEndDate; osTicket measured on a Dutch calendar
(`_round4/compare/promoted-rows-batch3.md`)".

The calendar the roll needs already exists (`WorkingCalendar::isWorkingDay()`)
and the arithmetic sits in one class (`SlaCalculator`). The roll is a rule
on top of `add()`, not a new clock.

## What changes

- The SLA shape `{value, unit}` accepts an optional `rollToWorkingDay` of
  `none` (default), `next` or `previous`. `next` is the Algemene
  termijnenwet rule.
- `SlaCalculator::add()` applies the roll after the budget is added, against
  the calendar the timer resolved. `businessDays` never needs it (the walk
  already ends on a working day) and the option is accepted and ignored.
- `FlowTimerService::describe()` reports the unrolled date and the rolled
  date when they differ, with the name of the rule that moved it, so a
  handler sees why a term ends on Monday.
- `extend()` and `supersede()` re-apply the roll on the recomputed moment.
- The escalation constraint (design D-6) compares against the rolled
  `fire_at`.

## Consumers

- dossiq: `rollToWorkingDay: next` on every `TermijnDefinitie` with
  `legalBasis` Algemene termijnenwet, confirmed by someone who reads the
  Atw. Specified in dossiq by the dossiq lane (register row 8.11 and the
  sibling row 8.16 on dossiq).
- shillinq (submission windows), humaniq (leave and payroll terms): the same
  option where a term has legal effect.

## ADRs

- ADR-022: the rule lives in the engine, consumed by every app.
- ADR-098 (fleet workflow convergence): one clock, decision 1.
- ADR-031: the option is data on the timer configuration.

## Impact

- Extends: `flow-business-timers` requirement "Business time is measured
  against ONE resolvable working calendar".
- Affected code: `lib/Service/Flow/Timer/SlaCalculator.php`,
  `FlowTimerService.php` (`describe`, `extend`, `supersede`), the SLA
  validator, the timer event ledger.
- Backwards compatible: the default is `none`.
- Size: S.
