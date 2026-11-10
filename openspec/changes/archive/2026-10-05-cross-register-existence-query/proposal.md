---
kind: code
depends_on: []
---

# Proposal: cross-register-existence-query

## Summary

Ask across registers whether a row exists, and get back only that it does,
where, and nothing else. One query, one bounded answer per register, and no
object in it. The caller learns enough to pick up the phone and not enough to
learn anything about the person the row is about.

## Why

**Today the only way to ask is to read.** A caller that wants to know whether
another register holds a row for a person runs a search against that register
and gets rows: the title, the status, the dates, every property the schema
declares. Everything beyond the existence has to be thrown away by the caller
afterwards, in code nobody can audit, and a property added to that schema
tomorrow arrives in the answer without anybody deciding it should.

**That is the wrong shape for the question purpose limitation actually
allows.** dossiq's `the-social-domain-plan-and-its-grounds` (gap row 5.18)
needs exactly this and could not have it: a Wmo consulent may learn that a
household is already known to Jeugdwet, so they coordinate rather than
duplicate, and may not learn anything about that case. The row the register
holds is special-category data under the AVG; the fact that a row exists is
not the same disclosure as the row.

dossiq built its own projection over the registers it already reads, field by
field, and named this slug in its `tasks.md` as the platform capability it
would adopt. It is not a dossiq concern: any two registers in the fleet have
the same question, and every app solving it alone solves it slightly
differently.

**A projection built by the reader cannot be trusted by the writer.** The
register holding the data has no say in what a caller strips out. An existence
query moves that decision to the server, where the schema's own owner can
reason about it, and makes "no content left the register" a property of the
endpoint rather than a promise about somebody else's code.

## What Changes

- **One endpoint**, `POST /api/objects/exists`, taking a list of
  `{register, schema, filters}` probes and answering, per probe, whether a row
  matched and how many, with a caller-chosen list of `reveal` fields that
  SHALL default to empty.
- **The answer is built field by field**, never by filtering a row down. A
  property added to a schema tomorrow appears in nothing unless a caller names
  it in `reveal` and the schema allows it.
- **`reveal` is bounded by the schema, not by the caller.** A field the schema
  marks sensitive is refused by name, so a caller cannot widen the answer into
  the read it was given instead of.
- **Every probe is authorised as a read** of that register and schema by the
  calling identity, so the endpoint can never answer about a register the
  caller could not have searched.
- **The probe is bounded**: at most ten probes per call, and the answer carries
  a count rather than rows.

## Impact

- **Affected specs**: `object-interactions`.
- **Affected code**: one new service, one controller method, one route.
- **Consumers**: dossiq `the-social-domain-plan-and-its-grounds` (row 5.18),
  which adopts it in place of its own projection and keeps the ground and the
  audit log, because those are case administration and not platform.

## Out of scope

- **Who may ask, beyond the read authorisation.** A lawful basis for asking is
  the consuming app's: dossiq requires a ground to be chosen before the lookup
  and writes it to its own audit log. The platform does not invent a second
  vocabulary of grounds.
- **Any 360 view.** This endpoint answers existence. A caller that wants the
  row asks for the row, through the read endpoint that already exists and
  already logs.
