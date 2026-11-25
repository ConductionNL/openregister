---
kind: code
---

# Proposal: self-filter-inside-retention

## Why

An app that keeps records under the Archiefwet asks its list the questions an
archivist asks: which dossiers are past their archive action date, and which
have no nomination yet. OpenRegister resolves those answers into the object's
`retention` metadata block (`RetentionService::applyArchivalMetadata()` writes
`archiefnominatie`, `archiefactiedatum` and the rest into `_retention`), but a
`@self` filter can only name a whole metadata column. `@self[retention.archiefactiedatum][lt]=…`
was refused as an unknown metadata field, so the list could not ask it and a
dashboard counter could not count it. decidiq's records-management dashboard
waits on exactly this (decidiq `records-management-archiving` task 6: overdue
transfers and unresolved retention).

## What changes

- A `@self` key `retention.<path>` or `tmlo.<path>` names a field inside that
  JSON metadata column. It takes the same operator vocabulary as a filter
  inside an object property (`gt`, `gte`, `lt`, `lte`, `ne`, `in`, `notIn`,
  `isnull`, `like`, `orNull`), written by the same `NestedPathFilter`, on both
  query paths (the QueryBuilder path and the raw UNION path).
- Only the two archival blocks open to a path. A dotted key into any other
  metadata column (authorization, groups, relations, files, validation, geo)
  is still named back as an unknown field: a path into the access columns
  would be an oracle on who may read what.
- A path segment that is not a plain name matches nothing.

## Not in scope

- Absence across schemas ("a meeting without a dossier"): that is an anti-join,
  not a path filter.
- The aggregation endpoint's own filter grammar; a caller counts through the
  list total.
