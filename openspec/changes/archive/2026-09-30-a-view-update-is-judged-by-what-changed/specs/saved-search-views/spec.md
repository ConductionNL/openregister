# saved-search-views

## ADDED Requirements

### Requirement: Updating a view is refused on the fields the caller may not change

`ViewsController::update()` SHALL refuse an update that changes a field the
caller does not own, before it saves anything. The refusal SHALL be a 403
naming each refused field.

The caller's access SHALL be resolved through `ViewShareResolver`: an owner and
an administrator may change everything; a `write` member may change only
`query`, `presentation` and `alert`; a `read` member and a stranger may change
nothing.

A view that cannot be read SHALL deny rather than fall through.

#### Scenario: a write member cannot rename someone else's view

- **GIVEN** a view owned by another user, shared to a group the caller is in with mode `write`
- **WHEN** the caller saves it with a different `name`
- **THEN** the save is refused with 403 naming `name`, and the stored view is unchanged
- @e2e exclude {specs only in this change; task 4.2 adds tests/e2e/api-direct/view-update-field-access.spec.ts when the wiring ships}

#### Scenario: a write member cannot change who sees the view

- **GIVEN** the same view and caller
- **WHEN** the caller saves it with `isPublic` true, a different `owner`, or a changed `sharedWith`
- **THEN** each is refused with 403 naming that field, and none of them is stored
- @e2e exclude {as above, probing with the least privileged principal that should be refused}

#### Scenario: a read member changes nothing

- **GIVEN** a view shared to the caller's group with mode `read`
- **WHEN** the caller saves any change at all
- **THEN** the save is refused with 403
- @e2e exclude {as above}

#### Scenario: the owner changes everything

- **GIVEN** a view the caller owns
- **WHEN** they change `name`, `isPublic` and `sharedWith` in one save
- **THEN** the save succeeds
- @e2e exclude {unit-tested on the guard; the owner path has no refusal to probe}

### Requirement: Only fields whose value actually changed are judged

The endpoint SHALL compare the submitted body against the stored view and SHALL
judge only the fields whose value differs. A field sent with the value it
already holds SHALL NOT be refused.

The comparison SHALL be by value and SHALL NOT depend on key order or on list
order, so an equal `query` object or an equal `sharedWith` list is not a
change.

Keys the body carries that name no view property SHALL be ignored, so
pagination and routing keys cannot refuse an update the caller is entitled to
make.

#### Scenario: the edit modal's full body does not refuse an ordinary edit

- **GIVEN** a view shared with the caller in mode `write`, and a body carrying `name`, `description`, `isPublic`, `isDefault` and `query` exactly as `EditView.vue` sends them
- **WHEN** only `query` differs from the stored view
- **THEN** the save succeeds and the four unchanged fields are not refused
- @e2e exclude {specs only in this change; task 4.2 exercises the real modal body}

#### Scenario: one changed forbidden field among four unchanged ones is still refused

- **GIVEN** the same caller and body
- **WHEN** `query` differs and `name` also differs
- **THEN** the save is refused with 403 naming `name` only
- @e2e exclude {as above}

#### Scenario: a reordered share list is not a change

- **GIVEN** a view whose `sharedWith` holds two shares, and a `write` member
- **WHEN** they save the same two shares in the opposite order
- **THEN** the save is not refused on `sharedWith`
- @e2e exclude {value comparison is a unit test on the diff helper}

#### Scenario: a pagination key on the body refuses nothing

- **GIVEN** a `write` member saving an unchanged view with `_limit` on the body
- **WHEN** the endpoint judges the change
- **THEN** nothing is refused
- @e2e exclude {as above}
