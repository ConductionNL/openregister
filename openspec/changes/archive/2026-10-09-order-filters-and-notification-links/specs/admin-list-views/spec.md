# admin-list-views

## ADDED Requirements

### Requirement: OpenRegister's schemas and registers lists MUST filter from their column headers

The schemas and registers index pages SHALL offer a filter in every column
header that can filter, and applying one SHALL narrow the list. A text column
SHALL match on contains, case-insensitive (`{key}[like]`). A date column SHALL
match a from and to range (`{key}[gte]`, `{key}[lte]`). Clearing a filter SHALL
show the full list again. A change SHALL return the list to page 1. Both lists
SHALL sort from their sortable headers.

#### Scenario: a title filter narrows the schemas list

- **GIVEN** the schemas list holds "Case Type", "Client" and "Lead Product"
- **WHEN** a person types "case" into the title header filter and applies it
- **THEN** only "Case Type" is listed
- **AND** clearing the filter lists all three again
- @e2e exclude {client-side list filter; covered by src/services/listFilter.spec.js and a live check on :8099}

#### Scenario: the registers list sorts by title

- **GIVEN** the registers list holds "pipelinq", "dossiq" and "Tasks"
- **WHEN** a person clicks the Title header
- **THEN** the list reads dossiq, pipelinq, Tasks
- @e2e exclude {client-side list sort; covered by src/services/listSort.spec.js and a live check on :8099}
