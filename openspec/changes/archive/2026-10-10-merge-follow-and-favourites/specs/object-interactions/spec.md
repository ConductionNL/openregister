# object-interactions

## MODIFIED Requirements

### Requirement: A user can star an object without changing it

The star is retired: starring is following with notifications off. For one release the system SHALL keep the favourite surface as a deprecated alias of following. `PUT .../favourite` SHALL follow the object for the current user with notifications off when they do not follow it yet, and SHALL leave an existing follow and its notification setting unchanged. `DELETE .../favourite` SHALL unfollow. Both SHALL answer with a `Deprecation: true` header and a `Link` header naming `.../watch` as `successor-version`. Object reads and lists SHALL carry `@self.favourite` with the same value as `@self.watching`. Neither route SHALL write an audit entry or a version on the object.

#### Scenario: starring leaves the object untouched

- **GIVEN** an object with three audit entries
- **WHEN** a user stars it and reads it back
- **THEN** `@self.favourite` and `@self.watching` are true, `@self.watchNotify` is false, and the object still has three audit entries
- @e2e tests/e2e/ci/favourites-and-recent.spec.ts

#### Scenario: starring an object you follow keeps your notifications

- **GIVEN** a user who follows an object with notifications on
- **WHEN** the user calls `PUT .../favourite`
- **THEN** `@self.watchNotify` is still true and the response carries a `Deprecation` header
- @e2e exclude {the alias is removed next release; asserted by FavouriteServiceTest::testStarringAFollowedObjectKeepsItsNotifySetting}

### Requirement: Favourites and recent are lenses on the object query

The object query SHALL accept `_recent=true`, returning the current user's viewed objects ordered by last view descending, composing with every other filter. For one release it SHALL also accept `_favourite=true` as a deprecated alias that returns exactly what `_watching=true` returns.

#### Scenario: a favourites chip on an index page

- **GIVEN** a user who follows two of five cases, one of them through a former favourite
- **WHEN** the index page queries with `_favourite=true` and `status=open`
- **THEN** the result equals the one for `_watching=true` and `status=open`
- @e2e tests/e2e/ci/favourites-and-recent.spec.ts

### Requirement: A user can watch an object they may read

The system SHALL let a user who may read an object follow it and unfollow it, storing the follow per user outside the object so that following writes no audit entry and no version on the object. Every follow SHALL carry a notification switch. `PUT .../watch` SHALL accept an optional body `{"notify": true|false}`: given, it sets the switch; absent, a new follow notifies and an existing follow keeps its setting. Object reads and lists SHALL carry `@self.watching` and, when the caller follows the object, `@self.watchNotify` for the current user only.

#### Scenario: following leaves the object untouched

- **GIVEN** an object with three audit entries
- **WHEN** a user watches it and reads it back
- **THEN** `@self.watching` and `@self.watchNotify` are true and the object still has three audit entries
- `@e2e tests/e2e/ci/object-watchers.spec.ts`

#### Scenario: a user without read cannot watch

- **GIVEN** an object the user may not read
- **WHEN** the user calls the watch endpoint
- **THEN** the response is 404 and no row is written
- `@e2e tests/e2e/ci/object-watchers.spec.ts` and WatcherService unit tests

#### Scenario: a user turns notifications off and keeps following

- **GIVEN** a user who follows an object with notifications on
- **WHEN** the user sends `PUT .../watch` with `{"notify": false}`
- **THEN** `@self.watching` is true, `@self.watchNotify` is false, and a rule addressed to watchers no longer reaches them
- @e2e exclude {notification delivery runs through a background job; asserted by WatcherServiceTest::testWatchWithNotifyFalseKeepsTheFollowQuiet and NotificationRecipientResolverWatchersTest}

### Requirement: Watchers are a lens and a list

The object query SHALL accept `_watching=true` returning the current user's followed objects, resolved inside the query as an `EXISTS` on the follow table so the page, the total and the facets see one restriction. An anonymous caller SHALL get an empty page. `GET .../watchers` SHALL list an object's followers, whatever their notification setting, for a user with `update`, without anybody's notification setting; a user with `manage` SHALL be able to add or remove another user, and any follower SHALL be able to remove themselves.

#### Scenario: a team lead lists who follows a case

- **GIVEN** a case followed by two users, one of them with notifications off, and a team lead with `update`
- **WHEN** the team lead lists the watchers
- **THEN** both users are returned with the time they subscribed and no notification setting
- `@e2e tests/e2e/ci/object-watchers.spec.ts` and tests/newman/openregister-object-watchers.postman_collection.json

#### Scenario: the lens is resolved in the query

- **GIVEN** a signed-in user
- **WHEN** a query asks `_watching=true`
- **THEN** the query carries the caller's uid as `_watchingFor` and no uuid list on `_ids`
- @e2e exclude {query construction; asserted by SearchQueryHandlerWatchingLensTest}

## ADDED Requirements

### Requirement: Favourites became follows with notifications off

The upgrade that ships this change SHALL move every favourite into the follow table with notifications off and SHALL then drop the favourites table. When a user had both starred and followed an object, the follow SHALL keep notifications on.

#### Scenario: a favourite survives the upgrade as a quiet follow

- **GIVEN** a user who starred case A and both starred and followed case B
- **WHEN** the instance upgrades
- **THEN** the user follows A with notifications off and B with notifications on, and `openregister_favourites` no longer exists
- @e2e exclude {an upgrade step has no HTTP surface; asserted by Version1Date20261009130000Test}

### Requirement: Being assigned an object follows it with notifications on

When a create or update sets the property a schema marks `x-openregister-role: assignee` to a Nextcloud user who may read the object, the system SHALL make that user follow the object with notifications on, switching an existing quiet follow on. A value that is not a Nextcloud user SHALL be skipped. A reassignment SHALL NOT unfollow the previous assignee. `WatcherService::followAssigned()` SHALL offer the same act to apps whose assignment is not an object property.

#### Scenario: a handler is assigned a case

- **GIVEN** a case schema whose `assignee` property carries `x-openregister-role: assignee`
- **WHEN** a case is saved with `assignee` set to `jan`, who may read it
- **THEN** `jan` follows the case with notifications on
- @e2e exclude {an event listener; asserted by AssigneeFollowListenerTest::testANewAssigneeFollowsWithNotificationsOn}

#### Scenario: a group in the assignee property is not a follower

- **GIVEN** the same schema
- **WHEN** a case is saved with `assignee` set to the group id `behandelaars`
- **THEN** no follow is written
- @e2e exclude {asserted by AssigneeFollowListenerTest::testAValueThatIsNotAUserIsSkipped}
