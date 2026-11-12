## ADDED Requirements

### Requirement: Aggregation filters accept the list endpoint's empty and filled syntax

An aggregation filter on a field SHALL accept `IS NULL`, `IS NOT NULL` and the
operator `isnull` (a boolean, read as the list endpoint reads it: true, "true",
"1", "on", "yes" ask for empty; anything else asks for filled), with the
meaning the list endpoint gives them: empty is a missing or SQL NULL value.
Both the native SQL path and the PHP fallback SHALL apply them, and the
reader's row-level access rules SHALL apply as for any other filter.

#### Scenario: chip counts on a queue of unassigned work
- GIVEN five cases, two of them without an assignee
- WHEN the grouped count runs with `assignee=IS NULL` (or `assignee[isnull]=true`)
- THEN it counts the two unassigned cases, grouped as asked

#### Scenario: the filled form
- WHEN the count runs with `assignee=IS NOT NULL` (or `assignee[isnull]=false`)
- THEN it counts the three assigned cases
