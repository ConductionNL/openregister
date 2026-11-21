---
kind: code
---

# Proposal: audit-trail-purge-by-age

## Summary

An administrator sets how long each kind of audit trail entry is kept (create, read,
update, delete), and the hourly log cleanup job removes entries past that age. The
settings and the job exist, but no requirement says that audit trail entries are
removed, so apps that rely on it (opencatalogi's history purge) have nothing to cite.

## Rows this closes

| matrix | row | capability |
|---|---|---|
| opencatalogi | ops-history-purge | Remove change history older than a chosen age |

## Why

`retention-management` covers the retention settings API, the rebase of deletion times
and the search trail sweep. `audit-trail-immutable` says entries are kept for at least
the register's configured retention. Between the two, nothing says that the cleanup job
deletes audit trail entries once their configured age has passed, or that it may never
go below the register's minimum.

## What changes

- ADDED requirement in `retention-management`: the hourly log cleanup job deletes audit
  trail entries past the configured retention, never before the register's minimum.

## Impact

Specs only, plus a test that pins the existing behaviour and the floor.
