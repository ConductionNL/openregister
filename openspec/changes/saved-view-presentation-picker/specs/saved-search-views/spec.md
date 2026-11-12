# saved-search-views

## ADDED Requirements

### Requirement: The view editor sets a view's presentation (REQ-VIEW-PRES-06)

The save form and the edit modal of a saved view on the Tables page SHALL let the user choose the view type `table`, `kanban` or `calendar`, and per type SHALL offer the fields the view save validates: `groupByField` with optional `cardFields` and `columnOrder` for `kanban`, `dateField` with optional `endDateField` for `calendar`. A field picker SHALL offer only properties of the view's schema that can serve its role. The form SHALL send the chosen `presentation` with the view, and SHALL show a validation refusal from the save on the field it names without closing.

#### Scenario: a caseworker saves a board grouped by status

- **GIVEN** a schema `verzoeken` with an enum property `status` and the Tables page filtered to it
- **WHEN** the caseworker opens the save form, picks the view type Board and the group field `status`, and saves
- **THEN** the saved view reads back with `presentation.viewType` `kanban` and `presentation.kanban.groupByField` `status`
- **AND** the Tables page draws the view as a board with one column per status value
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/saved-view-presentation-picker.spec.ts}

#### Scenario: a schema without a date cannot be a calendar

- **GIVEN** a schema with no property of format `date` or `date-time`
- **WHEN** the user opens the save form
- **THEN** the Calendar choice is disabled and says the schema has no date field
- @e2e exclude {specified only; covered by the nextcloud-vue component test of the picker}

#### Scenario: a refused field stays on screen

- **GIVEN** an edit modal for a calendar view whose `dateField` was removed from the schema since
- **WHEN** the user saves
- **THEN** the modal stays open with the save's message under the date field picker
- @e2e exclude {specified only; task 3.1 covers it in the same e2e file}

### Requirement: The Tables page switches the open view's presentation (REQ-VIEW-PRES-07)

The Tables page SHALL show the open view's presentation and SHALL let the user switch among the presentations its schema supports. A switch by a user who may edit the view SHALL save the new presentation on the view. A switch by a user who may not edit it SHALL change only the current page and SHALL offer to save the result as a new view.

#### Scenario: the owner switches a table to a calendar

- **GIVEN** a view owned by the user, presented as a table, on a schema with a date field `ontvangstdatum`
- **WHEN** the user switches it to Calendar and picks `ontvangstdatum`
- **THEN** the page draws the calendar and the view reads back with `presentation.viewType` `calendar`
- @e2e exclude {specified only; task 3.1 covers it}

#### Scenario: a shared view is not changed by a reader

- **GIVEN** a public view the user may read but not edit
- **WHEN** the user switches it to Board
- **THEN** the page draws the board, the view's stored presentation is unchanged, and the page offers "Save as new view"
- @e2e exclude {specified only; task 3.1 covers it}
