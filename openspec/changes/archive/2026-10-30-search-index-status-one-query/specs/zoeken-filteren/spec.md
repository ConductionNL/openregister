# zoeken-filteren Delta: search-index-status-one-query

## ADDED Requirements

### Requirement: The search index status reads index names in bulk

The search index status SHALL read the index names of all tables in scope in one query per 500 tables, and SHALL answer every table in scope, with an empty list for a table without indexes.

#### Scenario: Three tables
- WHEN the status is read for three tables
- THEN one pg_indexes query runs and each table lists its index names
