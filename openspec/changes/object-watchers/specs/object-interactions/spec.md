# object-interactions

## ADDED Requirements

### Requirement: A user can watch an object they may read

The system SHALL let a user who may read an object subscribe to it and
unsubscribe, storing the subscription per user outside the object so that
watching writes no audit entry and no version on the object. Object reads
and lists SHALL carry `@self.watching` for the current user.

#### Scenario: following leaves the object untouched

- **GIVEN** an object with three audit entries
- **WHEN** a user watches it and reads it back
- **THEN** `@self.watching` is true and the object still has three audit entries
- `@e2e tests/e2e/ci/object-watchers.spec.ts`

#### Scenario: a user without read cannot watch

- **GIVEN** an object the user may not read
- **WHEN** the user calls the watch endpoint
- **THEN** the response is 404 and no row is written
- `@e2e tests/e2e/ci/object-watchers.spec.ts` and WatcherService unit tests

### Requirement: Watchers are a lens and a list

The object query SHALL accept `_watching=true` returning the current user's
watched objects. `GET .../watchers` SHALL list an object's watchers for a
user with `update`; a user with `manage` SHALL be able to add or remove
another user, and any watcher SHALL be able to remove themselves.

#### Scenario: a team lead lists who follows a case

- **GIVEN** a case watched by two users and a team lead with `update`
- **WHEN** the team lead lists the watchers
- **THEN** both users are returned with the time they subscribed
- `@e2e tests/e2e/ci/object-watchers.spec.ts` and tests/newman/openregister-object-watchers.postman_collection.json

### Requirement: Deleting an object removes its watchers

Deleting an object SHALL delete its watcher rows.

#### Scenario: no orphan subscriptions

- **GIVEN** an object with four watchers
- **WHEN** it is deleted permanently
- **THEN** the watcher table holds no row for it
- @e2e exclude {deletion cleanup has no HTTP surface to read; asserted by WatcherServiceTest::testDeletingAnObjectRemovesItsWatchers and wired by WatcherPruneListener}
