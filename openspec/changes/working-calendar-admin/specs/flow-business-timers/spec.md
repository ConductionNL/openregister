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
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/working-calendar-admin.spec.ts when the page ships}

#### Scenario: the preview shows Koningsdag observed on the 26th

- **GIVEN** the `nl-national` rules and the year 2031, in which 27 April is a Sunday
- **WHEN** the administrator previews 2031
- **THEN** the list holds 2031-04-26 named Koningsdag and not 2031-04-27
- @e2e exclude {the preview reads WorkingCalendar::nonWorkingDates, covered by calendar unit tests}

### Requirement: Every write of a working calendar is validated the same way

The system SHALL run `WorkingCalendar::fromArray()` on every create and
update of a `working-calendar` object, whichever door the write came through,
and SHALL refuse with HTTP 422 and the validator's message when it throws. A
calendar consisting only of enumerated dates SHALL be refused at write time.

#### Scenario: an enumerated-only calendar is refused over the API

- **GIVEN** a request body with `workingWeekdays`, `hoursPerWorkingDay`, an empty `rules` list and twenty `exceptions`
- **WHEN** it is posted to `/api/objects/flow-timers/working-calendar`
- **THEN** the response is 422 and names the missing rules
- @e2e exclude {API contract, covered by Newman and the hook's unit test}

### Requirement: Only an administrator writes a calendar, and a referenced one cannot be deleted

The `flow-timers` register SHALL grant read to authenticated users and
create, update and delete to administrators. Deleting a calendar named by an
armed or suspended timer SHALL be refused with the number of timers and up to
ten of their uuids.

#### Scenario: a calendar with an armed timer survives a delete

- **GIVEN** a calendar `gemeente-x` and one armed timer with `calendar_slug` `gemeente-x`
- **WHEN** an administrator deletes the calendar
- **THEN** the response is 409, names one timer, and the calendar still resolves
- @e2e exclude {delete guard, covered by the hook's unit test}

### Requirement: The objects API is the public API of the calendar

The system SHALL serve working calendars, their hours and their holidays
through the objects API and SHALL NOT add a second calendar endpoint. The
API documentation SHALL name the register and schema.

#### Scenario: a script pushes a municipal holiday list

- **GIVEN** an administrator's API credentials and a calendar `gemeente-x`
- **WHEN** the script PUTs the calendar with three added `exceptions`
- **THEN** the next timer armed for that organisation skips the three dates
- @e2e exclude {API contract, covered by Newman}
