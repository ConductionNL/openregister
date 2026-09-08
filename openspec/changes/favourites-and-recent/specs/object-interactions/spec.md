# object-interactions

## ADDED Requirements

### Requirement: A user can star an object without changing it

The system SHALL let a user mark any object they may read as a favourite and
unmark it, storing the mark per user outside the object, so that starring
writes no audit entry and no version on the object. Object reads and lists
SHALL carry `@self.favourite` for the current user.

#### Scenario: starring leaves the object untouched

- **GIVEN** an object with three audit entries
- **WHEN** a user stars it and reads it back
- **THEN** `@self.favourite` is true and the object still has three audit entries
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/ci/favourites-and-recent.spec.ts when the endpoints ship}

### Requirement: Opening an object records a per-user view

The system SHALL record a view (user, object, time) when a user reads an
object's detail, at most once per user, object and minute, keeping the most
recent 100 views per user.

#### Scenario: repeated reads within a minute count once

- **GIVEN** a user who reads the same object four times in ten seconds
- **WHEN** their view history is listed
- **THEN** the object appears once with the time of the first read
- @e2e exclude {the throttle window is a service boundary covered by unit tests}

### Requirement: Favourites and recent are lenses on the object query

The object query SHALL accept `_favourite=true`, returning only the current
user's starred objects, and `_recent=true`, returning the current user's
viewed objects ordered by last view descending, each composing with every
other filter.

#### Scenario: a favourites chip on an index page

- **GIVEN** a user who starred two of five cases
- **WHEN** the index page queries with `_favourite=true` and `status=open`
- **THEN** only the starred cases with status open are returned
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/ci/favourites-and-recent.spec.ts when the lenses ship}
