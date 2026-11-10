# Design: calendar-time-zone

## D-1: the zone is the calendar's, not the user's

Two users in two zones reading one term must agree on its end date. So the
zone is a property of the calendar the term was measured against, never of
the reader. The reader's zone is a display concern the client handles from
the instant.

## D-2: instants stay UTC in storage

`fire_at`, `running_since` and the ledger stay UTC instants. Only the day
walk and the reporting change: `SlaCalculator` converts to the calendar's
zone, walks whole days at local midnight, and converts back. A DST change
inside a walk therefore costs or gains an hour on the instant, which is the
correct answer for "same time of day, n days later".

## D-3: one validator

`lib/Formats/TimeZoneFormat.php` validates an IANA identifier through
`DateTimeZone`, reused by the schema hook of `working-calendar-admin` and by
any schema property that declares `format: time-zone` (ADR-008).

## D-4: kind

Code, in OpenRegister.
