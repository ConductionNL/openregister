# zoeken-filteren (delta)

## ADDED Requirements

### Requirement: The records table sorts by a column and shows the columns the user picks (REQ-RAT-002)

The records page SHALL show the objects of the selected register and schema in a table with one column per chosen property and the metadata columns. Clicking a column header SHALL sort the list on that column server-side, a second click SHALL reverse the order, and the sort SHALL survive paging. The sidebar SHALL let the user choose which properties and metadata fields are columns, and the choice SHALL be kept for that schema when the user comes back.

#### Scenario: A user sorts on a column
- **GIVEN** the records page for schema `melding` with more than one page of objects
- **WHEN** the user clicks the `aangemaakt` header twice
- **THEN** the list is sorted on `aangemaakt` descending
- **AND** the second page continues in the same order
- @e2e exclude {task 2.2 adds tests/e2e/ci/records-table.spec.ts}

#### Scenario: A user picks the columns
- **GIVEN** the records page for schema `melding`
- **WHEN** the user turns on `prioriteit` and turns off `omschrijving` in the sidebar
- **THEN** the table shows a `prioriteit` column and no `omschrijving` column
- **AND** the choice is still there after reloading the page
- @e2e exclude {task 2.2}
