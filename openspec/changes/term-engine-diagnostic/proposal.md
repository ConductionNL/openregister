---
kind: code
depends_on: [flow-business-timers, working-calendar-admin]
---

# Proposal: term-engine-diagnostic

## Summary

Let an administrator ask the term engine what it would do for a date they
choose, and read its working. `SlaCalculator` and `WorkingCalendar` compute
a fire moment from an anchor, a budget and a calendar; nothing exposes that
computation without arming a timer. This change adds a read-only diagnostic
endpoint and a panel on the calendar admin page that prints the walk.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q8.18 | Can an administrator run the term engine against a date they choose, and read its working | no | S |

## Why

The register's note: "Five working-day implementations in `lib/Service/`
that disagree with each other, and no surface that evaluates any of them."
The best competitor, verbatim from the `best` column: "osTicket 1.18:
ajax.php/schedule/{id}/diagnostic, include/ajax.schedule.php:100
(`_round4/compare/proposed-rows-batch3.md`)".

The register's `why`: "an engine that prints its working for a chosen date
is a diagnostic on SlaCalculator". The calculator already has every input;
the diagnostic asks it to narrate.

## What changes

- `POST /api/flow-timers/diagnostic` (admin) takes `calendar`, `anchorAt`,
  `sla` (`{value, unit, rollToWorkingDay}`) and optional `ladder`, and
  returns the fire moment, each day the walk skipped with the rule that
  skipped it, the roll applied, the zone, and, when a ladder is given, the
  instant of each rung.
- The endpoint arms nothing and writes nothing.
- A "Try a date" panel on the working calendar admin page calls it and
  renders the walk as a list.
- A deep link `?calendar=&sla=` so a consuming app can open the panel
  preselected.

## Consumers

- dossiq: a link from the termijn settings to the diagnostic with the
  `TermijnDefinitie` preselected. Specified in dossiq by the dossiq lane
  (register row Q8.18).
- Any app whose administrator has to explain a date to a citizen.

## ADRs

- ADR-079: an admin page under Nextcloud settings.
- ADR-098 decision 1.
- openregister ADR-001: a settings section, not a navigation entry.

## Impact

- Extends: `flow-business-timers` requirement "Business time is measured
  against ONE resolvable working calendar".
- Affected code: `lib/Controller/FlowTimerDiagnosticController.php`,
  `SlaCalculator` (a narrating variant of `add()`), the admin Vue surface
  of `working-calendar-admin`.
- Size: S.
