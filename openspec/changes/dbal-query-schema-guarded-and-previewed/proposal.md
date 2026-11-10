---
kind: code
depends_on: []
---

# Proposal: dbal-query-schema-guarded-and-previewed

## Summary

A maker in buildiq writes a query over the tables of a connected database and
sees its first rows and columns before saving it as a table of the app.
OpenRegister accepts only one read-only `SELECT` or `WITH` statement, runs it
read-only with a time limit and a row cap, and says why it refuses a statement
that is anything else. The query-backed schema itself already exists; this
change makes it safe to hand to a maker and adds the preview.

## Halves this closes

This is the OpenRegister half of buildiq's merged change
`data-external-database-sources` (buildiq `development` 974af86), rows
`data-external-db` (5 competitors yes, buildiq core area) and `int-sql-query`
(4 competitors yes). It has no row in OpenRegister's matrix; the owner moves
pass of 28 Sep 2026 handed it here. Buildiq writes: "openregister owes the
query table. A `dbal-source` schema whose config names a `query` instead of a
`table`, checked as one read-only `SELECT` or `WITH` statement, run in a
read-only transaction with a statement timeout and the provider's row cap,
values bound as parameters. It also owes a preview route,
`POST /api/sources/{id}/query-preview`, gated like `introspect` ... No open
openregister change covers it: a search of `openspec/changes/*/proposal.md` at
development `ae898b0` for saved, raw or maker SQL queries found none."

Half of that premise did not hold up when read at 555af7212: the query-backed
schema shipped in #2043 (12a52c7fc, 23 Jul 2026) without an OpenSpec change.
`DbalObjectSourceProvider::isQueryBacked()` reads `config.query` and
`fromExpression()` reads from `(<query>) or_src`, with filters, sort, paging,
the 1,000 row cap and read-only writes. What is missing is the statement
check, the read-only transaction and timeout, and the preview route. Its
docblock says the query "MUST be trusted config authored by an administrator",
which is exactly the assumption a maker-authored query breaks.

## What changes

- Saving a `dbal-source` schema with `config.query` checks the statement: one
  statement, starting with `SELECT` or `WITH`, no data-changing or
  session-changing keywords outside string literals, no parameters left
  unbound. A refusal names the reason.
- Every read of a query-backed schema runs in a read-only transaction with a
  statement timeout (default 10 seconds, capped by the instance setting), on
  PostgreSQL and MariaDB.
- `POST /api/sources/{id}/query-preview` with a `query` returns at most 50 rows
  and the columns with their mapped types, gated like `introspect`:
  administrator and organisation-scoped.

## Out of scope

- Writes through a query-backed schema. They stay refused.
- Letting non-administrators author queries directly in OpenRegister. Buildiq
  decides who in the app may; OpenRegister refuses what is not a read.

## Impact

- `lib/Service/ObjectSource/DbalObjectSourceProvider.php` (`isQueryBacked()`
  at `:1080`, `fromExpression()` at `:1099`, the read paths).
- New `lib/Service/Dbal/ReadOnlyQueryGuard.php`.
- `lib/Controller/SourcesController.php` (`queryPreview()` beside `introspect()`
  at `:602`), route beside `sources#introspect` (`appinfo/routes.php:227`).
