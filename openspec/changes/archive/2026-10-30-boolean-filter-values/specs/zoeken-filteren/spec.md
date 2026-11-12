# zoeken-filteren

## ADDED Requirements

### Requirement: A boolean filter value filters like its string form

A property filter whose value is a PHP boolean SHALL return the same objects as
the same filter with the value written as a string (`false` as `'false'`, `true`
as `'true'`), on the list, on the count and on the facets, on PostgreSQL, MySQL and
MariaDB. This SHALL hold for equality, `in`, `notIn` and `ne`.

A null or missing property value SHALL match neither `true` nor `false`. A caller
that means "false or not set" SHALL ask for that explicitly; the filter SHALL NOT
widen to it on its own.

#### Scenario: a PHP false filter returns the rows that hold false

- **GIVEN** a schema with a boolean property `isDraft`, two objects with `isDraft` false, one with `isDraft` true and one without `isDraft`
- **WHEN** a PHP caller searches with `['isDraft' => false]`
- **THEN** the two objects with `isDraft` false are returned
- **AND** the same search with `['isDraft' => 'false']` returns the same two objects
- @e2e exclude {a PHP-caller value type; no browser or HTTP request can send a PHP boolean. Covered by tests/Db/BooleanFilterIntegrationTest.php against a real database}

#### Scenario: a PHP true filter returns the rows that hold true

- **GIVEN** the same objects
- **WHEN** a PHP caller searches with `['isDraft' => true]`
- **THEN** only the object with `isDraft` true is returned
- @e2e exclude {a PHP-caller value type; covered by tests/Db/BooleanFilterIntegrationTest.php}

#### Scenario: an unset value is not false

- **GIVEN** the same objects
- **WHEN** a caller searches with `isDraft` false, as a boolean or as a string
- **THEN** the object without `isDraft` is not returned
- @e2e exclude {covered by tests/Db/BooleanFilterIntegrationTest.php; the behaviour is the existing one for the string form}

#### Scenario: a boolean in an operator bag

- **GIVEN** the same objects
- **WHEN** a PHP caller searches with `['isDraft' => ['ne' => true]]` or `['isDraft' => ['in' => [false]]]`
- **THEN** the two objects with `isDraft` false are returned
- @e2e exclude {a PHP-caller value type; covered by tests/Unit/Db/MagicSearchHandlerBooleanFilterValueTest.php and tests/Db/BooleanFilterIntegrationTest.php}
