# dbal-virtual-registers

## ADDED Requirements

### Requirement: A query-backed schema accepts only a read-only statement and reads read-only

A `dbal-source` schema MAY name a `query` instead of a `table`. OpenRegister
SHALL accept it only when it is one statement starting with `SELECT` or `WITH`,
with no data-changing or session-changing keyword outside string literals and
no unbound placeholder, and SHALL refuse it otherwise, naming the rule. Every
read of such a schema SHALL run in a read-only transaction with a statement
timeout and the provider's row cap.

#### Scenario: a maker's join becomes a table

- **GIVEN** an administrator with a DBAL source on the municipality's permit database
- **WHEN** a `dbal-source` schema is saved with `query: "SELECT p.id, p.status, a.naam FROM permit p JOIN applicant a ON a.id = p.applicant_id"`
- **THEN** the schema is saved, and its objects list through `GET /api/objects/{register}/{schema}` with paging
- @e2e exclude {specified only; task 3.2 adds the Newman case}

#### Scenario: a statement that writes is refused

- **GIVEN** the same administrator
- **WHEN** a schema is saved with `query: "WITH x AS (UPDATE permit SET status = 'x' RETURNING *) SELECT * FROM x"`
- **THEN** the save is refused with a message naming `UPDATE`
- @e2e exclude {API contract; covered by ReadOnlyQueryGuardTest in task 1.1}

### Requirement: An administrator can preview a query before saving it

`POST /api/sources/{id}/query-preview` with a `query` SHALL, for an
administrator of the source's organisation, check the statement as a schema
save does and return at most 50 rows and the columns with their mapped types,
read under the same read-only transaction and timeout. It SHALL answer 403 to a
non-administrator and 404 for a source of another organisation.

#### Scenario: a maker sees the first rows

- **GIVEN** the administrator from the first scenario
- **WHEN** buildiq calls `POST /index.php/apps/openregister/api/sources/{id}/query-preview` with the join
- **THEN** the answer lists the columns `id`, `status` and `naam` with their types and at most 50 rows
- @e2e exclude {specified only; covered by SourcesControllerTest in task 2.1}
