---
kind: code
depends_on: [flow-business-timers]
---

# Proposal: working-calendar-admin

## Summary

Give the one working calendar an administered surface. `flow-business-timers`
made the calendar a register object (`flow-timers` / `working-calendar`,
seeded `nl-national`) resolved by `WorkingCalendarService` in a fixed order.
Nothing administers it: no page lists the calendars, nothing edits a rule or a
closure day, and a write that arrives through the objects API is not run
through the validation that refuses an enumerated-only calendar. This change
adds the admin page under Nextcloud settings, the validation hook on every
write path, and the statement that the objects API is the public API for the
calendar.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 8.12 | One working-day calendar, administered in one place | no | M |
| Q8.20 | Is the working calendar, hours and holidays, readable and writable through the public API | partial | S |

Both rows are on openregister in `procest/_gaps/gap-register.md`
(market-intelligence, 2026-09-13). 8.12 is the first of the register's five
to build first.

## Why

The register's note on 8.12: "**five** private implementations, four
different definitions, none configurable, and no settings screen
(`grep -ril "holiday\|feestdag\|werkdag\|kalender" src/views/settings/`
returns nothing)". The best competitor, verbatim from the register's `best`
column: "osTicket 1.18: HolidaysSchedule, administered, several per
business-hours schedule (`_round4/compare/promoted-rows-batch3.md`)". On
Q8.20: "Odoo 19.0: resource.calendar.leaves is a model like any other,
reachable over RPC, driven in batch 6
(`_round4/compare/proposed-rows-batch8.md`)".

The engine half already exists. `WorkingCalendarService::resolve()` picks the
calendar named on the timer, else the organisation's, else `nl-national`
(`lib/Service/Flow/Timer/WorkingCalendarService.php`), and
`FlowTimerDefinitionStore` reads the definitions from the `flow-timers`
register through `ObjectService`. So the calendar is a register object today,
and the objects API already serves it. What is missing is a place a
functional administrator can open, and the guarantee that a write from
anywhere is refused when `WorkingCalendar::fromArray()` would refuse it.

## What changes

- An admin page under OpenRegister's Nextcloud admin settings
  (`/settings/admin/openregister`, ADR-079) lists every working calendar,
  edits weekdays, `hoursPerWorkingDay`, rules (`fixed`, `easter`,
  `observedShift`), `exceptions` and the owning organisation, and previews
  the non-working dates of a chosen year before saving.
- A schema hook on `working-calendar` runs `WorkingCalendar::fromArray()`
  on every create and update, so the objects API, the admin page and an
  import refuse the same calendar with the same message. A calendar that is
  only enumerated dates is refused (design D-5 of `flow-business-timers`).
- The `flow-timers` register's authorization block grants read to every
  authenticated user and create, update and delete to administrators only.
- The objects API is the public API: `GET /api/objects/flow-timers/working-calendar`
  and the per-object routes. This change documents it and adds a Newman
  case; it adds no second endpoint.
- A calendar that is referenced by an armed timer cannot be deleted; the
  refusal names the timers.

## Consumers

- dossiq: every dossiq clock reads the engine calendar (termijnbewaking
  phases 1 to 4); dossiq keeps no calendar of its own. dossiq's half of Q8.20
  is to push the municipal holiday list through the API instead of typing
  it. Both are specified in dossiq by the dossiq lane (register rows 8.12
  and Q8.20; the existing change is `termijnbewaking-op-engine-timers`,
  which leaves the admin surface to this change).
- shillinq, integriq, humaniq: every app with a business timer reads the
  same calendar and gains the same page.

## ADRs

- ADR-022 (apps consume OpenRegister abstractions): one calendar, consumed.
- ADR-031 (schema-declarative business logic): the calendar stays data.
- ADR-079 (settings surface placement): instance configuration lives under
  Nextcloud admin settings.
- ADR-076 (settings plane): the page rides `GenericAdminSettings`.
- openregister ADR-001 (information architecture): the page is a settings
  section, not a navigation entry.

## Impact

- Extends: `openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md`,
  requirement "Business time is measured against ONE resolvable working
  calendar".
- Affected code: `lib/Settings/OpenRegisterAdmin.php` (section), a
  `WorkingCalendars` admin Vue surface, `lib/Service/Flow/Timer/`, a schema
  hook, `lib/Settings/flow_timer_register.json` (authorization block).
- Backwards compatible: existing calendars validate today.
- Size: M.
