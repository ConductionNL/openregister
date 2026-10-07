# object-interactions

## ADDED Requirements

### Requirement: The record page shows a star and a Follow toggle

OpenRegister's record page SHALL show, beside the record's title, a star bound to `@self.favourite` and a Follow toggle bound to `@self.watching` with the follower count from `@self.watcherCount`. Toggling SHALL call the favourite or watch route for the current user and SHALL revert the toggle with the server's message when the call fails. When the record's schema has no notification rule that targets watchers, the Follow toggle SHALL say that following adds the record to the Following list and sends no change notifications.

#### Scenario: a user stars a record from its page

- **GIVEN** a record the user may read and has not starred
- **WHEN** the user clicks the star on the record page
- **THEN** `PUT .../favourite` is sent, the star shows filled, and a reload of the page still shows it filled
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/record-star-follow-and-unread.spec.ts}

#### Scenario: a failed follow does not pretend

- **GIVEN** a record the user may read
- **WHEN** the user clicks Follow and the watch route answers with an error
- **THEN** the toggle returns to not following and the page shows the server's message
- @e2e exclude {specified only; covered by the component test of the toggle with a failing request}

### Requirement: The Tables page filters on favourites, recent and following

The Tables page SHALL offer quick filters Favourites, Recent and Following that add `_favourite=true`, `_recent=true` and `_watching=true` to the list query, combined with every other filter on the page, and SHALL show a star column the user can toggle per row. While Recent is on, the list SHALL keep the order the lens returns.

#### Scenario: my favourite open requests

- **GIVEN** a user who starred three records of a schema, two of them with status `open`
- **WHEN** the user filters the Tables page on that schema with status `open` and turns on Favourites
- **THEN** the list shows exactly those two records and the total says 2
- @e2e exclude {specified only; task 3.1 covers it}

### Requirement: The record read says whether the reader may change who follows it

When a read or list asks for `_extend=@self.can`, each object's `@self.can` SHALL carry `manage` next to `update`. `manage` SHALL be true exactly when `PUT` and `DELETE .../watchers/{userId}` for another user would be admitted for the caller: the object's owner or an administrator, decided by the same `ObjectScopeResolver::admitsUnconditionally` call that `WatcherService::requireManage` makes. It SHALL NOT be derived from the schema's RBAC rules, because `manage` is not one of core's five RBAC verbs (ADR-010 Rule 4). When the decision cannot be taken, `manage` SHALL be false.

#### Scenario: the owner may add a colleague

- **GIVEN** a record owned by `annemarie`
- **WHEN** `annemarie` reads it with `_extend=@self.can`
- **THEN** `@self.can` is `{"update": true, "manage": true}`
- @e2e exclude {backend marker; covered by a unit test on RenderObject and the Newman request in task 1.3}

#### Scenario: an editor who does not own the record may not

- **GIVEN** a record owned by `annemarie` that `jan` may update through the schema's RBAC rules
- **WHEN** `jan` reads it with `_extend=@self.can`
- **THEN** `@self.can.update` is true and `@self.can.manage` is false
- **AND** `PUT .../watchers/piet` by `jan` answers 403
- @e2e exclude {backend marker; covered by a unit test that runs the marker and the endpoint against one fixture}

#### Scenario: no extend, no marker

- **GIVEN** any record
- **WHEN** it is read without `_extend=@self.can`
- **THEN** the response carries no `@self.can`
- @e2e exclude {backend marker; covered by a unit test on RenderObject}

