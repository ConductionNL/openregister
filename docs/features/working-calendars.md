# Working calendars

Every deadline OpenRegister measures is counted against one working calendar:
which weekdays are worked, how long a working day is, and which days are
closed. There is one calendar per organisation at most, and one national
default, so two apps on the same instance cannot disagree about what a working
day is.

## Why the holidays are rules and not a list

A calendar whose holidays are a list of dates has an expiry date. Past the last
date somebody typed, it reports a working day for every holiday, silently, with
no error and no log line. That is the failure mode this design refuses: a
calendar made only of enumerated dates is rejected at write time.

Instead a holiday is a rule, and the rule is computed every year:

| kind | what it means | example |
|---|---|---|
| `fixed` | a month and a day, every year | Nieuwjaarsdag, 1 January |
| `easter` | a number of days from Easter Sunday | Hemelvaartsdag, 39 days after |
| `observedShift` | a fixed date that moves when it falls on a named weekday | Koningsdag, 27 April, observed on the 26th when the 27th is a Sunday |

Easter is computed with the anonymous Gregorian algorithm, so Goede Vrijdag,
Tweede Paasdag, Hemelvaartsdag and Tweede Pinksterdag are right in any year,
including years nobody has written a table for.

Single closure days are still possible. `exceptions` holds dates that are
closed once, such as a local anniversary, and they may sit alongside rules. They
may not be the whole calendar.

## Where the calendars live

A working calendar is an object, not a table of its own:

- register: `flow-timers`
- schema: `working-calendar`

That means the objects API is the calendar API. There is no second endpoint and
no second set of permissions.

```
GET    /apps/openregister/api/objects/flow-timers/working-calendar
GET    /apps/openregister/api/objects/flow-timers/working-calendar/{id}
POST   /apps/openregister/api/objects/flow-timers/working-calendar
PUT    /apps/openregister/api/objects/flow-timers/working-calendar/{id}
DELETE /apps/openregister/api/objects/flow-timers/working-calendar/{id}
```

Reading is open to any signed-in user, because every app that arms a deadline
has to resolve the calendar. Creating, changing and deleting one is for
administrators.

### The shape of a calendar

```json
{
  "slug": "gemeente-example",
  "title": "Gemeente Example",
  "organisation": "1f2e3d4c-5b6a-7980-9a8b-7c6d5e4f3a2b",
  "workingWeekdays": [1, 2, 3, 4, 5],
  "hoursPerWorkingDay": 8,
  "rules": [
    { "kind": "fixed", "month": 1, "day": 1, "name": "Nieuwjaarsdag" },
    { "kind": "easter", "offset": -2, "name": "Goede Vrijdag" },
    { "kind": "easter", "offset": 1, "name": "Tweede Paasdag" },
    {
      "kind": "fixed", "month": 4, "day": 27, "name": "Koningsdag",
      "observedShift": { "whenWeekday": "sunday", "days": -1 }
    },
    { "kind": "easter", "offset": 39, "name": "Hemelvaartsdag" },
    { "kind": "easter", "offset": 50, "name": "Tweede Pinksterdag" },
    { "kind": "fixed", "month": 12, "day": 25, "name": "Eerste Kerstdag" },
    { "kind": "fixed", "month": 12, "day": 26, "name": "Tweede Kerstdag" }
  ],
  "exceptions": [
    { "date": "2027-08-17", "name": "Kermis" }
  ]
}
```

`workingWeekdays` is ISO numbered, so 1 is Monday and 7 is Sunday.
`hoursPerWorkingDay` is required: without it a term in hours and a term in
working days cannot be compared.

### Pushing a municipal holiday list

Adding a municipality's closure days is a normal write. A script reads the list
it already maintains and PUTs it:

```bash
curl -u admin:admin -X PUT \
  -H 'Content-Type: application/json' \
  --data @gemeente-example.json \
  "$BASE/apps/openregister/api/objects/flow-timers/working-calendar/$ID"
```

Deadlines armed after the write skip the new dates.

## What happens when a calendar is wrong

The same validator runs whichever door the write came through: the admin page,
the objects API and a configuration import. A rejected write answers 422 and
names the fault, for example:

```
Working calendar 'gemeente-example' consists only of enumerated dates and
would expire after '2027-12-26'; declare computed rules.
```

Deleting a calendar that armed or suspended deadlines still refer to answers
409, with the number of those deadlines and up to ten of their identifiers. The
resolver refuses a calendar it cannot find rather than quietly counting
weekdays, so deleting one out from under a running deadline would break it
rather than degrade it.

## Administering one

The calendars are listed under settings, admin, Open Register, in the working
calendars section. The form edits the working week, the hours in a day, the
rules and the closure days, and previews a year before you save:

```
POST /apps/openregister/api/flow-timers/calendars/preview
{ "year": 2031, "calendar": { ... } }
```

The preview runs the rules over the year and returns the dates. It stores
nothing, so you can read what a rule means before committing to it. It is the
one endpoint outside the objects API, and it is a read.

One thing the preview cannot do yet: deadlines that are already running keep
the dates they were given when they were armed. Changing a rule applies to
deadlines started after the change. Recomputing the ones already running is a
separate piece of work.

## How an app resolves a calendar

In this order, and it never falls back to weekdays:

1. the calendar named on the deadline
2. the calendar configured for the subject's organisation
3. the seeded national default, `nl-national`

A name that resolves to nothing is an error at the moment the deadline is set,
with the name in the message. That is deliberate: a quiet downgrade to weekdays
produces a deadline that is wrong by several days and looks entirely healthy.
