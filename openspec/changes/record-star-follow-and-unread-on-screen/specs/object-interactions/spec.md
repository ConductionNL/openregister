# object-interactions

## ADDED Requirements

### Requirement: The record page shows one Follow control

OpenRegister's record page SHALL show, beside the record's title, one Follow control bound to `@self.watching`, with a notifications switch bound to `@self.watchNotify` and the follower count from `@self.watcherCount`. It SHALL show no star. Following, unfollowing and switching notifications SHALL call `PUT .../watch` (with `{"notify": bool}`) or `DELETE .../watch` for the current user and SHALL revert the control with the server's message when the call fails. When the record's schema has no notification rule that targets watchers, the Follow toggle SHALL say that following adds the record to the Following list and sends no change notifications.

#### Scenario: a user follows a record quietly from its page

- **GIVEN** a record the user may read and does not follow
- **WHEN** the user clicks Follow and then turns notifications off
- **THEN** `PUT .../watch` is sent with `{"notify": false}`, the control shows following without notifications, and a reload of the page still shows that
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/record-star-follow-and-unread.spec.ts}

#### Scenario: a failed follow does not pretend

- **GIVEN** a record the user may read
- **WHEN** the user clicks Follow and the watch route answers with an error
- **THEN** the toggle returns to not following and the page shows the server's message
- @e2e exclude {specified only; covered by the component test of the toggle with a failing request}

### Requirement: The Tables page filters on following and recent

The Tables page SHALL offer quick filters Following and Recent that add `_watching=true` and `_recent=true` to the list query, combined with every other filter on the page, and SHALL show a follow column the user can toggle per row. It SHALL NOT offer a separate Favourites filter. While Recent is on, the list SHALL keep the order the lens returns.

#### Scenario: the open requests I follow

- **GIVEN** a user who follows three records of a schema, two of them with status `open` and one of those quietly
- **WHEN** the user filters the Tables page on that schema with status `open` and turns on Following
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

