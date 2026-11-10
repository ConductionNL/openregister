# saved-search-views Delta: saved-view-user-shares

## ADDED Requirements

### Requirement: A view can be shared with users in read or write mode

`sharedWith` SHALL accept entries `{user, mode}` beside `{group, mode}`. A user
named in an entry SHALL get that mode's access when listing and reading the
view. An entry naming both `user` and `group`, neither, a user that does not
exist, or a user twice SHALL be refused with 400 naming the entry. Only the
owner or an administrator changes `sharedWith`, as for groups.

#### Scenario: a named user sees the view read-only

- **GIVEN** a view owned by A with `sharedWith: [{ user: "fatma", mode: "read" }]`
- **WHEN** fatma lists views
- **THEN** the view is returned with `@self.access` `read`
- @e2e exclude {covered by ViewShareResolver and controller unit tests}

#### Scenario: a user share in write mode may update the query, not the audience

- **GIVEN** the same view shared `{ user: "fatma", mode: "write" }`
- **WHEN** fatma PATCHes `query`, then PATCHes `sharedWith`
- **THEN** the first succeeds and the second is 403
- @e2e exclude {guard, covered by controller unit tests}

#### Scenario: an unknown user is refused

- **GIVEN** the owner
- **WHEN** the owner PATCHes `sharedWith: [{ user: "nobody", mode: "read" }]`
- **THEN** the response is 400 naming the entry and `sharedWith` is unchanged
- @e2e exclude {validation, covered by ViewShareResolver unit tests}
