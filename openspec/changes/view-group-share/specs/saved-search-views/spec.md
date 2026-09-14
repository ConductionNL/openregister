# saved-search-views

## ADDED Requirements

### Requirement: A view can be shared with groups in read or write mode

A View SHALL carry `sharedWith`, a list of `{group, mode}` with `mode`
`read` or `write`, editable by the owner or an administrator. Listing views
SHALL return the caller's own views, public views and views shared with a
group the caller belongs to, each with `@self.access` of `owner`, `write`
or `read`. Sharing with a group that does not exist SHALL be refused.

#### Scenario: a department sees its view with its columns

- **GIVEN** a view owned by A with `presentation.columns` set and shared `read` with group `handhaving`
- **WHEN** a member of `handhaving` lists views
- **THEN** the view is returned with `@self.access` `read` and its columns
- @e2e exclude {proposal only; the nextcloud-vue change saved-views-shared-by-role adds the e2e when the control ships}

#### Scenario: a non-member does not see it

- **GIVEN** the same view and a user in no shared group
- **WHEN** the user lists views
- **THEN** the view is absent
- @e2e exclude {list query, covered by ViewMapper unit tests}

### Requirement: Write on a share changes the query, never the audience

A member with `write` SHALL be able to update the view's `query`,
`presentation` and `alert`, and SHALL NOT be able to change `sharedWith`,
`owner` or delete the view.

#### Scenario: a writer cannot widen the share

- **GIVEN** a member with `write`
- **WHEN** the member sends `sharedWith` with a second group
- **THEN** the response is 403 and `sharedWith` is unchanged
- @e2e exclude {guard, covered by controller unit tests}
