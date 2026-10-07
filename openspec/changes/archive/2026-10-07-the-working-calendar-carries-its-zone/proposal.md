---
kind: capability
---

# Proposal: the-working-calendar-carries-its-zone

Gap register row Q8.19, "Is the buyer's own time zone accepted where a
calendar or a term is configured", owner openregister.

## Why

A calendar date is not an instant. "The term ends on 2 June" becomes a moment
only once somebody says where midnight is, and nothing in the timer vocabulary
said. So a term computed at 23:30 UTC lands a day early for an organisation in
Amsterdam, the same calendar counts different days on two servers, and neither
reports anything: the server's `date_default_timezone` answered by default and
that setting is invisible from the data.

Dossiq's `terms-on-the-engine-calendar` needs the answer to build statutory
term dates in the right day, and it must come from the calendar rather than
from dossiq, because ADR-022 puts the calendar here.

## What changes

- `WorkingCalendar` carries `timezone`, an IANA name, defaulting to `UTC`.
  A zone that does not resolve is refused by name rather than coerced.
- The seeded `nl-national` calendar declares `Europe/Amsterdam`.
- The admin preview echoes the validated zone, as it already echoes the
  weekdays and the opening time.

UTC is the default rather than the server's setting on purpose: it is the one
answer that is the same on every instance, and an organisation that needs
another says so.

It is the ORGANISATION's zone and not the viewer's. A display preference must
not move a statutory deadline, or two handlers on one case would be owed
different days and the one who travelled would be right.

## Impact

`lib/Service/Flow/Timer/WorkingCalendar.php`,
`lib/Controller/WorkingCalendarController.php`,
`lib/Settings/flow_timer_register.json`.

## Capabilities

- Modified: `flow-business-timers`: a calendar says which zone its days are
  counted in.
