---
kind: code
depends_on: []
---

# Proposal: object-quota-per-organisation

## Summary

A schema can cap how many of its objects one organisation holds:
`x-openregister-quota: {"perOrganisation": N}`. A create past the cap is
refused with the count and the cap; an update never counts. An app reads
the organisation's count, cap and whether it is at the cap from
`ObjectQuotaService::status()`.

## Why

Gate 23 (DECISIONS row 62, Ruben chose "Build the gaps in OpenRegister").
hermiq caps schedules per organisation and today only reports it: its
`TenantOpsService` says the create-time refusal "is an OpenRegister seam",
because object creation goes through OpenRegister's object API, not
through hermiq. OpenRegister's `Organisation` carries storage, bandwidth
and request quotas, but nothing that caps the number of objects of a
schema. Gap note: `for-ruben/hermiq-gate23-gap.md`, item 3 (build-all
workspace).

## What changes

- `x-openregister-quota` joins the schema annotation vocabulary, so it
  survives a schema save.
- `ObjectQuotaService`: `limitFor(schema)` (a positive integer, anything
  else is no quota), `count(register, schema, organisation)` (the
  organisation's real total, counted with RBAC and the organisation filter
  off), `status()` (`count`, `limit`, `atLimit`).
- `ObjectQuotaListener` on `ObjectCreatingEvent`: refuses a create when the
  organisation already holds the cap, with code `object-quota-exceeded`.

## Out of scope, and why

- Where else the cap could live (a per-organisation override, an app's own
  config key) and a quota on distinct values (hermiq's "agents in use"):
  questions Q1 and Q2 in `for-ruben/openregister-gate23-gap-questions.md`.
- Bulk creates through `saveObjects`: they do not dispatch
  `ObjectCreatingEvent` per object, so the cap is not enforced there yet.
  Recorded as task 4.1, open.
