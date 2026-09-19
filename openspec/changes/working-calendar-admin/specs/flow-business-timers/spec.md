# flow-business-timers

## ADDED Requirements

### Requirement: Working calendars are administered under Nextcloud admin settings

The system SHALL offer an administrator a page under the OpenRegister admin
settings section that lists every working calendar, edits its
`workingWeekdays`, `hoursPerWorkingDay`, `rules`, `exceptions`, `title` and
`organisation`, and previews the non-working dates of a chosen year from the
unsaved definition. The page SHALL write through the objects API and SHALL
NOT keep a store of its own.

#### Scenario: an administrator adds a local closure day

- **GIVEN** the seeded `nl-national` calendar and an administrator
- **WHEN** the administrator adds an `exceptions` entry for 2027-05-05 and saves
- **THEN** `GET /api/objects/flow-timers/working-calendar/nl-national` returns the exception and a timer armed afterwards skips 2027-05-05
- Covered by `tests/e2e/ci/working-calendar-admin.spec.ts` (`@e2e flow-business-timers::an-administrator-adds-a-local-closure-day`), which seeds its own calendar rather than editing the seeded `nl-national` other suites depend on.

#### Scenario: the preview shows Koningsdag observed on the 26th

- **GIVEN** the `nl-national` rules and the year 2031, in which 27 April is a Sunday
- **WHEN** the administrator previews 2031
- **THEN** the list holds 2031-04-26 named Koningsdag and not 2031-04-27
- @e2e exclude {the preview reads WorkingCalendar::nonWorkingDates, covered by `WorkingCalendarYearBoundaryTest` and by Newman request 6, which asserts Koningsdag 2031 lands on the 26th}

### Requirement: Every write of a working calendar is validated the same way

The system SHALL run `WorkingCalendar::fromArray()` on every create and
update of a `working-calendar` object, whichever door the write came through,
and SHALL refuse with HTTP 422 and the validator's message when it throws. A
calendar consisting only of enumerated dates SHALL be refused at write time.

#### Scenario: an enumerated-only calendar is refused over the API

- **GIVEN** a request body with `workingWeekdays`, `hoursPerWorkingDay`, an empty `rules` list and twenty `exceptions`
- **WHEN** it is posted to `/api/objects/flow-timers/working-calendar`
- **THEN** the response is 422 and names the missing rules
- @e2e exclude {API contract, covered by Newman (`tests/newman/openregister-working-calendars.postman_collection.json`, request 2) and by `WorkingCalendarGuardListenersTest::testAnEnumeratedOnlyCalendarIsRefusedOnCreate`; the admin form's half is additionally asserted in `tests/e2e/ci/working-calendar-admin.spec.ts`}

### Requirement: Only an administrator writes a calendar, and a referenced one cannot be deleted

The `flow-timers` register SHALL grant read to authenticated users and
create, update and delete to administrators. Deleting a calendar named by an
armed or suspended timer SHALL be refused with the number of timers and up to
ten of their uuids.

#### Scenario: a calendar with an armed timer survives a delete

- **GIVEN** a calendar `gemeente-x` and one armed timer with `calendar_slug` `gemeente-x`
- **WHEN** an administrator deletes the calendar
- **THEN** the response is 409, names one timer, and the calendar still resolves
- @e2e exclude {delete guard, covered by `WorkingCalendarGuardListenersTest::testACalendarWithAnArmedTimerSurvivesTheDelete`, which asserts the 409, the count and the uuid sample}

### Requirement: The objects API is the public API of the calendar

The system SHALL serve working calendars, their hours and their holidays
through the objects API and SHALL NOT add a second calendar endpoint. The
API documentation SHALL name the register and schema.

#### Scenario: a script pushes a municipal holiday list

- **GIVEN** an administrator's API credentials and a calendar `gemeente-x`
- **WHEN** the script PUTs the calendar with three added `exceptions`
- **THEN** the next timer armed for that organisation skips the three dates
- @e2e exclude {API contract, covered by Newman requests 4 and 5, which PUT three closure days and read every one of them back}

### Requirement: A working calendar resolves per record type, unit and instance (REQ-WCA-005)

A schema and an organisational unit MAY each name a working calendar. The
engine SHALL resolve the calendar for a term in the order record type,
unit, instance, and SHALL fall through to the instance calendar when
nothing nearer names one. The resolved calendar SHALL be named in the
term's diagnostic.

#### Scenario: burgerzaken counts differently from vergunningen

- **GIVEN** one schema naming a counter-hours calendar and another naming none
- **WHEN** a term is computed on each
- **THEN** the first uses the counter-hours calendar and the second uses the instance calendar

#### Scenario: nothing declared behaves as today

- **GIVEN** an instance where no schema and no unit names a calendar
- **WHEN** terms are computed
- **THEN** the instance calendar is used throughout, unchanged from before this change

### Requirement: A person's working pattern is read from the app that owns it (REQ-WCA-006)

When a term is computed for a named person, the engine MAY consult that
person's working pattern from the app that owns working hours and
absences. OpenRegister SHALL NOT store a person's working hours or
absences. When no app answers, the resolved scope calendar SHALL decide,
and the diagnostic SHALL say which of the two applied.

#### Scenario: a part-time handler's term respects their pattern

- **GIVEN** an app answering with a person's working pattern
- **WHEN** a term is computed for that person
- **THEN** the pattern is applied and the diagnostic names it

#### Scenario: nothing answers, and the calendar decides

- **GIVEN** no app answering for that person
- **WHEN** the same term is computed
- **THEN** the scope calendar is applied and the diagnostic says so
- @e2e exclude {cross-app resolution, covered by unit tests with a fake provider}

### Requirement: A calendar declares blackout periods and its first week of the year (REQ-WCA-007)

A working calendar MAY declare blackout periods, each naming the kind of
work it refuses, and its first week of the year. A blackout SHALL refuse a
booking in that period without stopping a term from running, which is what
a closure day does. Week numbers the system reports SHALL follow the
declared first week.

#### Scenario: no hoorzitting in the stembusperiode

- **GIVEN** a blackout period refusing hearings
- **WHEN** a hearing is scheduled inside it
- **THEN** the booking is refused, naming the period

#### Scenario: a blackout does not stop the clock

- **GIVEN** the same blackout and a term running through it
- **WHEN** the term is computed
- **THEN** the days in the period count as they otherwise would

#### Scenario: week one is the organisation's week one

- **GIVEN** a declared first week of the year
- **WHEN** a week number is reported
- **THEN** it follows that declaration
- @e2e exclude {calendar arithmetic, covered by unit tests}
