## ADDED Requirements

### Requirement: Read entries are shown only to the groups named for them

A `read` audit entry SHALL be given to a non-admin caller of an object's
history or of the readable feed only when the caller is in one of the groups
the setting `audit.readEventsVisibleTo` lists. An administrator SHALL always be
given read entries. With no group named, no non-admin caller SHALL be given
them. Without a user, with an unreadable setting or when the group lookup
fails, read entries SHALL NOT be given. An object's history SHALL answer
`readEventsVisible` saying whether its caller is given read entries, and its
`total` SHALL count only the entries the caller is given.

#### Scenario: a handler outside the named groups
- GIVEN `audit.readEventsVisibleTo` is `["dossiq-sensitive"]` and a case whose history holds a read and an update
- WHEN a handler who may read the case but is not in `dossiq-sensitive` reads its history
- THEN the history holds only the update, `total` is 1 and `readEventsVisible` is false

#### Scenario: a member of a named group
- GIVEN the same setting and case
- WHEN Pieter, in `dossiq-sensitive`, reads the history
- THEN it holds the read and the update and `readEventsVisible` is true

#### Scenario: an administrator
- GIVEN no group is named
- WHEN an administrator reads the history
- THEN read entries are given and `readEventsVisible` is true
