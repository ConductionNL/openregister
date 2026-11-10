# mariadb-ci-matrix

## ADDED Requirements

### Requirement: A text identifier is never bound as an integer

A lookup that accepts an id, a uuid or a slug SHALL compare the integer `id` column only when the identifier is an integer or a string of digits, and SHALL compare the text columns otherwise. PostgreSQL refuses a text value bound as an integer for the whole query, so `id = :value OR uuid = :value` with a uuid fails there while MySQL and SQLite cast it silently.

#### Scenario: a view is found by its uuid on PostgreSQL

- **GIVEN** a view with id 1 and uuid `04d8079a-b84a-410e-b5df-75f04a130068` on a PostgreSQL instance
- **WHEN** a client requests `GET /api/views/04d8079a-b84a-410e-b5df-75f04a130068`
- **THEN** the response is 200 with that view, and `GET /api/views/1` still answers it too
- @e2e exclude {query binding, covered by IdOrUuidBindingTest; the PostgreSQL PHPUnit cell runs it}

#### Scenario: a schema reference by slug resolves on PostgreSQL

- **GIVEN** a schema whose `allOf` names its parent by slug
- **WHEN** the schema is resolved
- **THEN** the parent is loaded by slug without binding the slug as an integer
- @e2e exclude {query binding, covered by IdOrUuidBindingTest}
