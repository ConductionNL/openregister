# zoeken-filteren

## ADDED Requirements

### Requirement: A list filter can match a value or no value in one query

The list API SHALL accept the comparison operator `inOrEmpty` on a property,
as `?<property>_inOrEmpty[]=<value>` or `<property>[inOrEmpty][]=<value>`, and
SHALL return the objects whose property equals one of the values or is null,
missing, an empty string or an empty array. Paging, totals and facets SHALL be
computed on the same condition.

#### Scenario: a game master sees one world plus the shared objects

- **GIVEN** a game master in larpinq, and 12 characters with `setting` "Aldoria", 5 with `setting` "Norheim" and 3 with no `setting`
- **WHEN** larpinq lists `GET /api/objects/larpinq/character?setting_inOrEmpty[]=<Aldoria uuid>&_limit=10`
- **THEN** the first page holds 10 characters from Aldoria or with no setting, none from Norheim
- **AND** the total is 15
- @e2e exclude {specified only; task 2.1 adds the Newman case}

#### Scenario: the operator works on a list property

- **GIVEN** items whose `settings` is an array, one with `["Aldoria"]`, one with `[]` and one with `["Norheim"]`
- **WHEN** the list is filtered with `settings_inOrEmpty[]=<Aldoria uuid>`
- **THEN** the first two items are returned
- @e2e exclude {specified only; covered by MagicSearchHandlerInOrEmptyTest in task 1.1}
